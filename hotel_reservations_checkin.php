<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for check-in.');
    header('Location: hotel_front_desk.php');
    exit;
}

$booking = get_booking_full($conn, $booking_id);

if (!$booking) {
    flash_set('danger', 'Booking not found.');
    header('Location: hotel_front_desk.php');
    exit;
}

if ($booking['status'] !== 'reserved') {
    flash_set('danger', 'This booking is not in a reserved state and cannot be checked in.');
    header('Location: hotel_front_desk.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_checkin') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $tax = (float)($_POST['tax'] ?? 0);
        $discount = (float)($_POST['discount'] ?? 0);
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $valid_methods = ['cash','card','mobile_banking','bank','others'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';

        $room_charge_total = (float)$booking['room_charge_total'];
        $total_amount = $room_charge_total - $discount + $tax;
        if ($total_amount < 0) $total_amount = 0;

        if ($discount < 0 || $discount > $room_charge_total) {
            $errors[] = 'Discount cannot be negative or exceed the room charge.';
        }
        if ($payment_amount < 0) {
            $errors[] = 'Payment amount cannot be negative.';
        }
        if ($payment_amount > $total_amount) {
            $errors[] = 'Payment amount cannot exceed the total payable amount (৳' . number_format($total_amount, 2) . ').';
        }

        if (empty($errors)) {
            // Re-check availability at the moment of check-in (safety net)
            $conflict = is_room_available($conn, $booking['room_id'], $booking['reserved_from'], $booking['reserved_until'], $booking_id);
            if ($conflict !== false) {
                $errors[] = 'This room now conflicts with another booking. Please review reservations.';
            }
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET
                status = 'checked_in', checkin_at = NOW(), checkin_by = ?, tax = ?, discount = ?, total_amount = ?
                WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'idddi', $user['id'], $tax, $discount, $total_amount, $booking_id);
            $ok = mysqli_stmt_execute($stmt) && $ok;

            if ($ok && $payment_amount > 0) {
                $stmt = mysqli_prepare($conn, "INSERT INTO hotel_payments (booking_id, payment_type, amount, payment_method, created_by) VALUES (?, 'checkin', ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'idsi', $booking_id, $payment_amount, $payment_method, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $stmt = mysqli_prepare($conn, "UPDATE rooms SET status = 'occupied' WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $booking['room_id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $stmt = mysqli_prepare($conn, "INSERT INTO room_status_logs (room_id, status, changed_by) VALUES (?, 'occupied', ?)");
                mysqli_stmt_bind_param($stmt, 'is', $booking['room_id'], $user['full_name']);
                mysqli_stmt_execute($stmt);
            }

            if ($ok) {
                mysqli_commit($conn);
                flash_set('success', "Guest checked in successfully for room {$booking['room_number']}.");
                header('Location: hotel_receipt.php?booking_id=' . $booking_id . '&type=checkin');
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Check-in failed: ' . mysqli_error($conn);
            }
        }
    }
}

$nights = (int)$booking['reserved_nights'];
$room_charge_total = (float)$booking['room_charge_total'];

$page_title = 'Check-In';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .info-line { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.88rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT: Booking info -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-info-circle me-1"></i> Booking Information
            </div>
            <div class="card-body">
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Phone</span><span class="value"><?= e($booking['guest_phone']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
            <div class="info-line"><span class="label">Floor</span><span class="value"><?= e($booking['floor'] ?: '—') ?></span></div>
            <div class="info-line"><span class="label">Adults / Children</span><span class="value"><?= (int)$booking['adults'] ?> / <?= (int)$booking['children'] ?></span></div>
            <div class="info-line"><span class="label">Reserved From</span><span class="value"><?= e(date('d M Y', strtotime($booking['reserved_from']))) ?></span></div>
            <div class="info-line"><span class="label">Reserved Until</span><span class="value"><?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
            <div class="info-line"><span class="label">Nights</span><span class="value"><?= $nights ?></span></div>
            <div class="info-line"><span class="label">Price / Night</span><span class="value">৳<?= number_format((float)$booking['room_charge_per_day'], 2) ?></span></div>
            <div class="info-line"><span class="label">Room Charge Total</span><span class="value">৳<?= number_format($room_charge_total, 2) ?></span></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Check-in process -->
    <div class="col-lg-7">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="do_checkin">
            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Check-In Process
                </div>
                <div class="card-body">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Check-In Date</label>
                        <input type="text" class="form-control" value="<?= date('d M Y') ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Check-In Time</label>
                        <input type="text" class="form-control" value="<?= date('h:i A') ?>" disabled>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Tax</label>
                        <input type="number" step="0.01" min="0" name="tax" id="tax" class="form-control" value="0" oninput="recalc()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Discount</label>
                        <input type="number" step="0.01" min="0" max="<?= $room_charge_total ?>" name="discount" id="discount" class="form-control" value="0" oninput="recalc()">
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
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Payment Amount</label>
                        <input type="number" step="0.01" min="0" name="payment_amount" id="payment_amount" class="form-control" value="0" oninput="validatePayment()">
                        <div class="form-text text-danger d-none" id="paymentError">Payment cannot exceed the total payable amount.</div>
                    </div>
                </div>

                <div class="total-box">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Room Charge</span><span id="disp_room_charge">৳<?= number_format($room_charge_total, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Tax (+)</span><span id="disp_tax">৳0.00</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Discount (−)</span><span id="disp_discount">৳0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="fw-semibold">Total Payable</span>
                        <span class="grand" id="disp_total">৳<?= number_format($room_charge_total, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-1">
                        <span>Due After Payment</span><span id="disp_due">৳<?= number_format($room_charge_total, 2) ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 mt-3 py-2 fw-semibold" id="checkinBtn">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Check In
                </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const roomCharge = <?= $room_charge_total ?>;

function recalc() {
    const tax = parseFloat(document.getElementById('tax').value) || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const total = Math.max(0, roomCharge - discount + tax);

    document.getElementById('disp_tax').innerText = '৳' + tax.toFixed(2);
    document.getElementById('disp_discount').innerText = '৳' + discount.toFixed(2);
    document.getElementById('disp_total').innerText = '৳' + total.toFixed(2);

    document.getElementById('payment_amount').max = total;
    validatePayment();
}

function validatePayment() {
    const tax = parseFloat(document.getElementById('tax').value) || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const total = Math.max(0, roomCharge - discount + tax);
    const payment = parseFloat(document.getElementById('payment_amount').value) || 0;
    const due = Math.max(0, total - payment);

    document.getElementById('disp_due').innerText = '৳' + due.toFixed(2);

    const errBox = document.getElementById('paymentError');
    const btn = document.getElementById('checkinBtn');
    if (payment > total) {
        errBox.classList.remove('d-none');
        btn.disabled = true;
    } else {
        errBox.classList.add('d-none');
        btn.disabled = false;
    }
}

recalc();
</script>

<?php require __DIR__ . '/footer.php'; ?>