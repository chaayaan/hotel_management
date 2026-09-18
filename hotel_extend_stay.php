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

// ---------- AJAX: booked date ranges for this room (for the calendar picker) ----------
// Purely additive read endpoint for the calendar UI, same pattern as hotel_reservations.php.
// Does not touch or replace is_room_available()/check_extend, which remain the source of
// truth on submit. Excludes this booking's own row so its own existing stay doesn't show
// as "booked" on its own extend calendar.
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
                        <label class="rescal-label">New Checkout Date <span class="text-danger">*</span></label>
                        <input type="hidden" name="new_reserved_until" id="new_reserved_until" required>

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
                                <button type="button" class="rescal-clear-btn" id="calClear">Clear</button>
                            </div>
                        </div>
                        <div class="rescal-summary">
                            <i class="bi bi-calendar3"></i>
                            <span id="calSummaryText">Select new checkout date</span>
                        </div>
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

// ---------- Calendar (single-date picker for the new checkout date) ----------
// Adapted from the reservation page's calendar component: here there's no "from" to pick
// (the stay's start is fixed), so this calendar only ever picks one date — the new
// reserved_until. Booked-date shading uses the same room_booked_dates AJAX endpoint,
// scoped to this room and excluding this booking's own existing reservation row.
const cal = {
    grid: document.getElementById('calGrid'),
    monthLabel: document.getElementById('calMonthLabel'),
    prevBtn: document.getElementById('calPrev'),
    nextBtn: document.getElementById('calNext'),
    clearBtn: document.getElementById('calClear'),
    summaryText: document.getElementById('calSummaryText'),
    untilInput: document.getElementById('new_reserved_until'),

    viewYear: 0,
    viewMonth: 0, // 0-11
    todayStr: '',
    bookedSet: new Set(),  // 'YYYY-MM-DD' strings occupied by other bookings for this room
    selUntil: null,
    warnTimeout: null,
};

function calPad(n) { return n < 10 ? '0' + n : '' + n; }
function calFmt(y, m, d) { return `${y}-${calPad(m + 1)}-${calPad(d)}`; }
function calToday() { const t = new Date(); return calFmt(t.getFullYear(), t.getMonth(), t.getDate()); }
function calMonthName(m) {
    return ['January','February','March','April','May','June','July','August','September','October','November','December'][m];
}
function formatDisplayDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function calInit() {
    // Start the view on the month of the current reserved_until, since every selectable
    // date is on or after that date.
    const start = new Date(currentUntil + 'T00:00:00');
    cal.viewYear = start.getFullYear();
    cal.viewMonth = start.getMonth();
    cal.todayStr = calToday();

    cal.prevBtn.addEventListener('click', () => calChangeMonth(-1));
    cal.nextBtn.addEventListener('click', () => calChangeMonth(1));
    cal.clearBtn.addEventListener('click', () => calClearSelection());

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

// Does the half-open range [currentUntil, toStrExclusive) overlap any booked date?
// Mirrors the server's is_room_available() window: only the newly-added nights matter.
function calRangeHasConflict(toStrExclusive) {
    let cur = new Date(currentUntil + 'T00:00:00');
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

    // Can't navigate before the month containing the current reserved_until — every
    // selectable date is on or after that date.
    const floor = new Date(currentUntil + 'T00:00:00');
    cal.prevBtn.disabled = (cal.viewYear === floor.getFullYear() && cal.viewMonth === floor.getMonth());

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

        const isToday = dateStr === cal.todayStr;
        // Only dates strictly after the current reserved_until are ever selectable —
        // same rule as the old min="...+1 day" date input.
        const isTooEarly = dateStr <= currentUntil;
        const isBooked = cal.bookedSet.has(dateStr);
        const isSelected = dateStr === cal.selUntil;
        // Shade the newly-added nights too (currentUntil, selUntil), so the whole extension
        // reads as one solid block rather than just the single end date standing out.
        const inRange = cal.selUntil && dateStr > currentUntil && dateStr < cal.selUntil;

        if (isToday) dayEl.classList.add('rescal-today');
        if (isTooEarly) dayEl.classList.add('rescal-past');
        else if (isBooked) dayEl.classList.add('rescal-booked');
        else dayEl.classList.add('rescal-available');

        if (isTooEarly || isBooked) dayEl.classList.add('rescal-disabled');
        if (isSelected) dayEl.classList.add('rescal-selected');
        else if (inRange) dayEl.classList.add('rescal-in-range');

        if (!isTooEarly && !isBooked) {
            dayEl.addEventListener('click', () => calHandleDayClick(dateStr));
        }

        cal.grid.appendChild(dayEl);
    }
}

function calHandleDayClick(dateStr) {
    // A booked date can't be picked as the new checkout date directly; the intervening-range
    // check below also catches booked dates that fall strictly between currentUntil and the
    // clicked date, matching the server's is_room_available() window exactly.
    if (calRangeHasConflict(dateStr)) {
        calShowWarning('That range includes reserved dates — pick another date.');
        return;
    }
    cal.selUntil = dateStr;
    calApplySelection();
    calRender();
}

function calClearSelection() {
    cal.selUntil = null;
    calApplySelection();
    calRender();
}

function calShowWarning(msg) {
    clearTimeout(cal.warnTimeout);
    cal.summaryText.innerHTML = `<span class="rc-warn"><i class="bi bi-exclamation-triangle me-1"></i>${msg}</span>`;
    cal.warnTimeout = setTimeout(() => calApplySelection(), 3000);
}

function calApplySelection() {
    cal.untilInput.value = cal.selUntil || '';

    if (cal.selUntil) {
        cal.summaryText.innerHTML = `<span class="rc-value">${formatDisplayDate(cal.selUntil)}</span>`;
    } else {
        cal.summaryText.innerText = 'Select new checkout date';
    }

    // Reuses the existing check-extend flow untouched — same AJAX call, same button-disable
    // pattern as before, just triggered from the calendar instead of a date input.
    onDateChange();
}

// Fetch the booked date ranges for this room (excluding this booking's own row) and
// refresh the calendar's shading. Purely additive; is_room_available() via check_extend
// remains the authoritative check both here (AJAX) and again on POST.
function calLoadBookedDates() {
    fetch(`hotel_extend_stay.php?booking_id=<?= $booking_id ?>&ajax=room_booked_dates`)
        .then(r => r.json())
        .then(data => {
            calRebuildBookedSet(Array.isArray(data) ? data : []);
            if (cal.selUntil && calRangeHasConflict(cal.selUntil)) {
                calClearSelection();
                calShowWarning('That date is no longer available — pick again.');
            } else {
                calRender();
            }
        })
        .catch(() => { /* fail quietly; calendar just won't show booked dates */ });
}

calInit();

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