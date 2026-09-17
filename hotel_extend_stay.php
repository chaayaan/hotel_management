<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for extension.');
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
    flash_set('danger', 'Only an active (checked-in) booking can be extended.');
    header('Location: hotel_front_desk.php');
    exit;
}

$errors = [];

// ---------- AJAX: conflict check for a candidate new_until date ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_extend') {
    header('Content-Type: application/json');
    $new_until = $_GET['new_until'] ?? '';
    if ($new_until) {
        // Check conflicts strictly after current reserved_until, excluding this booking
        $conflict = is_room_available($conn, $booking['room_id'], $booking['reserved_until'], $new_until, $booking_id);
        echo json_encode(['available' => $conflict === false, 'conflict' => $conflict ?: null]);
    } else {
        echo json_encode(['available' => null]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_extend') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $new_until = $_POST['new_reserved_until'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if ($new_until === '' || strtotime($new_until) <= strtotime($booking['reserved_until'])) {
            $errors[] = 'New checkout date must be after the current reserved-until date.';
        }

        if (empty($errors)) {
            $conflict = is_room_available($conn, $booking['room_id'], $booking['reserved_until'], $new_until, $booking_id);
            if ($conflict !== false) {
                $errors[] = "Cannot extend: conflicts with reservation {$conflict['reservation_no']} starting {$conflict['reserved_from']}.";
            }
        }

        if (empty($errors)) {
            $added_nights = calc_nights($booking['reserved_from'], $new_until) - (int)$booking['reserved_nights'];
            $price_per_day = (float)$booking['room_charge_per_day'];
            $extended_charge = $added_nights * $price_per_day;

            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "INSERT INTO hotel_booking_extensions
                (booking_id, old_reserved_until, new_reserved_until, added_nights, room_charge_per_day, total_extended_charge, extended_by, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'issiddis',
                $booking_id, $booking['reserved_until'], $new_until, $added_nights, $price_per_day, $extended_charge, $user['id'], $reason
            );
            $ok = mysqli_stmt_execute($stmt) && $ok;

            if ($ok) {
                $new_nights = (int)$booking['reserved_nights'] + $added_nights;
                $new_extension_total = (float)$booking['extension_charge_total'] + $extended_charge;
                $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET
                    reserved_until = ?, reserved_nights = ?, extension_charge_total = ?
                    WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'sidi', $new_until, $new_nights, $new_extension_total, $booking_id);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                recalc_booking_total($conn, $booking_id);
                mysqli_commit($conn);
                flash_set('success', "Stay extended successfully. New checkout date: " . date('d M Y', strtotime($new_until)));
                header('Location: hotel_front_desk.php');
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Failed to extend stay: ' . mysqli_error($conn);
            }
        }
    }
}

$page_title = 'Extend Stay';
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
                <i class="bi bi-info-circle me-1"></i> Current Booking
            </div>
            <div class="card-body">
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
            <div class="info-line"><span class="label">Checked In</span><span class="value"><?= e(date('d M Y', strtotime($booking['checkin_at']))) ?></span></div>
            <div class="info-line"><span class="label">Current Reserved Until</span><span class="value"><?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
            <div class="info-line"><span class="label">Current Nights</span><span class="value"><?= (int)$booking['reserved_nights'] ?></span></div>
            <div class="info-line"><span class="label">Price / Night</span><span class="value">৳<?= number_format((float)$booking['room_charge_per_day'], 2) ?></span></div>
            <div id="conflictNote" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Extend form -->
    <div class="col-lg-7">
        <form method="POST" id="extendForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="do_extend">
            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-plus me-1"></i> Extend Stay
                </div>
                <div class="card-body">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">New Checkout Date <span class="text-danger">*</span></label>
                        <input type="date" name="new_reserved_until" id="new_reserved_until" class="form-control" required
                               min="<?= date('Y-m-d', strtotime($booking['reserved_until'] . ' +1 day')) ?>" oninput="onDateChange()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Reason (optional)</label>
                        <input type="text" name="reason" class="form-control" maxlength="255" placeholder="e.g. Guest requested extra night">
                    </div>
                </div>

                <div class="total-box">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Additional Nights</span><span id="calc_added_nights">0</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Price / Night</span><span>৳<?= number_format((float)$booking['room_charge_per_day'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="fw-semibold">Additional Charge</span>
                        <span class="grand" id="calc_added_charge">৳0.00</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 mt-3 py-2 fw-semibold" id="extendBtn" disabled>
                    <i class="bi bi-calendar-check me-1"></i> Extend Stay
                </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const currentUntil = '<?= $booking['reserved_until'] ?>';
const pricePerDay = <?= (float)$booking['room_charge_per_day'] ?>;
let extendAvailable = null;

function onDateChange() {
    const newUntil = document.getElementById('new_reserved_until').value;
    const note = document.getElementById('conflictNote');
    const btn = document.getElementById('extendBtn');

    if (!newUntil) { note.innerHTML=''; extendAvailable=null; btn.disabled=true; return; }

    const d1 = new Date(currentUntil), d2 = new Date(newUntil);
    const addedNights = Math.max(0, Math.round((d2 - d1) / (1000*60*60*24)));
    document.getElementById('calc_added_nights').innerText = addedNights;
    document.getElementById('calc_added_charge').innerText = '৳' + (addedNights * pricePerDay).toFixed(2);

    if (addedNights <= 0) {
        note.innerHTML = '<div class="alert alert-warning py-2 small mb-0">New date must be after current checkout date.</div>';
        extendAvailable = false;
        btn.disabled = true;
        return;
    }

    fetch(`hotel_extend_stay.php?booking_id=<?= $booking_id ?>&ajax=check_extend&new_until=${newUntil}`)
        .then(r => r.json())
        .then(data => {
            if (data.available) {
                note.innerHTML = '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>No conflicting reservation. Extension allowed.</div>';
                extendAvailable = true;
            } else {
                const c = data.conflict;
                note.innerHTML = `<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>Conflicts with ${c ? c.reservation_no : 'another booking'} starting ${c ? c.reserved_from : ''}.</div>`;
                extendAvailable = false;
            }
            btn.disabled = !extendAvailable;
        });
}
</script>

<?php require __DIR__ . '/footer.php'; ?>