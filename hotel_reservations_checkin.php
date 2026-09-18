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

// ---------- AJAX: booked date ranges for this room (for the calendar picker) ----------
// Purely additive read endpoint for the calendar UI, same pattern as hotel_reservations.php
// and hotel_extend_stay.php. Does not touch or replace is_room_available(), which remains
// the source of truth on submit. Excludes this booking's own row so its own existing
// reservation doesn't show as "booked" on its own check-in calendar.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'room_booked_dates') {
    header('Content-Type: application/json');
    $out = [];
    $stmt = mysqli_prepare($conn, "SELECT reserved_from, reserved_until, status, reservation_no
                                    FROM hotel_bookings
                                    WHERE room_id = ? AND status IN ('reserved','checked_in') AND id != ?
                                    ORDER BY reserved_from ASC");
    mysqli_stmt_bind_param($stmt, 'ii', $booking['room_id'], $booking_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'from' => $row['reserved_from'],
            'until' => $row['reserved_until'],
            'status' => $row['status'],
            'reservation_no' => $row['reservation_no'],
        ];
    }
    echo json_encode($out);
    exit;
}

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

        // ---- Reservation dates: editable at check-in ----
        // Default to the booking's existing dates; if the front desk changed them in the
        // form, these become the new effective from/until for this check-in.
        $new_reserved_from = $_POST['reserved_from'] ?? $booking['reserved_from'];
        $new_reserved_until = $_POST['reserved_until'] ?? $booking['reserved_until'];
        $dates_changed = ($new_reserved_from !== $booking['reserved_from']) || ($new_reserved_until !== $booking['reserved_until']);

        $price_per_day = (float)$booking['room_charge_per_day'];
        $room_charge_total = (float)$booking['room_charge_total'];
        $new_nights = (int)$booking['reserved_nights'];

        if ($dates_changed) {
            if ($new_reserved_from === '' || $new_reserved_until === '') {
                $errors[] = 'Please select both from and until dates.';
            } elseif (strtotime($new_reserved_until) <= strtotime($new_reserved_from)) {
                $errors[] = 'Reserved Until must be after Reserved From.';
            }

            if (empty($errors)) {
                // Re-check availability for the new range, excluding this booking's own row.
                $date_conflict = is_room_available($conn, $booking['room_id'], $new_reserved_from, $new_reserved_until, $booking_id);
                if ($date_conflict !== false) {
                    $errors[] = "The new dates conflict with reservation {$date_conflict['reservation_no']} ({$date_conflict['reserved_from']} to {$date_conflict['reserved_until']}).";
                }
            }

            if (empty($errors)) {
                $new_nights = calc_nights($new_reserved_from, $new_reserved_until);
                $room_charge_total = $new_nights * $price_per_day;
            }
        }

        // ---- Block check-in before the reservation's (possibly just-edited) start date ----
        if (empty($errors) && date('Y-m-d') < $new_reserved_from) {
            $errors[] = 'This reservation starts on ' . date('d M Y', strtotime($new_reserved_from)) . '. Check-in isn\'t allowed before that date.';
        }

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
            // Re-check availability at the moment of check-in (safety net), using whichever
            // dates are actually about to be saved — the edited ones if changed, otherwise
            // the booking's original dates.
            $conflict = is_room_available($conn, $booking['room_id'], $new_reserved_from, $new_reserved_until, $booking_id);
            if ($conflict !== false) {
                $errors[] = 'This room now conflicts with another booking. Please review reservations.';
            }
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "UPDATE hotel_bookings SET
                status = 'checked_in', checkin_at = NOW(), checkin_by = ?, tax = ?, discount = ?, total_amount = ?,
                reserved_from = ?, reserved_until = ?, reserved_nights = ?, room_charge_total = ?
                WHERE id = ?");
            mysqli_stmt_bind_param(
                $stmt, 'idddssidi',
                $user['id'], $tax, $discount, $total_amount,
                $new_reserved_from, $new_reserved_until, $new_nights, $room_charge_total,
                $booking_id
            );
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

    /* ---------- Calendar date picker (same component as hotel_reservations.php) ---------- */
    .rescal-label { font-weight: 700; font-size: 0.85rem; color: #1c3d2e; margin-bottom: 8px; display: block; }

    .rescal-card {
        border: 1px solid #dfe3e1; border-radius: 10px; background: #fff;
        padding: 10px 12px 12px;
    }
    .rescal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .rescal-month-label {
        flex: 1 1 auto; text-align: center; font-weight: 700; font-size: 0.85rem; color: #1c3d2e;
    }
    .rescal-nav-btn {
        width: 24px; height: 24px; border: none; background: transparent; border-radius: 6px;
        display: flex; align-items: center; justify-content: center; color: #6c776f;
        cursor: pointer; transition: background .12s ease, color .12s ease; flex-shrink: 0;
    }
    .rescal-nav-btn:hover { background: #f0f2f1; color: #1c3d2e; }
    .rescal-nav-btn:disabled { opacity: 0.3; cursor: not-allowed; }
    .rescal-nav-btn:disabled:hover { background: transparent; color: #6c776f; }

    .rescal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); margin-bottom: 2px; }
    .rescal-weekdays span { text-align: center; font-size: 0.62rem; font-weight: 700; color: #9aa39d; padding: 2px 0; }

    .rescal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    .rescal-day {
        position: relative; height: 30px; display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem; font-weight: 600; color: #2b3a33; border-radius: 6px;
        cursor: pointer; user-select: none; background: #f7f8f7; transition: background .1s ease, color .1s ease;
    }
    .rescal-day.rescal-empty { visibility: hidden; cursor: default; }
    .rescal-day:not(.rescal-disabled):hover { background: #dcecdf; }

    .rescal-day.rescal-today { box-shadow: inset 0 0 0 1.5px #0f5132; }

    .rescal-day.rescal-past, .rescal-day.rescal-blocked { color: #c9cfcb; background: #f4f5f4; cursor: not-allowed; }
    .rescal-day.rescal-past:hover, .rescal-day.rescal-blocked:hover { background: #f4f5f4; }

    .rescal-day.rescal-available { background: #c7ecd0; color: #1c5c33; }
    .rescal-day.rescal-available:hover { background: #b3e3bf; }

    .rescal-day.rescal-booked { background: #f6b9b4; color: #7d241a; cursor: not-allowed; }
    .rescal-day.rescal-booked:hover { background: #f6b9b4; }
    .rescal-day.rescal-booked:not(.rescal-disabled) { cursor: pointer; }
    .rescal-day.rescal-booked:not(.rescal-disabled):hover { background: #f2a29c; }

    .rescal-day.rescal-selected { background: #0f5132; color: #fff; }
    .rescal-day.rescal-selected:hover { background: #0f5132; }

    .rescal-day.rescal-in-range { background: #0f5132; color: #fff; }
    .rescal-day.rescal-in-range:hover { background: #0f5132; }

    .rescal-summary {
        border: 1px solid #e6e9e7; border-radius: 8px; background: #fafbfa;
        padding: 6px 10px; display: flex; align-items: center; gap: 6px;
        font-size: 0.76rem; color: #6c776f; margin-top: 8px;
    }
    .rescal-summary i { color: #6c776f; font-size: 0.8rem; }
    .rescal-summary .rc-value { font-weight: 700; color: #1c3d2e; }
    .rescal-summary .rc-warn { color: #b3261e; font-weight: 700; }

    .rescal-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
    .rescal-legend { display: flex; gap: 10px; font-size: 0.64rem; color: #6c776f; flex-wrap: wrap; }
    .rescal-legend span { display: inline-flex; align-items: center; gap: 4px; }
    .rescal-legend i { width: 8px; height: 8px; border-radius: 3px; display: inline-block; }
    .rescal-legend .lg-available { background: #c7ecd0; }
    .rescal-legend .lg-booked { background: #f6b9b4; }
    .rescal-legend .lg-selected { background: #0f5132; }
    .rescal-clear-btn {
        border: none; background: none; font-size: 0.68rem; font-weight: 700; color: #6c776f; cursor: pointer;
    }
    .rescal-clear-btn:hover { color: #b3261e; }
    .rescal-hint { font-size: 0.68rem; color: #9aa39d; text-align: center; padding: 6px 0 0; }
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
                    <!-- LEFT sub-column: all form fields -->
                    <div class="col-lg-6">
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
                                <label class="form-label small fw-semibold">Reserved From</label>
                                <input type="text" class="form-control" id="reserved_from_display" value="<?= e(date('d M Y', strtotime($booking['reserved_from']))) ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Reserved Until</label>
                                <input type="text" class="form-control" id="reserved_until_display" value="<?= e(date('d M Y', strtotime($booking['reserved_until']))) ?>" disabled>
                            </div>
                            <input type="hidden" name="reserved_from" id="reserved_from" value="<?= e($booking['reserved_from']) ?>">
                            <input type="hidden" name="reserved_until" id="reserved_until" value="<?= e($booking['reserved_until']) ?>">
                            <div class="col-12">
                                <div id="dateChangeNote"></div>
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
                    </div>

                    <!-- RIGHT sub-column: calendar picker -->
                    <div class="col-lg-6">
                        <label class="rescal-label">Reservation Dates <span class="text-danger">*</span></label>

                        <div class="rescal-card">
                            <div class="rescal-header">
                                <button type="button" class="rescal-nav-btn" id="calPrev"><i class="bi bi-chevron-left"></i></button>
                                <span class="rescal-month-label" id="calMonthLabel"></span>
                                <button type="button" class="rescal-nav-btn" id="calNext"><i class="bi bi-chevron-right"></i></button>
                            </div>
                            <div class="rescal-weekdays">
                                <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                            </div>
                            <div class="rescal-grid" id="calGrid"></div>
                            <div class="rescal-footer">
                                <div class="rescal-legend">
                                    <span><i class="lg-available"></i>Available</span>
                                    <span><i class="lg-booked"></i>Reserved</span>
                                    <span><i class="lg-selected"></i>Selected</span>
                                </div>
                                <button type="button" class="rescal-clear-btn" id="calClear">Reset</button>
                            </div>
                        </div>
                        <div class="rescal-summary">
                            <i class="bi bi-calendar3"></i>
                            <span id="calSummaryText">Select stay dates</span>
                        </div>
                        <div class="rescal-hint">Defaults to this booking's current dates. Pick new dates to change them.</div>
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
const pricePerDay = <?= $booking['room_charge_per_day'] ?>;
const todayStr = '<?= date('Y-m-d') ?>';
const originalFrom = '<?= e($booking['reserved_from']) ?>';
const originalUntil = '<?= e($booking['reserved_until']) ?>';
let roomCharge = <?= $room_charge_total ?>;
let datesValid = true;

// ---------- Calendar (same component as hotel_reservations.php) ----------
// Defaults to this booking's current reserved_from/reserved_until; picking a new range
// here just changes the hidden reserved_from/reserved_until inputs the form already posts,
// same as the old plain date inputs did. Nothing about the POST handling changed.
const cal = {
    grid: document.getElementById('calGrid'),
    monthLabel: document.getElementById('calMonthLabel'),
    prevBtn: document.getElementById('calPrev'),
    nextBtn: document.getElementById('calNext'),
    clearBtn: document.getElementById('calClear'),
    summaryText: document.getElementById('calSummaryText'),
    fromInput: document.getElementById('reserved_from'),
    untilInput: document.getElementById('reserved_until'),
    fromDisplay: document.getElementById('reserved_from_display'),
    untilDisplay: document.getElementById('reserved_until_display'),

    viewYear: 0,
    viewMonth: 0, // 0-11
    todayStr: todayStr,
    bookedSet: new Set(),   // 'YYYY-MM-DD' strings occupied by other bookings for this room
    selFrom: originalFrom,
    selUntil: originalUntil,
    pickingFrom: true,
    warnTimeout: null,
};

function calPad(n) { return n < 10 ? '0' + n : '' + n; }
function calFmt(y, m, d) { return `${y}-${calPad(m + 1)}-${calPad(d)}`; }
function calMonthName(m) {
    return ['January','February','March','April','May','June','July','August','September','October','November','December'][m];
}
function formatDisplayDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function calInit() {
    const start = new Date(originalFrom + 'T00:00:00');
    cal.viewYear = start.getFullYear();
    cal.viewMonth = start.getMonth();

    cal.prevBtn.addEventListener('click', () => calChangeMonth(-1));
    cal.nextBtn.addEventListener('click', () => calChangeMonth(1));
    cal.clearBtn.addEventListener('click', () => calResetSelection());

    calRender();
    calLoadBookedDates();
}

function calChangeMonth(delta) {
    cal.viewMonth += delta;
    if (cal.viewMonth < 0) { cal.viewMonth = 11; cal.viewYear--; }
    if (cal.viewMonth > 11) { cal.viewMonth = 0; cal.viewYear++; }
    calRender();
}

// Rebuild the set of booked (unavailable) date strings from the fetched ranges.
// A stay occupies nights from `from` up to (but not including) `until`.
function calRebuildBookedSet(ranges) {
    cal.bookedSet = new Set();
    (ranges || []).forEach(r => {
        let cur = new Date(r.from + 'T00:00:00');
        const end = new Date(r.until + 'T00:00:00');
        while (cur < end) {
            cal.bookedSet.add(calFmt(cur.getFullYear(), cur.getMonth(), cur.getDate()));
            cur.setDate(cur.getDate() + 1);
        }
    });
}

// Does the half-open range [fromStr, toStrExclusive) overlap any booked date?
function calRangeHasConflict(fromStr, toStrExclusive) {
    let cur = new Date(fromStr + 'T00:00:00');
    const end = new Date(toStrExclusive + 'T00:00:00');
    while (cur < end) {
        if (cal.bookedSet.has(calFmt(cur.getFullYear(), cur.getMonth(), cur.getDate()))) return true;
        cur.setDate(cur.getDate() + 1);
    }
    return false;
}

function calMonFirstCol(jsDay) { return (jsDay + 6) % 7; }

function calRender() {
    cal.monthLabel.innerText = calMonthName(cal.viewMonth) + ' ' + cal.viewYear;

    const now = new Date();
    cal.prevBtn.disabled = (cal.viewYear === now.getFullYear() && cal.viewMonth === now.getMonth());

    cal.grid.innerHTML = '';
    const firstDay = calMonFirstCol(new Date(cal.viewYear, cal.viewMonth, 1).getDay());
    const daysInMonth = new Date(cal.viewYear, cal.viewMonth + 1, 0).getDate();

    for (let i = 0; i < firstDay; i++) {
        const empty = document.createElement('div');
        empty.className = 'rescal-day rescal-empty';
        cal.grid.appendChild(empty);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = calFmt(cal.viewYear, cal.viewMonth, d);
        const dayEl = document.createElement('div');
        dayEl.className = 'rescal-day';
        dayEl.innerText = d;

        const isPast = dateStr < cal.todayStr;
        const isToday = dateStr === cal.todayStr;
        const isBooked = cal.bookedSet.has(dateStr);
        const isSelected = (dateStr === cal.selFrom) || (dateStr === cal.selUntil);
        const inRange = cal.selFrom && cal.selUntil && dateStr > cal.selFrom && dateStr < cal.selUntil;

        // Same "booked date usable as a checkout boundary" rule as the reservation page:
        // a booked date can be picked as the "until" end of a new range, just not as "from".
        const isPickingUntilNow = !!cal.selFrom && dateStr > cal.selFrom;
        const canClickDespiteBooked = isBooked && isPickingUntilNow;

        if (isToday) dayEl.classList.add('rescal-today');
        if (isPast) dayEl.classList.add('rescal-past');
        else if (isBooked) dayEl.classList.add('rescal-booked');
        else dayEl.classList.add('rescal-available');

        if ((isPast || isBooked) && !canClickDespiteBooked) dayEl.classList.add('rescal-disabled');
        if (isSelected) dayEl.classList.add('rescal-selected');
        else if (inRange) dayEl.classList.add('rescal-in-range');

        if (!isPast && (!isBooked || canClickDespiteBooked)) {
            dayEl.addEventListener('click', () => calHandleDayClick(dateStr));
        }

        cal.grid.appendChild(dayEl);
    }
}

function calHandleDayClick(dateStr) {
    // Anchor-and-extend selection: after the first click, the range only ever grows.
    // A click before the current start pulls 'from' back to it; a click after the current
    // start pushes 'until' out to it. Clicking the exact start date again restarts a fresh
    // selection. A conflicting range shows a warning and restarts from the clicked date.
    if (!cal.selFrom) {
        if (cal.bookedSet.has(dateStr)) {
            calShowWarning('That date is already reserved — pick another check-in date.');
            return;
        }
        cal.selFrom = dateStr;
        cal.selUntil = null;
    } else if (dateStr === cal.selFrom) {
        cal.selUntil = null;
    } else if (dateStr < cal.selFrom) {
        if (cal.bookedSet.has(dateStr)) {
            calShowWarning('That date is already reserved — pick another check-in date.');
            return;
        }
        const newUntil = cal.selUntil || cal.selFrom;
        if (calRangeHasConflict(dateStr, newUntil)) {
            cal.selFrom = dateStr;
            cal.selUntil = null;
            calShowWarning('That range includes reserved dates — pick a new range.');
            return;
        }
        cal.selFrom = dateStr;
        cal.selUntil = newUntil;
    } else if (calRangeHasConflict(cal.selFrom, dateStr)) {
        cal.selFrom = dateStr;
        cal.selUntil = null;
        calShowWarning('That range includes reserved dates — pick a new range.');
    } else {
        cal.selUntil = dateStr;
    }
    calApplySelection();
    calRender();
}

// "Reset" restores the booking's original dates rather than clearing to empty — check-in
// always needs a from/until, so an empty selection isn't a valid resting state here.
function calResetSelection() {
    cal.selFrom = originalFrom;
    cal.selUntil = originalUntil;
    cal.pickingFrom = true;
    calApplySelection();
    calRender();
}

function calShowWarning(msg) {
    clearTimeout(cal.warnTimeout);
    cal.summaryText.innerHTML = `<span class="rc-warn"><i class="bi bi-exclamation-triangle me-1"></i>${msg}</span>`;
    cal.warnTimeout = setTimeout(() => calApplySelection(), 3000);
}

function calApplySelection() {
    cal.fromInput.value = cal.selFrom || '';
    cal.untilInput.value = cal.selUntil || '';
    cal.fromDisplay.value = cal.selFrom ? formatDisplayDate(cal.selFrom) : '—';
    cal.untilDisplay.value = cal.selUntil ? formatDisplayDate(cal.selUntil) : '—';

    if (cal.selFrom && cal.selUntil) {
        cal.summaryText.innerHTML = `<span class="rc-value">${formatDisplayDate(cal.selFrom)}</span> &rarr; <span class="rc-value">${formatDisplayDate(cal.selUntil)}</span>`;
    } else if (cal.selFrom) {
        cal.summaryText.innerHTML = `<span class="rc-value">${formatDisplayDate(cal.selFrom)}</span> &rarr; select until date`;
    } else {
        cal.summaryText.innerText = 'Select stay dates';
    }

    // Reuses the existing onDatesChange() hook untouched — it still drives the nights/charge
    // recompute, the early-check-in warning, and validatePayment() exactly as before.
    onDatesChange();
}

// Fetch this room's other booked date ranges (excluding this booking's own row) and
// refresh the calendar's shading. Purely additive; is_room_available() on submit remains
// the authoritative check.
function calLoadBookedDates() {
    fetch(`hotel_reservations_checkin.php?booking_id=<?= $booking_id ?>&ajax=room_booked_dates`)
        .then(r => r.json())
        .then(data => {
            calRebuildBookedSet(Array.isArray(data) ? data : []);
            if (cal.selFrom && cal.selUntil && calRangeHasConflict(cal.selFrom, cal.selUntil)) {
                // The booking's own current range never conflicts with itself (excluded
                // server-side), so this only fires if another booking appeared since load.
                calShowWarning('Current dates are no longer available — pick again.');
            } else {
                calRender();
            }
        })
        .catch(() => { /* fail quietly; calendar just won't show booked dates */ });
}

function onDatesChange() {
    const from = document.getElementById('reserved_from').value;
    const until = document.getElementById('reserved_until').value;
    const note = document.getElementById('dateChangeNote');

    if (!from || !until) {
        note.innerHTML = '';
        datesValid = false;
        validatePayment();
        return;
    }

    // Mirrors the server-side rule: check-in cannot happen before the reservation's start date.
    if (from < todayStr) {
        note.innerHTML = '<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>Check-in isn\'t allowed before the reservation start date.</div>';
        datesValid = false;
        validatePayment();
        return;
    }

    if (until <= from) {
        note.innerHTML = '<div class="alert alert-warning py-2 small mb-0">Reserved Until must be after Reserved From.</div>';
        datesValid = false;
        validatePayment();
        return;
    }

    // Nights/charge recompute client-side for a live preview; the server re-validates
    // availability and recalculates the same way as the authoritative source of truth.
    const d1 = new Date(from), d2 = new Date(until);
    const nights = Math.max(0, Math.round((d2 - d1) / (1000*60*60*24)));
    roomCharge = nights * pricePerDay;
    document.getElementById('disp_room_charge').innerText = '৳' + roomCharge.toFixed(2);
    document.getElementById('discount').max = roomCharge;

    note.innerHTML = '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>' + nights + ' night(s) · ৳' + roomCharge.toFixed(2) + ' room charge. Availability is re-checked when you submit.</div>';
    datesValid = true;
    recalc();
}

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
    if (!datesValid) {
        errBox.classList.add('d-none');
        btn.disabled = true;
    } else if (payment > total) {
        errBox.classList.remove('d-none');
        btn.disabled = true;
    } else {
        errBox.classList.add('d-none');
        btn.disabled = false;
    }
}

calInit();
recalc();
</script>

<?php require __DIR__ . '/footer.php'; ?>