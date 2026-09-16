<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for service.');
    header('Location: hotel_front_desk.php');
    exit;
}

$booking = get_booking_full($conn, $booking_id);

if (!$booking) {
    flash_set('danger', 'Booking not found.');
    header('Location: hotel_front_desk.php');
    exit;
}

if ($booking['status'] !== 'checked_in') {
    flash_set('danger', 'Services can only be added to an active (checked-in) booking.');
    header('Location: hotel_front_desk.php');
    exit;
}

$errors = [];
$service_types = ['food' => 'Food', 'spa' => 'Spa', 'laundry' => 'Laundry', 'others' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $service_type = $_POST['service_type'] ?? 'others';
        if (!array_key_exists($service_type, $service_types)) $service_type = 'others';
        $service_notes = trim($_POST['service_notes'] ?? '');
        $discount = (float)($_POST['discount'] ?? 0);
        $initial_payment = (float)($_POST['initial_payment'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $valid_methods = ['cash','card','mobile_banking','bank','others'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';

        $item_names = $_POST['item_name'] ?? [];
        $item_qtys = $_POST['item_qty'] ?? [];
        $item_prices = $_POST['item_price'] ?? [];

        $items = [];
        $service_amount = 0;
        for ($i = 0; $i < count($item_names); $i++) {
            $name = trim($item_names[$i] ?? '');
            $qty = max(1, (int)($item_qtys[$i] ?? 1));
            $price = max(0, (float)($item_prices[$i] ?? 0));
            if ($name === '') continue;
            $total = $qty * $price;
            $service_amount += $total;
            $items[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'total' => $total];
        }

        if (empty($items)) {
            $errors[] = 'Please add at least one item.';
        }
        if ($discount < 0 || $discount > $service_amount) {
            $errors[] = 'Discount cannot be negative or exceed the service amount.';
        }
        $payable = max(0, $service_amount - $discount);
        if ($initial_payment < 0 || $initial_payment > $payable) {
            $errors[] = 'Initial payment cannot be negative or exceed the payable amount (৳' . number_format($payable, 2) . ').';
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "INSERT INTO hotel_booking_services (booking_id, service_type, notes, amount, discount, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'issddi', $booking_id, $service_type, $service_notes, $service_amount, $discount, $user['id']);
            $ok = mysqli_stmt_execute($stmt) && $ok;
            $service_id = $ok ? mysqli_insert_id($conn) : 0;

            if ($ok) {
                $stmt = mysqli_prepare($conn, "INSERT INTO hotel_booking_service_items (booking_id, booking_service_id, item_name, quantity, price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($items as $it) {
                    mysqli_stmt_bind_param($stmt, 'iisidd', $booking_id, $service_id, $it['name'], $it['qty'], $it['price'], $it['total']);
                    $ok = mysqli_stmt_execute($stmt) && $ok;
                }
            }

            if ($ok && $initial_payment > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO hotel_payments (booking_id, payment_type, amount, payment_method, reference_note, created_by) VALUES (?, 'service', ?, ?, ?, ?)");
                $ref = 'Service #' . $service_id;
                mysqli_stmt_bind_param($stmt, 'idssi', $booking_id, $initial_payment, $payment_method, $ref, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $ok = recalc_booking_total($conn, $booking_id);
            }

            if ($ok) {
                mysqli_commit($conn);
                flash_set('success', 'Service added successfully.');
                header('Location: hotel_service_receipt.php?service_id=' . $service_id);
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Failed to add service: ' . mysqli_error($conn);
            }
        }
    }
}

$existing_services = [];
$res = mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE booking_id = {$booking_id} ORDER BY created_at DESC");
while ($row = mysqli_fetch_assoc($res)) $existing_services[] = $row;

$page_title = 'Add Service';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .pos-panel { border-radius: 14px; background: #fff; border: 1px solid #e8ebe9; padding: 20px; height: 100%; }
    .pos-panel h6 { font-weight: 700; color: #1c3d2e; margin-bottom: 14px; }
    .info-line { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.88rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
    .item-row { display: grid; grid-template-columns: 1fr 70px 90px 90px 32px; gap: 8px; align-items: center; margin-bottom: 8px; }
    .item-row input { font-size: 0.85rem; }
    .mini-history { font-size: 0.8rem; }
    .service-type-btn { border: 1px solid #d8ddd9; border-radius: 10px; padding: 10px; text-align: center; cursor: pointer; font-size: 0.82rem; }
    .service-type-btn.active { background: #0f5132; color: #fff; border-color: #0f5132; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="mb-3">
    <a href="hotel_front_desk.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Front Desk</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="pos-panel">
            <h6><i class="bi bi-info-circle me-1"></i>Booking Information</h6>
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
            <div class="info-line"><span class="label">Checked In</span><span class="value"><?= e(date('d M Y', strtotime($booking['checkin_at']))) ?></span></div>

            <div class="mt-3" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#8a938e;font-weight:700;">Existing Services (this stay)</div>
            <?php if (empty($existing_services)): ?>
                <div class="text-muted small mt-1">No services added yet.</div>
            <?php else: ?>
                <table class="table table-sm mini-history mt-1 mb-0">
                    <thead><tr><th>Type</th><th class="text-end">Amount</th><th class="text-end">Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($existing_services as $es): ?>
                        <tr>
                            <td><?= e(ucfirst($es['service_type'])) ?></td>
                            <td class="text-end">৳<?= number_format((float)$es['amount'], 2) ?></td>
                            <td class="text-end">৳<?= number_format((float)$es['amount'] - (float)$es['discount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="hotel_services_history.php?booking_id=<?= $booking_id ?>" class="small">View full service history →</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-7">
        <form method="POST" id="serviceForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_service">
            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
            <input type="hidden" name="service_type" id="service_type" value="food">

            <div class="pos-panel">
                <h6><i class="bi bi-cup-hot me-1"></i>Add Service</h6>

                <label class="form-label small fw-semibold">Service Type</label>
                <div class="row g-2 mb-3">
                    <?php foreach ($service_types as $key => $label): ?>
                        <div class="col-3">
                            <div class="service-type-btn <?= $key === 'food' ? 'active' : '' ?>" data-type="<?= $key ?>" onclick="selectServiceType('<?= $key ?>')"><?= $label ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label class="form-label small fw-semibold">Notes</label>
                <input type="text" name="service_notes" class="form-control mb-3" maxlength="255" placeholder="Optional note about this service">

                <label class="form-label small fw-semibold">Items</label>
                <div id="itemsContainer">
                    <div class="item-row">
                        <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Item name" oninput="calcTotal()">
                        <input type="number" name="item_qty[]" class="form-control form-control-sm" value="1" min="1" oninput="calcTotal()">
                        <input type="number" name="item_price[]" class="form-control form-control-sm" step="0.01" min="0" value="0" placeholder="Price" oninput="calcTotal()">
                        <input type="text" class="form-control form-control-sm item-total-display" value="৳0.00" disabled>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-brand mb-3" onclick="addItemRow()"><i class="bi bi-plus"></i> Add Item</button>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Discount</label>
                        <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="0" oninput="calcTotal()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="mobile_banking">Mobile Banking</option>
                            <option value="bank">Bank</option>
                            <option value="others">Others</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Initial Payment</label>
                        <input type="number" step="0.01" min="0" name="initial_payment" id="initial_payment" class="form-control" value="0" oninput="validatePayment()">
                        <div class="form-text text-danger d-none" id="paymentError">Initial payment cannot exceed the payable amount.</div>
                    </div>
                </div>

                <div class="total-box">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Items Total</span><span id="calc_items_total">৳0.00</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Discount (−)</span><span id="calc_discount">৳0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="fw-semibold">Payable Amount</span>
                        <span class="grand" id="calc_payable">৳0.00</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-1">
                        <span>Due After Payment</span><span id="calc_due">৳0.00</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 mt-3 py-2 fw-semibold" id="submitBtn">
                    <i class="bi bi-check-circle me-1"></i> Add Service
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function selectServiceType(type) {
    document.getElementById('service_type').value = type;
    document.querySelectorAll('.service-type-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.type === type));
}

function addItemRow() {
    const container = document.getElementById('itemsContainer');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Item name" oninput="calcTotal()">
        <input type="number" name="item_qty[]" class="form-control form-control-sm" value="1" min="1" oninput="calcTotal()">
        <input type="number" name="item_price[]" class="form-control form-control-sm" step="0.01" min="0" value="0" placeholder="Price" oninput="calcTotal()">
        <input type="text" class="form-control form-control-sm item-total-display" value="৳0.00" disabled>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button>
    `;
    container.appendChild(row);
}

function removeItemRow(btn) {
    const container = document.getElementById('itemsContainer');
    if (container.children.length > 1) {
        btn.closest('.item-row').remove();
        calcTotal();
    }
}

function calcTotal() {
    let itemsTotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
        const total = qty * price;
        row.querySelector('.item-total-display').value = '৳' + total.toFixed(2);
        itemsTotal += total;
    });

    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const payable = Math.max(0, itemsTotal - discount);

    document.getElementById('calc_items_total').innerText = '৳' + itemsTotal.toFixed(2);
    document.getElementById('calc_discount').innerText = '৳' + discount.toFixed(2);
    document.getElementById('calc_payable').innerText = '৳' + payable.toFixed(2);
    document.getElementById('initial_payment').max = payable;

    validatePayment();
}

function validatePayment() {
    let itemsTotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
        itemsTotal += qty * price;
    });
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const payable = Math.max(0, itemsTotal - discount);
    const payment = parseFloat(document.getElementById('initial_payment').value) || 0;
    const due = Math.max(0, payable - payment);

    document.getElementById('calc_due').innerText = '৳' + due.toFixed(2);

    const errBox = document.getElementById('paymentError');
    const btn = document.getElementById('submitBtn');
    if (payment > payable + 0.01) {
        errBox.classList.remove('d-none');
        btn.disabled = true;
    } else {
        errBox.classList.add('d-none');
        btn.disabled = false;
    }
}

calcTotal();
</script>

<?php require __DIR__ . '/footer.php'; ?>