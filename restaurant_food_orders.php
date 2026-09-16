<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$user = current_user();
$table_id = (int)($_GET['table_id'] ?? 0);
$order_id = (int)($_GET['order_id'] ?? 0);

if ($table_id <= 0 && $order_id <= 0) {
    flash_set('danger', 'No table selected.');
    header('Location: restaurant_front_desk.php');
    exit;
}

$existing_order = null;
if ($order_id > 0) {
    $existing_order = get_order_full($conn, $order_id);
    if (!$existing_order) {
        flash_set('danger', 'Order not found.');
        header('Location: restaurant_front_desk.php');
        exit;
    }
    $table_id = (int)$existing_order['table_id'];
}

$table = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM restaurant_tables WHERE id = {$table_id} LIMIT 1"));
if (!$table) {
    flash_set('danger', 'Table not found.');
    header('Location: restaurant_front_desk.php');
    exit;
}

if (!$existing_order) {
    $live = get_table_live_status($conn, $table_id);
    if ($live['order']) {
        $existing_order = get_order_full($conn, $live['order']['id']);
        $order_id = (int)$existing_order['id'];
    }
}

$errors = [];
$order_types = ['dine_in' => 'Dine In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['hold_order', 'save_order'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $action = $_POST['action'];
        $cart_json = $_POST['cart_json'] ?? '[]';
        $cart = json_decode($cart_json, true);
        $tax_percent = (float)($_POST['tax_percent'] ?? 0);
        $discount = (float)($_POST['discount'] ?? 0);
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $order_type = $_POST['order_type'] ?? 'dine_in';
        $valid_methods = ['cash','card','mobile_banking','bank','other'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';
        if (!array_key_exists($order_type, $order_types)) $order_type = 'dine_in';
        $existing_order_id = (int)($_POST['order_id'] ?? 0);

        if (empty($cart) || !is_array($cart)) {
            $errors[] = 'Please add at least one item to the order.';
        }

        $subtotal = 0;
        $validated_cart = [];
        if (empty($errors)) {
            foreach ($cart as $c) {
                $food_item_id = (int)($c['id'] ?? 0);
                $qty = max(1, (int)($c['qty'] ?? 1));
                $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM restaurant_food_items WHERE id = {$food_item_id} LIMIT 1"));
                if (!$item) continue;
                $price = (float)$item['price'];
                $total = $qty * $price;
                $subtotal += $total;
                $validated_cart[] = [
                    'food_item_id' => $food_item_id,
                    'item_name' => $item['item_name'],
                    'size' => $item['size'],
                    'qty' => $qty,
                    'price' => $price,
                    'total' => $total,
                ];
            }
            if (empty($validated_cart)) {
                $errors[] = 'No valid items found in the order.';
            }
        }

        $tax_amount = round(max(0, $subtotal - $discount) * ($tax_percent / 100), 2);
        $grand_total = max(0, $subtotal - $discount + $tax_amount);

        if ($discount < 0 || $discount > $subtotal) {
            $errors[] = 'Discount cannot be negative or exceed the subtotal.';
        }
        if ($payment_amount < 0 || $payment_amount > $grand_total) {
            $errors[] = 'Payment amount cannot be negative or exceed the grand total.';
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;
            $status = $action === 'hold_order' ? 'hold' : 'ordered';

            if ($existing_order_id > 0) {
                $order_id_to_use = $existing_order_id;

                $stmt = mysqli_prepare($conn, "DELETE FROM restaurant_food_order_items WHERE order_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $order_id_to_use);
                $ok = mysqli_stmt_execute($stmt) && $ok;

                $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_orders SET tax = ?, discount = ?, status = ?, order_type = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'ddssi', $tax_amount, $discount, $status, $order_type, $order_id_to_use);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            } else {
                $order_no = generate_order_no($conn);
                $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_food_orders (order_no, table_id, order_type, tax, discount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'sisddsi', $order_no, $table_id, $order_type, $tax_amount, $discount, $status, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
                $order_id_to_use = $ok ? mysqli_insert_id($conn) : 0;
            }

            if ($ok) {
                $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_food_order_items (order_id, food_item_id, item_name, size, qty, price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($validated_cart as $it) {
                    mysqli_stmt_bind_param($stmt, 'iissidd', $order_id_to_use, $it['food_item_id'], $it['item_name'], $it['size'], $it['qty'], $it['price'], $it['total']);
                    $ok = mysqli_stmt_execute($stmt) && $ok;
                }
            }

            if ($ok && $payment_amount > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_payments (order_id, payment_type, payment_method, amount, created_by) VALUES (?, 'initial_payment', ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'isdi', $order_id_to_use, $payment_method, $payment_amount, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $ok = recalc_order_total($conn, $order_id_to_use);
            }

            if ($ok) {
                mysqli_commit($conn);
                flash_set('success', $status === 'hold' ? 'Order held successfully.' : 'Order saved successfully.');
                header('Location: restaurant_receipt.php?order_id=' . $order_id_to_use);
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Failed to save order: ' . mysqli_error($conn);
            }
        }
    }
}

$categories_result = mysqli_query($conn, "SELECT id, type FROM restaurant_food_categories WHERE is_active = 1 ORDER BY type ASC");
$categories_arr = [];
while ($c = mysqli_fetch_assoc($categories_result)) $categories_arr[] = $c;

// Load all active menu items up front (mysqli, no AJAX) — filtering by category/search happens in JS.
$menu_items_result = mysqli_query($conn, "SELECT id, item_name, price, size, image_path, food_category FROM restaurant_food_items WHERE is_active = 1 ORDER BY item_name ASC");
$menu_items_arr = [];
while ($mi = mysqli_fetch_assoc($menu_items_result)) $menu_items_arr[] = $mi;

$existing_items = [];
if ($existing_order) {
    $existing_items = get_order_items($conn, $existing_order['id']);
}

$page_title = 'New Order';
$active_menu = 'restaurant_front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    body { background: #f4f6f5; overflow-x: hidden; }
    .pos-wrap, .pos-wrap * { box-sizing: border-box; }

    /* Overall POS layout: menu (flexible) + cart (fixed-ish width), both height-bound to the viewport
       so neither panel nor the page itself grows unbounded no matter how many items/categories exist. */
    .pos-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(300px, 380px);
        gap: 16px;
        align-items: start;
        max-width: 100%;
        height: calc(100vh - 100px);
        height: calc(100dvh - 100px);
        min-height: 420px;
    }
    @media (max-width: 991px) {
        .pos-wrap { grid-template-columns: 1fr; height: auto; }
    }

    .menu-panel {
        background: #fff; border-radius: 14px; border: 1px solid #e8ebe9; padding: 18px;
        min-width: 0;
        height: 100%;
        min-height: 0;
        display: flex; flex-direction: column;
        overflow: hidden; /* only the food-grid inside scrolls, never this panel itself */
    }
    @media (max-width: 991px) {
        /* On stacked/mobile layouts give the menu its own comfortable, viewport-relative height
           instead of a hardcoded pixel value, so it adapts to any screen size. */
        .menu-panel { height: 65vh; height: 65dvh; min-height: 320px; }
    }

    .search-box { position: relative; margin-bottom: 14px; flex-shrink: 0; }
    .search-box input {
        width: 100%; padding: 11px 16px 11px 40px; border-radius: 10px; border: 1px solid #d8ddd9;
        font-size: 0.9rem; background: #fafbfa;
    }
    .search-box input:focus { outline: none; border-color: #0f5132; background: #fff; }
    .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #8a938e; }

    .category-pills { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 16px; flex-shrink: 0; }
    .category-pill {
        border: 1px solid #d8ddd9; border-radius: 10px; padding: 8px 18px;
        font-size: 0.85rem; font-weight: 600; white-space: nowrap; cursor: pointer; color: #495a52; background: #f4f6f5;
        flex-shrink: 0;
    }
    .category-pill.active { background: #0f5132; color: #fff; border-color: #0f5132; }

    /* Item selection area: this is the ONLY element that scrolls to reveal more items.
       flex:1 lets it fill whatever room remains under the search box + category pills,
       min-height:0 is required so it can actually shrink and become scrollable inside a flex column. */
    .food-grid-wrap { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; padding-right: 4px; }
    .food-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 14px;
        align-content: start; /* cards pack from the top instead of stretching to fill leftover space */
    }
    @media (max-width: 480px) {
        .food-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
    }

    .food-card { background: #fff; border: 1px solid #eef0ef; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
    .food-card .food-img { width: 100%; height: 100px; object-fit: cover; background: #f0f2f1; display: block; }
    .food-card .food-img-placeholder {
        width: 100%; height: 100px; background: #f0f2f1; display: flex; align-items: center; justify-content: center; color: #b8c0bc; font-size: 1.6rem;
    }
    .food-card .food-body { padding: 10px 12px 12px; display: flex; flex-direction: column; flex: 1 1 auto; }
    .food-card .food-name { font-weight: 600; font-size: 0.87rem; color: #1c3d2e; line-height: 1.25; min-height: 20px; }
    .food-card .food-meta-row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; margin: 4px 0 8px; }
    .food-card .food-price { color: #0f5132; font-weight: 700; font-size: 0.85rem; }
    .food-card .food-size { color: #8a938e; font-size: 0.75rem; font-weight: 500; white-space: nowrap; flex-shrink: 0; }
    .food-card .add-btn {
        width: 100%; background: #0f5132; color: #fff; border: none; border-radius: 8px;
        padding: 7px; font-size: 0.82rem; font-weight: 600; margin-top: auto;
    }
    .food-card .add-btn:hover { background: #0c4128; }

    .cart-panel {
        background: #fff; border-radius: 14px; border: 1px solid #e8ebe9; padding: 18px;
        position: sticky; top: 12px;
        min-width: 0; max-width: 100%;
        height: 100%;
        min-height: 0;
        display: flex; flex-direction: column;
        overflow: hidden;
    }
    @media (max-width: 991px) { .cart-panel { position: static; height: auto; } }
    .cart-panel form { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; }
    .cart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; flex-shrink: 0; }
    .cart-header h5 { margin: 0; font-weight: 800; color: #1c3d2e; font-size: 1.15rem; }
    .table-chip { display: flex; align-items: center; gap: 5px; color: #495a52; font-size: 0.85rem; font-weight: 600; }

    .cart-items { flex: 1 1 auto; overflow-y: auto; overflow-x: hidden; margin-top: 10px; min-height: 60px; }
    .cart-item-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f2f1; }
    .cart-item-row .cart-thumb { width: 46px; height: 46px; border-radius: 8px; object-fit: cover; background: #f0f2f1; flex-shrink: 0; }
    .cart-item-row .cart-thumb-placeholder { width: 46px; height: 46px; border-radius: 8px; background: #f0f2f1; display:flex; align-items:center; justify-content:center; color:#b8c0bc; flex-shrink: 0; }
    .cart-item-row .cart-info { flex: 1 1 auto; min-width: 0; }
    .cart-item-row .cart-name { font-weight: 600; font-size: 0.87rem; color: #1c3d2e; line-height: 1.25; overflow-wrap: anywhere; }
    .cart-item-row .cart-meta-row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; margin-top: 2px; }
    .cart-item-row .cart-size { font-weight: 500; color: #8a938e; font-size: 0.78rem; white-space: nowrap; flex-shrink: 0; }
    .cart-item-row .cart-price { color: #0f5132; font-size: 0.8rem; }
    .cart-qty { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .cart-qty button { width: 24px; height: 24px; border-radius: 6px; border: 1px solid #d8ddd9; background: #fff; font-weight: 700; font-size: 0.8rem; line-height: 1; flex-shrink: 0; }
    .cart-qty .qty-val { min-width: 16px; text-align: center; font-weight: 600; font-size: 0.85rem; }
    .cart-total-col { text-align: right; min-width: 45px; font-weight: 700; font-size: 0.85rem; color: #1c3d2e; flex-shrink: 0; }
    .cart-delete { color: #b8c0bc; background: none; border: none; padding: 0 0 0 6px; flex-shrink: 0; }
    .cart-delete:hover { color: #dc3545; }

    @media (max-width: 420px) {
        .cart-item-row { gap: 6px; flex-wrap: wrap; }
        .cart-item-row .cart-thumb, .cart-item-row .cart-thumb-placeholder { width: 38px; height: 38px; }
        .cart-total-col { min-width: 38px; }
    }

    .summary-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 14px; flex-shrink: 0; }
    .summary-box .line { display: flex; justify-content: space-between; font-size: 0.87rem; padding: 4px 0; color: #495a52; }
    .summary-box .grand-line { display: flex; justify-content: space-between; font-size: 1.05rem; font-weight: 800; color: #1c3d2e; padding-top: 8px; margin-top: 4px; border-top: 1px solid #dde1de; }

    .btn-pay { width: 100%; background: #0f5132; color: #fff; border: none; border-radius: 10px; padding: 13px; font-weight: 700; font-size: 0.95rem; margin-top: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; flex-shrink: 0; }
    .btn-pay:hover { background: #0c4128; color: #fff; }
    .btn-pay:disabled { background: #a8b5ae; cursor: not-allowed; }
    .btn-hold-outline { width: 100%; background: #fff; color: #0f5132; border: 1px solid #0f5132; border-radius: 10px; padding: 11px; font-weight: 700; font-size: 0.9rem; margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; flex-shrink: 0; }
    .btn-hold-outline:hover { background: #f4f6f5; color: #0f5132; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($existing_order): ?>
<div class="mb-3 d-flex justify-content-end">
    <span class="badge <?= order_status_badge($existing_order['status']) ?>">Order #<?= e($existing_order['order_no']) ?> — <?= e(ucfirst($existing_order['status'])) ?></span>
</div>
<?php endif; ?>

<div class="pos-wrap">
    <div class="menu-panel">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search food or drink..." oninput="onSearchInput()">
        </div>

        <div class="category-pills" id="categoryPills">
            <div class="category-pill active" data-category="0" onclick="selectCategory(0, this)">All</div>
            <?php foreach ($categories_arr as $c): ?>
                <div class="category-pill" data-category="<?= (int)$c['id'] ?>" onclick="selectCategory(<?= (int)$c['id'] ?>, this)"><?= e($c['type']) ?></div>
            <?php endforeach; ?>
        </div>

        <div class="food-grid-wrap">
            <div class="food-grid" id="foodGrid">
                <div class="text-muted text-center py-4" style="grid-column: 1/-1;">Loading menu...</div>
            </div>
        </div>
    </div>

    <div class="cart-panel">
        <form method="POST" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="save_order">
            <input type="hidden" name="order_id" value="<?= $existing_order ? (int)$existing_order['id'] : 0 ?>">
            <input type="hidden" name="cart_json" id="cartJson" value="[]">

            <div class="cart-header">
                <h5>Current Order</h5>
                <span class="table-chip"><i class="bi bi-people"></i> <?= e($table['table_no']) ?></span>
            </div>
            <div class="text-muted small mb-2">
                <?= e($table['table_type']) ?> · <?= e($table['floor'] ?: '—') ?>
                <?php if ($existing_order): ?> · <?= e($existing_order['order_no']) ?><?php endif; ?>
            </div>

            <select name="order_type" id="order_type" class="form-select form-select-sm mb-2">
                <?php foreach ($order_types as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($existing_order && $existing_order['order_type'] === $val) ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>

            <div class="cart-items" id="cartItems">
                <div class="text-center text-muted py-4" id="cartEmptyMsg">No items added yet.</div>
            </div>

            <div class="row g-2 mt-1">
                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Tax %</label>
                    <input type="number" step="0.1" min="0" name="tax_percent" id="tax_percent" class="form-control form-control-sm" value="5" oninput="recalcCart()">
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Discount (৳)</label>
                    <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control form-control-sm" value="0" oninput="recalcCart()">
                </div>
            </div>

            <div class="summary-box">
                <div class="line"><span>Subtotal</span><span id="calc_subtotal">৳0</span></div>
                <div class="line"><span>Discount</span><span id="calc_discount">৳0</span></div>
                <div class="line"><span>Tax (<span id="calc_tax_pct">5</span>%)</span><span id="calc_tax">৳0</span></div>
                <div class="grand-line"><span>Total</span><span id="calc_grand">৳0</span></div>
            </div>

            <div class="row g-2 mt-2">
                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Payment Method</label>
                    <select name="payment_method" class="form-select form-select-sm">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="mobile_banking">Mobile Banking</option>
                        <option value="bank">Bank</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Payment Amount</label>
                    <input type="number" step="0.01" min="0" name="payment_amount" id="payment_amount" class="form-control form-control-sm" value="0" oninput="recalcCart()">
                </div>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-2">
                <span>Due</span><span id="calc_due" class="fw-semibold">৳0.00</span>
            </div>

            <button type="submit" class="btn-pay" id="saveBtn" onclick="setAction('save_order')" disabled>
                <i class="bi bi-credit-card"></i> Pay & Make Order
            </button>
            <button type="submit" class="btn-hold-outline" id="holdBtn" onclick="setAction('hold_order')" disabled>
                <i class="bi bi-pause-circle"></i> Hold Order
            </button>
            <?php if ($existing_order): ?>
            <a href="restaurant_receipt.php?order_id=<?= (int)$existing_order['id'] ?>" class="btn-hold-outline mt-2" style="text-decoration:none;">
                <i class="bi bi-printer"></i> Print Receipt
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
let cart = [];
<?php if (!empty($existing_items)): ?>
cart = <?= json_encode(array_map(function($it) use ($conn) {
    $img = mysqli_fetch_assoc(mysqli_query($conn, "SELECT image_path FROM restaurant_food_items WHERE id = " . (int)$it['food_item_id']));
    return [
        'id' => (int)$it['food_item_id'],
        'name' => $it['item_name'],
        'size' => $it['size'],
        'price' => (float)$it['price'],
        'qty' => (int)$it['qty'],
        'image' => $img ? $img['image_path'] : null,
    ];
}, $existing_items)) ?>;
<?php endif; ?>

// All active menu items, loaded once via PHP/mysqli on page load (no AJAX).
const allMenuItems = <?= json_encode(array_map(function($mi) {
    return [
        'id' => (int)$mi['id'],
        'item_name' => $mi['item_name'],
        'price' => (float)$mi['price'],
        'size' => $mi['size'],
        'image_path' => $mi['image_path'],
        'food_category' => (int)$mi['food_category'],
    ];
}, $menu_items_arr)) ?>;

let currentCategory = 0;
let currentSearch = '';

function setAction(action) {
    document.getElementById('formAction').value = action;
}

function selectCategory(categoryId, el) {
    currentCategory = categoryId;
    document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    renderMenuItems();
}

function onSearchInput() {
    currentSearch = document.getElementById('searchInput').value.trim().toLowerCase();
    renderMenuItems();
}

function renderMenuItems() {
    const grid = document.getElementById('foodGrid');

    const items = allMenuItems.filter(item => {
        if (currentCategory > 0 && item.food_category !== currentCategory) return false;
        if (currentSearch && !item.item_name.toLowerCase().includes(currentSearch)) return false;
        return true;
    });

    if (items.length === 0) {
        grid.innerHTML = '<div class="text-muted text-center py-4" style="grid-column: 1/-1;">No items found.</div>';
        return;
    }

    grid.innerHTML = '';
    items.forEach(item => {
        const card = document.createElement('div');
        card.className = 'food-card';
        const imgHtml = item.image_path
            ? `<img src="${item.image_path}" class="food-img" alt="" onerror="this.outerHTML='<div class=&quot;food-img-placeholder&quot;><i class=&quot;bi bi-egg-fried&quot;></i></div>'">`
            : `<div class="food-img-placeholder"><i class="bi bi-egg-fried"></i></div>`;
        card.innerHTML = `
            ${imgHtml}
            <div class="food-body">
                <div class="food-name">${item.item_name}</div>
                <div class="food-meta-row">
                    <span class="food-price">৳${parseFloat(item.price).toFixed(0)}</span>
                    ${item.size ? `<span class="food-size">${item.size}</span>` : ''}
                </div>
                <button type="button" class="add-btn" onclick='addToCart(${item.id}, ${JSON.stringify(item.item_name)}, ${JSON.stringify(item.size||"")}, ${item.price}, ${JSON.stringify(item.image_path||"")})'>Add</button>
            </div>
        `;
        grid.appendChild(card);
    });
}

function addToCart(id, name, size, price, image) {
    const existing = cart.find(c => c.id === id && c.size === size);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({ id, name, size, price, qty: 1, image });
    }
    renderCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function cartChangeQty(index, delta) {
    cart[index].qty = Math.max(1, cart[index].qty + delta);
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    if (cart.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4" id="cartEmptyMsg">No items added yet.</div>';
    } else {
        container.innerHTML = '';
        cart.forEach((c, idx) => {
            const total = c.qty * c.price;
            const row = document.createElement('div');
            row.className = 'cart-item-row';
            const thumbHtml = c.image
                ? `<img src="${c.image}" class="cart-thumb" alt="" onerror="this.outerHTML='<div class=&quot;cart-thumb-placeholder&quot;><i class=&quot;bi bi-egg-fried&quot;></i></div>'">`
                : `<div class="cart-thumb-placeholder"><i class="bi bi-egg-fried"></i></div>`;
            row.innerHTML = `
                ${thumbHtml}
                <div class="cart-info">
                    <div class="cart-name">${c.name}</div>
                    <div class="cart-meta-row">
                        <span class="cart-price">৳${c.price.toFixed(0)}</span>
                        ${c.size ? `<span class="cart-size">${c.size}</span>` : ''}
                    </div>
                </div>
                <div class="cart-qty">
                    <button type="button" onclick="cartChangeQty(${idx}, -1)">−</button>
                    <span class="qty-val">${c.qty}</span>
                    <button type="button" onclick="cartChangeQty(${idx}, 1)">+</button>
                </div>
                <div class="cart-total-col">৳${total.toFixed(0)}</div>
                <button type="button" class="cart-delete" onclick="removeFromCart(${idx})"><i class="bi bi-trash"></i></button>
            `;
            container.appendChild(row);
        });
    }
    recalcCart();
}

function recalcCart() {
    const subtotal = cart.reduce((sum, c) => sum + c.qty * c.price, 0);
    const discount = Math.min(parseFloat(document.getElementById('discount').value) || 0, subtotal);
    const taxPercent = parseFloat(document.getElementById('tax_percent').value) || 0;
    const taxAmount = Math.max(0, subtotal - discount) * (taxPercent / 100);
    const grandTotal = Math.max(0, subtotal - discount + taxAmount);
    const payment = parseFloat(document.getElementById('payment_amount').value) || 0;
    const due = Math.max(0, grandTotal - payment);

    document.getElementById('calc_subtotal').innerText = '৳' + subtotal.toFixed(0);
    document.getElementById('calc_discount').innerText = '৳' + discount.toFixed(0);
    document.getElementById('calc_tax_pct').innerText = taxPercent;
    document.getElementById('calc_tax').innerText = '৳' + taxAmount.toFixed(0);
    document.getElementById('calc_grand').innerText = '৳' + grandTotal.toFixed(0);
    document.getElementById('calc_due').innerText = '৳' + due.toFixed(2);

    document.getElementById('payment_amount').max = grandTotal;
    document.getElementById('discount').max = subtotal;

    document.getElementById('cartJson').value = JSON.stringify(cart);

    const hasItems = cart.length > 0;
    const paymentValid = payment <= grandTotal + 0.01 && discount <= subtotal + 0.01;
    document.getElementById('holdBtn').disabled = !(hasItems && paymentValid);
    document.getElementById('saveBtn').disabled = !(hasItems && paymentValid);
}

renderMenuItems();
renderCart();
</script>

<?php require __DIR__ . '/footer.php'; ?>