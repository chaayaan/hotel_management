<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for checkout.');
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
    flash_set('danger', 'This booking is not currently checked in and cannot be checked out.');
    header('Location: hotel_front_desk.php');
    exit;
}

$errors = [];

// Financial breakdown
$service_total = get_booking_services_total($conn, $booking_id);
$room_charge_total = (float)$booking['room_charge_total'];
$extension_charge_total = (float)$booking['extension_charge_total'];
$existing_discount = (float)$booking['discount'];
$existing_tax = (float)$booking['tax'];
$subtotal = $room_charge_total + $extension_charge_total + $service_total;

$payments_made = get_booking_payments_total($conn, $booking_id);

// Service records for display
$services_result = mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$services = [];
while ($s = mysqli_fetch_assoc($services_result)) $services[] = $s;

// Payment history
$payments_result = mysqli_query($conn, "SELECT * FROM hotel_payments WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$payments = [];
while ($p = mysqli_fetch_assoc($payments_result)) $payments[] = $p;

// Extensions
$extensions_result = mysqli_query($conn, "SELECT * FROM hotel_booking_extensions WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$extensions = [];
while ($ex = mysqli_fetch_assoc($extensions_result)) $extensions[] = $ex;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_checkout') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $additional_discount = (float)($_POST['additional_discount'] ?? 0);
        $final_payment_amount = (float)($_POST['final_payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $valid_methods = ['cash','card','mobile_banking','bank','others'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';

        $total_discount = $existing_discount + $additional_discount;
        $final_total = $subtotal - $total_discount + $existing_tax;
        if ($final_total < 0) $final_total = 0;
        $remaining_payable = max(0, $final_total - $payments_made);

        if ($additional_discount < 0) {
            $errors[] = 'Discount cannot be negative.';
        }
        if ($total_discount > $subtotal) {
            $errors[] = 'Total discount cannot exceed the subtotal.';
        }
        if ($final_payment_amount < 0) {
            $errors[] = 'Payment amount cannot be negative.';
        }
        if ($final_payment_amount > $remaining_payable + 0.01) {
            $errors[] = 'Payment amount cannot exceed the remaining payable amount (৳' . number_format($remaining_payable, 2) . ').';
        }

        // Critical rule: checkout is blocked while any balance remains due after this payment.
        $balance_after_payment = max(0, $remaining_payable - $final_payment_amount);
        if (empty($errors) && $balance_after_payment > 0.01) {
            $errors[] = 'Checkout is not allowed while a balance is due. Remaining due after this payment: ৳' . number_format($balance_after_payment, 2) . '. Please collect full payment before checking out.';
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET
                status = 'checked_out', checkout_at = NOW(), checkout_by = ?, discount = ?, total_amount = ?
                WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'iddi', $user['id'], $total_discount, $final_total, $booking_id);
            $ok = mysqli_stmt_execute($stmt) && $ok;

            if ($ok && $final_payment_amount > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO hotel_payments (booking_id, payment_type, amount, payment_method, created_by) VALUES (?, 'checkout', ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'idsi', $booking_id, $final_payment_amount, $payment_method, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $stmt = mysqli_prepare($conn, "UPDATE rooms SET status = 'available' WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $booking['room_id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $stmt = mysqli_prepare($conn, "INSERT INTO room_status_logs (room_id, status, changed_by) VALUES (?, 'available', ?)");
                mysqli_stmt_bind_param($stmt, 'is', $booking['room_id'], $user['full_name']);
                mysqli_stmt_execute($stmt);
            }

            if ($ok) {
                recalc_booking_total($conn, $booking_id);
                mysqli_commit($conn);
                flash_set('success', "Guest checked out successfully from room {$booking['room_number']}.");
                header('Location: hotel_receipt.php?booking_id=' . $booking_id . '&type=checkout');
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Checkout failed: ' . mysqli_error($conn);
            }
        }
    }
}

$page_title = 'Checkout';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .info-line { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.85rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
    .mini-table { font-size: 0.8rem; }
    .section-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8a938e; font-weight: 700; margin: 14px 0 6px; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT: Complete booking info -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clipboard-data me-1"></i> Complete Booking Information
            </div>
            <div class="card-body">

            <div class="section-label">Guest & Room</div>
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Phone</span><span class="value"><?= e($booking['guest_phone']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>

            <div class="section-label">Stay</div>
            <div class="info-line"><span class="label">Checked In</span><span class="value"><?= e(date('d M Y, h:i A', strtotime($booking['checkin_at']))) ?></span></div>
            <div class="info-line"><span class="label">Reserved From → Until</span><span class="value"><?= e(date('d M', strtotime($booking['reserved_from']))) ?> → <?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
            <div class="info-line"><span class="label">Nights</span><span class="value"><?= (int)$booking['reserved_nights'] ?></span></div>
            <div class="info-line"><span class="label">Room Charge</span><span class="value">৳<?= number_format($room_charge_total, 2) ?></span></div>
            <?php if ($extension_charge_total > 0): ?>
            <div class="info-line"><span class="label">Extension Charge</span><span class="value">৳<?= number_format($extension_charge_total, 2) ?></span></div>
            <?php endif; ?>

            <?php if (!empty($extensions)): ?>
            <div class="section-label">Extension History</div>
            <table class="table table-sm mini-table mb-0">
                <thead><tr><th>Old Until</th><th>New Until</th><th>Nights</th><th>Charge</th></tr></thead>
                <tbody>
                <?php foreach ($extensions as $ex): ?>
                    <tr>
                        <td><?= e(date('d M', strtotime($ex['old_reserved_until']))) ?></td>
                        <td><?= e(date('d M Y', strtotime($ex['new_reserved_until']))) ?></td>
                        <td><?= (int)$ex['added_nights'] ?></td>
                        <td>৳<?= number_format((float)$ex['total_extended_charge'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="section-label">Services</div>
            <?php if (empty($services)): ?>
                <div class="text-muted small">No services added.</div>
            <?php else: ?>
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Type</th><th>Amount</th><th>Discount</th><th>Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td><?= e(ucfirst($s['service_type'])) ?></td>
                            <td>৳<?= number_format((float)$s['amount'], 2) ?></td>
                            <td>৳<?= number_format((float)$s['discount'], 2) ?></td>
                            <td>৳<?= number_format((float)$s['amount'] - (float)$s['discount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="3">Total Services</td><td>৳<?= number_format($service_total, 2) ?></td></tr></tfoot>
                </table>
            <?php endif; ?>

            <div class="section-label">Payment History</div>
            <?php if (empty($payments)): ?>
                <div class="text-muted small">No payments recorded yet.</div>
            <?php else: ?>
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Type</th><th>Method</th><th>Amount</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e(ucfirst($p['payment_type'])) ?></td>
                            <td><?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></td>
                            <td>৳<?= number_format((float)$p['amount'], 2) ?></td>
                            <td class="text-muted"><?= e(date('d M, h:i A', strtotime($p['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="2">Total Paid</td><td colspan="2">৳<?= number_format($payments_made, 2) ?></td></tr></tfoot>
                </table>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Checkout process -->
    <div class="col-lg-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="do_checkout">
            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-box-arrow-right me-1"></i> Checkout Process
                </div>
                <div class="card-body">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Checkout Date</label>
                        <input type="text" class="form-control" value="<?= date('d M Y') ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Checkout Time</label>
                        <input type="text" class="form-control" value="<?= date('h:i A') ?>" disabled>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Additional Discount</label>
                        <input type="number" step="0.01" min="0" name="additional_discount" id="additional_discount" class="form-control" value="0" oninput="recalc()">
                        <div class="form-text">Existing discount: ৳<?= number_format($existing_discount, 2) ?></div>
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
                        <label class="form-label small fw-semibold">Final Payment Amount</label>
                        <input type="number" step="0.01" min="0" name="final_payment_amount" id="final_payment_amount" class="form-control" value="0" oninput="validatePayment()">
                        <div class="form-text text-danger d-none" id="paymentError">Payment cannot exceed the remaining payable amount.</div>
                        <div class="alert alert-warning py-2 small mt-2 d-none" id="dueBlockError"><i class="bi bi-exclamation-triangle me-1"></i>Checkout is blocked while a balance remains due. Please collect full payment first.</div>
                    </div>
                </div>

                <div class="total-box">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Room + Extension + Services</span><span>৳<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Tax (+)</span><span>৳<?= number_format($existing_tax, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Total Discount (−)</span><span id="disp_discount">৳<?= number_format($existing_discount, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="fw-semibold">Final Amount</span>
                        <span class="grand" id="disp_final">৳<?= number_format(max(0, $subtotal - $existing_discount + $existing_tax), 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-1">
                        <span>Already Paid</span><span>৳<?= number_format($payments_made, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small fw-semibold mt-1">
                        <span>Remaining Payable</span><span id="disp_remaining">৳<?= number_format(max(0, $subtotal - $existing_discount + $existing_tax - $payments_made), 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mt-1 pt-1 border-top">
                        <span class="fw-semibold text-danger">Due After This Payment</span><span id="disp_due_after" class="fw-semibold text-danger">৳<?= number_format(max(0, $subtotal - $existing_discount + $existing_tax - $payments_made), 2) ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger w-100 mt-3 py-2 fw-semibold" id="checkoutBtn">
                    <i class="bi bi-box-arrow-right me-1"></i> Check Out
                </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const subtotal = <?= $subtotal ?>;
const existingDiscount = <?= $existing_discount ?>;
const existingTax = <?= $existing_tax ?>;
const paymentsMade = <?= $payments_made ?>;

function recalc() {
    const addDiscount = parseFloat(document.getElementById('additional_discount').value) || 0;
    const totalDiscount = existingDiscount + addDiscount;
    const finalAmount = Math.max(0, subtotal - totalDiscount + existingTax);
    const remaining = Math.max(0, finalAmount - paymentsMade);

    document.getElementById('disp_discount').innerText = '৳' + totalDiscount.toFixed(2);
    document.getElementById('disp_final').innerText = '৳' + finalAmount.toFixed(2);
    document.getElementById('disp_remaining').innerText = '৳' + remaining.toFixed(2);
    document.getElementById('final_payment_amount').max = remaining;

    validatePayment();
}

function validatePayment() {
    const addDiscount = parseFloat(document.getElementById('additional_discount').value) || 0;
    const totalDiscount = existingDiscount + addDiscount;
    const finalAmount = Math.max(0, subtotal - totalDiscount + existingTax);
    const remaining = Math.max(0, finalAmount - paymentsMade);
    const payment = parseFloat(document.getElementById('final_payment_amount').value) || 0;
    const dueAfter = Math.max(0, remaining - payment);

    document.getElementById('disp_due_after').innerText = '৳' + dueAfter.toFixed(2);

    const errBox = document.getElementById('paymentError');
    const dueBlockBox = document.getElementById('dueBlockError');
    const btn = document.getElementById('checkoutBtn');

    if (payment > remaining + 0.01) {
        errBox.classList.remove('d-none');
        dueBlockBox.classList.add('d-none');
        btn.disabled = true;
    } else if (dueAfter > 0.01) {
        errBox.classList.add('d-none');
        dueBlockBox.classList.remove('d-none');
        btn.disabled = true;
    } else {
        errBox.classList.add('d-none');
        dueBlockBox.classList.add('d-none');
        btn.disabled = false;
    }
}

recalc();
</script>

<?php require __DIR__ . '/footer.php'; ?>