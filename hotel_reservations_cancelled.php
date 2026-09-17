<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for cancellation.');
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
    flash_set('danger', 'Only a reserved (not yet checked-in) booking can be cancelled.');
    header('Location: hotel_front_desk.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_cancel') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $reason = trim($_POST['cancel_reason'] ?? '');

        mysqli_begin_transaction($conn);
        $ok = true;

        $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?");
        $note_append = $reason !== '' ? (" | Cancelled: " . $reason) : " | Cancelled";
        mysqli_stmt_bind_param($stmt, 'isi', $user['id'], $note_append, $booking_id);
        $ok = mysqli_stmt_execute($stmt) && $ok;

        // Room only needs releasing if it was somehow marked occupied for this future reservation (normally it wouldn't be).
        if ($ok) {
            $roomStatus = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM rooms WHERE id = {$booking['room_id']}"))['status'];
            if ($roomStatus === 'occupied') {
                $stmt = mysqli_prepare($conn, "UPDATE rooms SET status = 'available' WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $booking['room_id']);
                mysqli_stmt_execute($stmt);
            }
        }

        if ($ok) {
            mysqli_commit($conn);
            flash_set('success', "Reservation {$booking['reservation_no']} has been cancelled.");
            header('Location: hotel_front_desk.php');
            exit;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Failed to cancel reservation: ' . mysqli_error($conn);
        }
    }
}

$page_title = 'Cancel Reservation';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .info-line { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.88rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT: Booking info -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-info-circle me-1"></i> Booking Information
            </div>
            <div class="card-body">
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Phone</span><span class="value"><?= e($booking['guest_phone']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
            <div class="info-line"><span class="label">Reserved From</span><span class="value"><?= e(date('d M Y', strtotime($booking['reserved_from']))) ?></span></div>
            <div class="info-line"><span class="label">Reserved Until</span><span class="value"><?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
            <div class="info-line"><span class="label">Nights</span><span class="value"><?= (int)$booking['reserved_nights'] ?></span></div>
            <div class="info-line"><span class="label">Room Charge Total</span><span class="value">৳<?= number_format((float)$booking['room_charge_total'], 2) ?></span></div>
            <div class="info-line"><span class="label">Status</span><span class="value"><span class="badge <?= booking_status_badge($booking['status']) ?>"><?= e(ucwords($booking['status'])) ?></span></span></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Cancellation action -->
    <div class="col-lg-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="do_cancel">
            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-x-circle me-1"></i> Cancellation
                </div>
                <div class="card-body">

                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Cancelling will mark this reservation as <strong>cancelled</strong>. The room will be released for the reserved
                    period. This action does not delete the booking — it remains visible in booking history.
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Cancellation Reason (optional)</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" maxlength="200" placeholder="e.g. Guest requested cancellation"></textarea>
                </div>

                <button type="submit" class="btn btn-danger w-100 py-2 fw-semibold" onclick="return confirm('Are you sure you want to cancel this reservation?');">
                    <i class="bi bi-x-circle me-1"></i> Cancel Reservation
                </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>