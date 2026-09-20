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

// All active tables, for the Table Select dropdown in the right panel.
$all_tables_result = mysqli_query($conn, "SELECT id, table_no, floor, table_type FROM restaurant_tables WHERE is_active = 1 ORDER BY table_no ASC");
$all_tables_arr = [];
while ($t = mysqli_fetch_assoc($all_tables_result)) $all_tables_arr[] = $t;

/**
 * Build the receipt markup (used for the same-page receipt preview after a full payment).
 */
function render_pos_receipt_html($conn, $order_id) {
    $order = get_order_full($conn, $order_id);
    if (!$order) return '';
    $items = get_order_items($conn, $order_id);
    $total_paid = get_order_payments_total($conn, $order_id);

    $settings = [];
    $res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
    while ($row = mysqli_fetch_assoc($res)) $settings[$row['setting_key']] = $row['setting_value'];
    $resort_name = $settings['resort_name'] ?? 'Resort';
    $resort_address = $settings['resort_address'] ?? '';
    $resort_phone = $settings['resort_phone'] ?? '';
    $resort_website = $settings['resort_website'] ?? '';
    $cashier_name = current_user()['full_name'];

    ob_start();
    ?>
    <div class="print-header">
        <h4><?= e($resort_name) ?></h4>
        <div class="muted">Restaurant</div>
        <div class="muted"><?= e($resort_address) ?></div>
        <div class="muted"><?= e($resort_phone) ?><?= $resort_website ? ' · ' . e($resort_website) : '' ?></div>
        <div class="muted mt-1">Order: <?= e($order['order_no']) ?> · <?= e(date('d M Y, h:i A')) ?></div>
    </div>

    <div class="print-info-row"><span>Table</span><span><?= e($order['table_no']) ?></span></div>
    <div class="print-info-row"><span>Floor</span><span><?= e($order['floor'] ?: '—') ?></span></div>
    <div class="print-info-row"><span>Order Time</span><span><?= e(date('d M Y, h:i A', strtotime($order['order_datetime']))) ?></span></div>
    <div class="print-info-row"><span>Cashier</span><span><?= e($cashier_name) ?></span></div>

    <table class="print-table">
        <thead><tr><th>Item</th><th>Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['item_name']) ?><?= $it['size'] ? ' (' . e($it['size']) . ')' : '' ?></td>
                <td><?= (int)$it['qty'] ?></td>
                <td class="text-end">৳<?= number_format((float)$it['price'], 2) ?></td>
                <td class="text-end">৳<?= number_format((float)$it['total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-totals">
        <div class="line"><span>Subtotal</span><span>৳<?= number_format((float)$order['subtotal'], 2) ?></span></div>
        <?php if ((float)$order['tax'] > 0): ?>
        <div class="line"><span>Tax</span><span>৳<?= number_format((float)$order['tax'], 2) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$order['discount'] > 0): ?>
        <div class="line"><span>Discount</span><span>− ৳<?= number_format((float)$order['discount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="line grand"><span>Grand Total</span><span>৳<?= number_format((float)$order['total_amount'], 2) ?></span></div>
        <div class="line"><span>Total Paid</span><span>৳<?= number_format($total_paid, 2) ?></span></div>
        <div class="line"><span>Due</span><span>৳0.00</span></div>
    </div>

    <div class="print-paid-note">Paid in Full</div>

    <div class="text-center text-muted mt-3" style="font-size:0.75rem;">Thank you for visiting us.</div>
    <?php
    return ob_get_clean();
}

// AJAX endpoint: save the order with full payment and return the receipt HTML — no redirect.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_full_and_save') {
    header('Content-Type: application/json');

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'errors' => ['Invalid request token. Please try again.']]);
        exit;
    }

    $post_table_id = (int)($_POST['table_id'] ?? $table_id);
    $cart_json = $_POST['cart_json'] ?? '[]';
    $cart = json_decode($cart_json, true);
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $order_type = $_POST['order_type'] ?? 'dine_in';
    $valid_methods = ['cash','card','mobile_banking','bank','other'];
    if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';
    if (!array_key_exists($order_type, $order_types)) $order_type = 'dine_in';
    $existing_order_id = (int)($_POST['order_id'] ?? 0);

    $ajax_errors = [];

    if ($post_table_id <= 0) {
        $ajax_errors[] = 'Please select a table.';
    }
    if (empty($cart) || !is_array($cart)) {
        $ajax_errors[] = 'Please add at least one item to the order.';
    }

    $subtotal = 0;
    $validated_cart = [];
    if (empty($ajax_errors)) {
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
            $ajax_errors[] = 'No valid items found in the order.';
        }
    }

    $tax_amount = round(max(0, $subtotal - $discount) * ($tax_percent / 100), 2);
    $grand_total = max(0, $subtotal - $discount + $tax_amount);
    // Full payment required — the amount sent must match the grand total (small tolerance for rounding).
    $payment_amount = $grand_total;

    if ($discount < 0 || $discount > $subtotal) {
        $ajax_errors[] = 'Discount cannot be negative or exceed the subtotal.';
    }
    if ($grand_total <= 0) {
        $ajax_errors[] = 'Order total must be greater than zero.';
    }

    if (!empty($ajax_errors)) {
        echo json_encode(['ok' => false, 'errors' => $ajax_errors]);
        exit;
    }

    mysqli_begin_transaction($conn);
    $ok = true;
    $status = 'paid';

    if ($existing_order_id > 0) {
        $order_id_to_use = $existing_order_id;

        $stmt = mysqli_prepare($conn, "DELETE FROM restaurant_food_order_items WHERE order_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $order_id_to_use);
        $ok = mysqli_stmt_execute($stmt) && $ok;

        $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_orders SET table_id = ?, tax = ?, discount = ?, status = ?, order_type = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'iddssi', $post_table_id, $tax_amount, $discount, $status, $order_type, $order_id_to_use);
        $ok = mysqli_stmt_execute($stmt) && $ok;
    } else {
        $order_no = generate_order_no($conn);
        $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_food_orders (order_no, table_id, order_type, tax, discount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sisddsi', $order_no, $post_table_id, $order_type, $tax_amount, $discount, $status, $user['id']);
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
        $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_payments (order_id, payment_type, payment_method, amount, created_by) VALUES (?, 'final_payment', ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isdi', $order_id_to_use, $payment_method, $payment_amount, $user['id']);
        $ok = mysqli_stmt_execute($stmt) && $ok;
    }

    if ($ok) {
        $ok = recalc_order_total($conn, $order_id_to_use);
    }

    if ($ok) {
        // Belt-and-braces: this workflow only ever creates fully paid orders.
        $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_orders SET status = 'paid' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $order_id_to_use);
        $ok = mysqli_stmt_execute($stmt) && $ok;
    }

    if ($ok) {
        mysqli_commit($conn);
        $receipt_html = render_pos_receipt_html($conn, $order_id_to_use);
        $fresh_order = get_order_full($conn, $order_id_to_use);
        echo json_encode([
            'ok' => true,
            'order_id' => $order_id_to_use,
            'order_no' => $fresh_order['order_no'],
            'receipt_html' => $receipt_html,
        ]);
        exit;
    } else {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'errors' => ['Failed to save order: ' . mysqli_error($conn)]]);
        exit;
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
        grid-template-columns: minmax(0, 1fr) minmax(380px, 440px);
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
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 14px;
        align-content: start; /* cards pack from the top instead of stretching to fill leftover space */
    }
    @media (max-width: 480px) {
        .food-grid { grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 10px; }
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

    .table-select-box { margin-bottom: 12px; flex-shrink: 0; }
    .table-select-box label { font-size: 0.78rem; font-weight: 700; color: #495a52; margin-bottom: 4px; display: block; }
    .table-select-box select { width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #d8ddd9; font-size: 0.88rem; font-weight: 600; color: #1c3d2e; background: #fafbfa; }
    .table-select-box select:focus { outline: none; border-color: #0f5132; background: #fff; }

    /* Same-page receipt modal */
    .receipt-modal-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(15, 24, 20, 0.55);
        z-index: 1050; align-items: center; justify-content: center; padding: 20px;
    }
    .receipt-modal-backdrop.show { display: flex; }
    .receipt-modal-card {
        background: #fff; border-radius: 14px; max-width: 420px; width: 100%;
        max-height: 90vh; overflow-y: auto; padding: 22px 22px 18px;
        font-family: Arial, Helvetica, sans-serif;
    }
    .receipt-modal-actions { display: flex; gap: 10px; margin-top: 16px; }
    .receipt-modal-actions button { flex: 1 1 0; border-radius: 10px; padding: 11px; font-weight: 700; font-size: 0.9rem; border: none; }
    .receipt-modal-actions .btn-print { background: #0f5132; color: #fff; }
    .receipt-modal-actions .btn-print:hover { background: #0c4128; color: #fff; }
    .receipt-modal-actions .btn-new-order { background: #f4f6f5; color: #1c3d2e; border: 1px solid #d8ddd9; }
    .receipt-modal-actions .btn-new-order:hover { background: #eceeed; }

    .confirm-modal-card {
        background: #fff; border-radius: 14px; max-width: 380px; width: 100%;
        padding: 26px 24px 20px; text-align: center;
    }
    .confirm-modal-card .confirm-icon { font-size: 2.4rem; color: #0f5132; margin-bottom: 8px; }
    .confirm-modal-card h5 { font-weight: 800; color: #1c3d2e; margin-bottom: 8px; }
    .confirm-modal-card p { font-size: 0.9rem; margin-bottom: 0; white-space: pre-line; }
    .confirm-modal-card .receipt-modal-actions { margin-top: 18px; }

    .print-header { text-align: center; border-bottom: 2px dashed #dcdfdd; padding-bottom: 12px; margin-bottom: 14px; }
    .print-header h4 { margin: 0; font-weight: 800; color: #0f5132; }
    .print-header .muted { color: #666; font-size: 0.82rem; }
    .print-info-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    table.print-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; margin-top: 10px; }
    table.print-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 5px 3px; font-size: 0.72rem; text-transform: uppercase; }
    table.print-table td { padding: 5px 3px; border-bottom: 1px dashed #eee; }
    .print-totals { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .print-totals .line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-totals .grand { font-size: 1.2rem; font-weight: 800; color: #0f5132; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 8px; }
    .print-paid-note { background: #d1f5e0; color: #0f5132; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }

    /* Keep the receipt markup out of the normal page flow/view. It only becomes
       visible inside @media print, right when window.print() fires. */
    .receipt-print-only {
        position: absolute !important;
        left: -9999px !important;
        top: -9999px !important;
        width: 80mm;
    }

    /* POS/thermal (80mm) receipt margin baked into the print job itself, so the
       browser's print dialog never needs manual margin setup before printing.
       Left/right kept tight (0.05in) since receipt paper is narrow; top/bottom
       kept a bit looser (0.1in). Matches restaurant_receipt.php's POS mode. */
    @page {
        margin-left: 0.05in;
        margin-right: 0.05in;
        margin-top: 0.1in;
        margin-bottom: 0.1in;
    }

    @media print {
        html, body { height: auto !important; overflow: visible !important; }
        body * { visibility: hidden !important; }
        #receiptPrintArea, #receiptPrintArea * { visibility: visible !important; }
        #receiptPrintArea {
            display: block !important;
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100%; max-width: 80mm; margin: 0 auto;
        }
    }
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
            <input type="hidden" name="action" id="formAction" value="pay_full_and_save">
            <input type="hidden" name="order_id" id="orderIdField" value="<?= $existing_order ? (int)$existing_order['id'] : 0 ?>">
            <input type="hidden" name="cart_json" id="cartJson" value="[]">

            <div class="table-select-box">
                <label for="tableSelect"><i class="bi bi-grid-3x3-gap me-1"></i>Table</label>
                <select name="table_id" id="tableSelect" onchange="onTableChange(this)">
                    <?php foreach ($all_tables_arr as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $table_id) ? 'selected' : '' ?>>
                            <?= e($t['table_no']) ?> — <?= e($t['table_type']) ?><?= $t['floor'] ? ' (' . e($t['floor']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cart-header">
                <h5>Current Order</h5>
                <span class="table-chip"><i class="bi bi-people"></i> <span id="tableChipLabel"><?= e($table['table_no']) ?></span></span>
            </div>
            <div class="text-muted small mb-2" id="tableSubLabel">
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
                    <input type="number" step="0.1" min="0" name="tax_percent" id="tax_percent" class="form-control form-control-sm" value="0" oninput="recalcCart()">
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
                    <label class="form-label small fw-semibold mb-1">Amount Received (৳)</label>
                    <input type="number" step="0.01" min="0" name="amount_received" id="amount_received" class="form-control form-control-sm" placeholder="0.00" oninput="recalcCart()">
                </div>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-2">
                <span>Amount to Pay (Full)</span><span id="calc_due" class="fw-semibold">৳0.00</span>
            </div>
            <div class="d-flex justify-content-between small mt-1" id="changeRow" style="display:none !important;">
                <span class="text-muted">Change Due</span><span id="calc_change" class="fw-semibold text-success">৳0.00</span>
            </div>
            <div class="small mt-1" id="paymentHint" style="color:#b02a37;"></div>

            <div id="saveError" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none small"></div>

            <button type="submit" class="btn-pay" id="saveBtn" disabled>
                <i class="bi bi-credit-card"></i> Pay Full Amount &amp; Save Order
            </button>
        </form>
    </div>
</div>

<!-- Custom confirm popup, shown before saving the order -->
<div class="receipt-modal-backdrop" id="confirmBackdrop">
    <div class="confirm-modal-card">
        <div class="confirm-icon"><i class="bi bi-question-circle"></i></div>
        <h5>Confirm Order</h5>
        <p id="confirmMessage" class="text-muted"></p>
        <div class="receipt-modal-actions">
            <button type="button" class="btn-new-order" onclick="closeConfirmModal(false)">Cancel</button>
            <button type="button" class="btn-print" id="confirmYesBtn" onclick="closeConfirmModal(true)"><i class="bi bi-check-circle me-1"></i>Yes, Save Order</button>
        </div>
    </div>
</div>

<!-- Hidden receipt container used only for silent direct printing.
     Not shown on screen (see .receipt-print-only rule below); only visible
     inside @media print so the OS print dialog is skipped in kiosk-printing mode. -->
<div id="receiptPrintArea" class="receipt-print-only">
    <!-- receipt HTML injected here right before printing -->
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

function onTableChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('tableChipLabel').innerText = opt.text.split(' — ')[0];
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
    // Full payment is required — the amount due IS the amount to pay.
    const due = grandTotal;

    const amountReceived = parseFloat(document.getElementById('amount_received').value) || 0;
    const change = amountReceived - due;

    document.getElementById('calc_subtotal').innerText = '৳' + subtotal.toFixed(0);
    document.getElementById('calc_discount').innerText = '৳' + discount.toFixed(0);
    document.getElementById('calc_tax_pct').innerText = taxPercent;
    document.getElementById('calc_tax').innerText = '৳' + taxAmount.toFixed(0);
    document.getElementById('calc_grand').innerText = '৳' + grandTotal.toFixed(0);
    document.getElementById('calc_due').innerText = '৳' + due.toFixed(2);

    document.getElementById('discount').max = subtotal;

    document.getElementById('cartJson').value = JSON.stringify(cart);

    const changeRow = document.getElementById('changeRow');
    const hint = document.getElementById('paymentHint');

    const hasItems = cart.length > 0;
    const discountValid = discount <= subtotal + 0.01;
    // Payment is only "fully paid" once the received amount covers the due amount
    // (small tolerance for floating point rounding).
    const isFullyPaid = amountReceived + 0.01 >= due && due > 0;

    if (isFullyPaid && change > 0.004) {
        changeRow.style.setProperty('display', 'flex', 'important');
        document.getElementById('calc_change').innerText = '৳' + change.toFixed(2);
    } else {
        changeRow.style.setProperty('display', 'none', 'important');
    }

    if (hasItems && grandTotal > 0 && !isFullyPaid) {
        const remaining = Math.max(0, due - amountReceived);
        hint.textContent = 'Enter the full amount (remaining ৳' + remaining.toFixed(2) + ') to enable payment.';
    } else {
        hint.textContent = '';
    }

    document.getElementById('saveBtn').disabled = !(hasItems && discountValid && grandTotal > 0 && isFullyPaid);
}

function showError(msg) {
    const box = document.getElementById('saveError');
    box.textContent = msg;
    box.classList.remove('d-none');
}

function clearError() {
    const box = document.getElementById('saveError');
    box.classList.add('d-none');
    box.textContent = '';
}

function showReceipt(html) {
    // Silent direct-print: no preview modal, no manual click.
    // Inject the receipt markup into the hidden print area and fire print immediately.
    document.getElementById('receiptPrintArea').innerHTML = html;
    window.print();
    // Reset the form for the next order right after printing.
    startNewOrder();
}

function startNewOrder() {
    // Reset cart, totals, and payment info; keep the same table selected so staff can
    // immediately take the next order at this table, or they can pick a new one.
    cart = [];
    document.getElementById('discount').value = 0;
    document.getElementById('tax_percent').value = 5;
    document.getElementById('amount_received').value = '';
    document.getElementById('orderIdField').value = 0;
    clearError();
    document.getElementById('receiptPrintArea').innerHTML = '';
    renderCart();
}

let confirmResolve = null;

function openConfirmModal(message) {
    document.getElementById('confirmMessage').innerText = message;
    document.getElementById('confirmBackdrop').classList.add('show');
    return new Promise(resolve => { confirmResolve = resolve; });
}

function closeConfirmModal(result) {
    document.getElementById('confirmBackdrop').classList.remove('show');
    if (confirmResolve) {
        confirmResolve(result);
        confirmResolve = null;
    }
}

document.getElementById('orderForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();

    if (cart.length === 0) {
        showError('Please add at least one item to the order.');
        return;
    }

    const confirmTotal = document.getElementById('calc_grand').innerText;
    const confirmTable = document.getElementById('tableChipLabel').innerText;
    const confirmed = await openConfirmModal(
        'Confirm order for Table ' + confirmTable + ' — Total ' + confirmTotal + '.\n\nThis will save the order and mark it as fully paid.'
    );
    if (!confirmed) {
        return;
    }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    const originalLabel = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';

    const formData = new FormData(this);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                showReceipt(data.receipt_html);
            } else {
                showError((data.errors && data.errors[0]) || 'Failed to save order.');
            }
        })
        .catch(() => {
            showError('Network error while saving the order. Please try again.');
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalLabel;
            recalcCart();
        });
});

renderMenuItems();
renderCart();
</script>

<?php require __DIR__ . '/footer.php'; ?>