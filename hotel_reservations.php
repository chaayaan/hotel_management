<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$errors = [];
$preselect_room_id = (int)($_GET['room_id'] ?? 0);

// ---------- Handle AJAX: guest search ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'guest_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $out = [];
    if (strlen($q) >= 2) {
        $qEsc = mysqli_real_escape_string($conn, $q);
        $res = mysqli_query($conn, "SELECT id, full_name, phone, email FROM guests
                                     WHERE full_name LIKE '%{$qEsc}%' OR phone LIKE '%{$qEsc}%'
                                     ORDER BY full_name ASC LIMIT 10");
        while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    }
    echo json_encode($out);
    exit;
}

// ---------- Handle AJAX: room availability check ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_availability') {
    header('Content-Type: application/json');
    $room_id = (int)($_GET['room_id'] ?? 0);
    $from = $_GET['from'] ?? '';
    $until = $_GET['until'] ?? '';
    if ($room_id && $from && $until) {
        $conflict = is_room_available($conn, $room_id, $from, $until);
        echo json_encode(['available' => $conflict === false, 'conflict' => $conflict ?: null]);
    } else {
        echo json_encode(['available' => null]);
    }
    exit;
}

// ---------- Handle POST: create reservation ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_reservation') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_reservations.php');
        exit;
    }

    $room_id = (int)($_POST['room_id'] ?? 0);
    $guest_mode = $_POST['guest_mode'] ?? 'existing';
    $guest_id = (int)($_POST['guest_id'] ?? 0);
    $reserved_from = $_POST['reserved_from'] ?? '';
    $reserved_until = $_POST['reserved_until'] ?? '';
    $adults = max(1, (int)($_POST['adults'] ?? 1));
    $children = max(0, (int)($_POST['children'] ?? 0));
    $price_per_day = (float)($_POST['price_per_day'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    // New guest fields
    $new_name = trim($_POST['new_guest_name'] ?? '');
    $new_phone = trim($_POST['new_guest_phone'] ?? '');
    $new_email = trim($_POST['new_guest_email'] ?? '');
    $new_id_type = trim($_POST['new_guest_id_type'] ?? '');
    $new_id_number = trim($_POST['new_guest_id_number'] ?? '');
    $new_address = trim($_POST['new_guest_address'] ?? '');

    if ($room_id <= 0) $errors[] = 'Please select a room.';
    if ($reserved_from === '' || $reserved_until === '') {
        $errors[] = 'Please select both from and until dates.';
    } elseif (strtotime($reserved_until) <= strtotime($reserved_from)) {
        $errors[] = 'Reserved Until must be after Reserved From.';
    }

    if (empty($errors)) {
        if ($guest_mode === 'new') {
            if ($new_name === '' || $new_phone === '') {
                $errors[] = 'New guest name and phone are required.';
            }
        } else {
            if ($guest_id <= 0) $errors[] = 'Please select an existing guest.';
        }
    }

    if (empty($errors)) {
        $conflict = is_room_available($conn, $room_id, $reserved_from, $reserved_until);
        if ($conflict !== false) {
            $errors[] = "Room is not available for the selected dates. Conflicts with reservation {$conflict['reservation_no']} ({$conflict['reserved_from']} to {$conflict['reserved_until']}).";
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        $ok = true;
        $reservation_no = '';

        if ($guest_mode === 'new') {
            $stmt = mysqli_prepare($conn, "INSERT INTO guests (full_name, phone, email, id_proof_type, id_proof_number, address) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssss', $new_name, $new_phone, $new_email, $new_id_type, $new_id_number, $new_address);
            $ok = mysqli_stmt_execute($stmt);
            if ($ok) $guest_id = mysqli_insert_id($conn);
        }

        if ($ok) {
            $nights = calc_nights($reserved_from, $reserved_until);
            $room_charge_total = $nights * $price_per_day;
            $reservation_no = generate_reservation_no($conn);
            $reservation_date = date('Y-m-d');

            $stmt = mysqli_prepare($conn, "INSERT INTO hotel_bookings
                (reservation_no, room_id, guest_id, reservation_date, reservation_by, reserved_from, reserved_nights, reserved_until,
                 adults, children, status, room_charge_per_day, room_charge_total, total_amount, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reserved', ?, ?, ?, ?)");
            mysqli_stmt_bind_param(
                $stmt,
                'siisisisiiddds',
                $reservation_no, $room_id, $guest_id, $reservation_date, $user['id'],
                $reserved_from, $nights, $reserved_until, $adults, $children,
                $price_per_day, $room_charge_total, $room_charge_total, $notes
            );
            $ok = mysqli_stmt_execute($stmt);
            $new_booking_id = $ok ? mysqli_insert_id($conn) : 0;
        }

        if ($ok) {
            mysqli_commit($conn);
            flash_set('success', "Reservation {$reservation_no} created successfully. Proceed with check-in.");
            header('Location: hotel_reservations_checkin.php?booking_id=' . $new_booking_id);
            exit;
        } else {
            mysqli_rollback($conn);
            $errors[] = 'Failed to create reservation: ' . mysqli_error($conn);
        }
    }
}

// ---------- Fetch existing reservations (upper section) ----------
// This panel only ever shows bookings that are still in "reserved" status
// (not checked in, checked out, cancelled, or no-show).
$sql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, r.room_number
        FROM hotel_bookings b
        JOIN guests g ON g.id = b.guest_id
        JOIN rooms r ON r.id = b.room_id
        WHERE b.status = 'reserved'";
$sql .= " ORDER BY b.created_at DESC LIMIT 50";
$reservations = mysqli_query($conn, $sql);

// ---------- Fetch rooms for the form ----------
$rooms_result = mysqli_query($conn, "SELECT r.id, r.room_number, r.price_per_day, r.status, rt.name AS type_name
                                      FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                                      WHERE r.is_active = 1
                                      ORDER BY CAST(r.room_number AS UNSIGNED), r.room_number");
$rooms_arr = [];
while ($r = mysqli_fetch_assoc($rooms_result)) $rooms_arr[] = $r;

$page_title = 'Reservations';
$active_menu = 'reservations';
require __DIR__ . '/navbar.php';
?>

<style>
    .pos-panel { border-radius: 14px; background: #fff; border: 1px solid #e8ebe9; padding: 20px; height: 100%; }
    .pos-panel h6 { font-weight: 700; color: #1c3d2e; margin-bottom: 14px; }
    .info-line { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.87rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .guest-result-item { padding: 8px 12px; cursor: pointer; border-radius: 8px; }
    .guest-result-item:hover { background: #f4f6f5; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }

    .reservation-list-scroll { max-height: 720px; overflow-y: auto; }
    .reservation-row { padding: 14px 16px; border-bottom: 1px solid #eef0ef; }
    .reservation-row:hover { background: #fafbfa; }
    .reservation-row:last-child { border-bottom: none; }
    .reservation-row .min-w-0 { min-width: 0; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" id="reservationForm">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="create_reservation">
<input type="hidden" name="guest_id" id="guest_id" value="">
<input type="hidden" name="guest_mode" id="guest_mode" value="existing">

<div class="row g-3">
    <!-- LEFT: Existing Reservations -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <span><i class="bi bi-calendar-week me-1"></i> Existing Reservations</span>
                <span class="badge bg-warning-subtle text-warning-emphasis">Reserved only</span>
            </div>
            <div class="card-body p-0">
                <div class="reservation-list-scroll">
                <?php if (mysqli_num_rows($reservations) === 0): ?>
                    <div class="text-center text-muted py-5">No reservations found.</div>
                <?php else: while ($row = mysqli_fetch_assoc($reservations)):
                    $rbid = (int)$row['id'];
                ?>
                    <div class="reservation-row">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small text-truncate"><?= e($row['reservation_no']) ?></div>
                                <div class="text-muted small text-truncate"><?= e($row['guest_name']) ?> · <?= e($row['guest_phone']) ?></div>
                            </div>
                            <span class="badge <?= booking_status_badge($row['status']) ?> flex-shrink-0"><?= e(ucwords(str_replace('_',' ',$row['status']))) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2 small">
                            <span class="text-muted">Room <?= e($row['room_number']) ?> · <?= (int)$row['reserved_nights'] ?>N</span>
                            <span class="fw-semibold">৳<?= number_format((float)$row['total_amount'], 2) ?></span>
                        </div>
                        <div class="text-muted small mt-1"><?= e(date('d M Y', strtotime($row['reserved_from']))) ?> → <?= e(date('d M Y', strtotime($row['reserved_until']))) ?></div>
                        <div class="d-flex gap-1 flex-wrap mt-2">
                        <?php if ($row['status'] === 'reserved'): ?>
                            <a href="hotel_reservations_checkin.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-brand"><i class="bi bi-box-arrow-in-right"></i> Check In</a>
                            <a href="hotel_reservations_cancelled.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</a>
                        <?php elseif ($row['status'] === 'checked_in'): ?>
                            <a href="hotel_reservations_checkout.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-right"></i> Check Out</a>
                            <a href="hotel_receipt.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-receipt"></i> Receipt</a>
                        <?php elseif ($row['status'] === 'checked_out'): ?>
                            <a href="hotel_receipt.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-receipt"></i> Receipt</a>
                        <?php else: ?>
                            <a href="hotel_receipt.php?booking_id=<?= $rbid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a>
                        <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Guest selection + New Reservation form, combined -->
    <div class="col-lg-7">
        <div class="pos-panel">
            <h6><i class="bi bi-calendar-plus me-1"></i>New Reservation</h6>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Guest</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" id="guestSearchInput" class="form-control" placeholder="Search guest by name or phone...">
                    <button type="button" class="btn btn-outline-brand" id="newGuestBtn">+ New Guest</button>
                </div>
                <div id="guestSearchResults" class="border rounded" style="display:none; max-height:180px; overflow-y:auto; position:relative; z-index:5; background:#fff;"></div>

                <div id="selectedGuestBox" class="d-none border rounded p-2 mt-2" style="background:#f4f6f5;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="small text-muted fw-semibold mb-1"><i class="bi bi-check-circle-fill text-success me-1"></i>Selected Guest</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="clearGuestSelection()" title="Remove selected guest">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="info-line"><span class="label">Name</span><span class="value" id="sg_name"></span></div>
                    <div class="info-line"><span class="label">Phone</span><span class="value" id="sg_phone"></span></div>
                    <div class="info-line" style="border-bottom:none;"><span class="label">Email</span><span class="value" id="sg_email"></span></div>
                </div>

                <div id="newGuestBox" class="d-none border rounded p-2 mt-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="small text-muted fw-semibold"><i class="bi bi-person-plus me-1"></i>New Guest Details</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cancelNewGuest()" title="Cancel">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="row g-2">
                        <div class="col-12">
                            <input type="text" name="new_guest_name" id="new_guest_name" class="form-control form-control-sm" placeholder="Full Name *">
                        </div>
                        <div class="col-6">
                            <input type="text" name="new_guest_phone" id="new_guest_phone" class="form-control form-control-sm" placeholder="Phone *">
                        </div>
                        <div class="col-6">
                            <input type="email" name="new_guest_email" class="form-control form-control-sm" placeholder="Email">
                        </div>
                        <div class="col-6">
                            <input type="text" name="new_guest_id_type" class="form-control form-control-sm" placeholder="ID Type (NID/Passport)">
                        </div>
                        <div class="col-6">
                            <input type="text" name="new_guest_id_number" class="form-control form-control-sm" placeholder="ID Number">
                        </div>
                        <div class="col-12">
                            <input type="text" name="new_guest_address" class="form-control form-control-sm" placeholder="Address">
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Room <span class="text-danger">*</span></label>
                    <select name="room_id" id="room_id" class="form-select" required onchange="onRoomChange()">
                        <option value="">-- Select Room --</option>
                        <?php foreach ($rooms_arr as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"
                                data-price="<?= (float)$r['price_per_day'] ?>"
                                data-type="<?= e($r['type_name']) ?>"
                                data-number="<?= e($r['room_number']) ?>"
                                <?= $preselect_room_id === (int)$r['id'] ? 'selected' : '' ?>>
                                <?= e($r['room_number']) ?> — <?= e($r['type_name']) ?> (৳<?= number_format((float)$r['price_per_day'],0) ?>/night)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Adults</label>
                    <input type="number" name="adults" id="adults" class="form-control" value="1" min="1" onchange="updateSummary()">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Children</label>
                    <input type="number" name="children" id="children" class="form-control" value="0" min="0" onchange="updateSummary()">
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Reserved From <span class="text-danger">*</span></label>
                    <input type="date" name="reserved_from" id="reserved_from" class="form-control" required min="<?= date('Y-m-d') ?>" onchange="onDatesChange()">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Reserved Until <span class="text-danger">*</span></label>
                    <input type="date" name="reserved_until" id="reserved_until" class="form-control" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" onchange="onDatesChange()">
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" maxlength="255"></textarea>
                </div>
            </div>

            <div id="summaryBox" class="mt-3">
                <div class="info-line"><span class="label">Room</span><span class="value" id="sum_room">—</span></div>
                <div class="info-line"><span class="label">Guest</span><span class="value" id="sum_guest">—</span></div>
                <div class="info-line"><span class="label">Adult/Child</span><span class="value" id="sum_occupancy">—</span></div>
                <div class="info-line"><span class="label">Reserved For</span><span class="value" id="sum_dates">—</span></div>
            </div>

            <div id="availabilityNote" class="mt-2"></div>

            <input type="hidden" name="price_per_day" id="price_per_day" value="0">

            <div class="total-box">
                <div class="d-flex justify-content-between small text-muted">
                    <span>Room Cost / Night</span><span id="calc_price">৳0.00</span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Nights</span><span id="calc_nights">0</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="fw-semibold">Total</span>
                    <span class="grand" id="calc_total">৳0.00</span>
                </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 mt-3 py-2 fw-semibold" id="reserveBtn" disabled>
                <i class="bi bi-check-circle me-1"></i> Reserve
            </button>
        </div>
    </div>
</div>
</form>



<script>
let selectedGuestId = null;
let isRoomAvailable = null;

const guestInput = document.getElementById('guestSearchInput');
const resultsBox = document.getElementById('guestSearchResults');
let searchTimeout;

guestInput.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    const q = guestInput.value.trim();

    // Typing a search means they intend to pick an existing guest — close the new-guest panel if open.
    if (q.length > 0 && !document.getElementById('newGuestBox').classList.contains('d-none')) {
        document.getElementById('newGuestBox').classList.add('d-none');
        document.getElementById('guest_mode').value = 'existing';
    }

    if (q.length < 2) { resultsBox.style.display = 'none'; return; }
    searchTimeout = setTimeout(() => {
        fetch('hotel_reservations.php?ajax=guest_search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                resultsBox.innerHTML = '';
                if (data.length === 0) {
                    resultsBox.innerHTML = '<div class="p-2 text-muted small">No guests found.</div>';
                } else {
                    data.forEach(g => {
                        const div = document.createElement('div');
                        div.className = 'guest-result-item';
                        div.innerHTML = `<div class="fw-semibold small">${g.full_name}</div><div class="text-muted small">${g.phone}</div>`;
                        div.onclick = () => selectGuest(g);
                        resultsBox.appendChild(div);
                    });
                }
                resultsBox.style.display = 'block';
            });
    }, 300);
});

function selectGuest(g) {
    selectedGuestId = g.id;
    document.getElementById('guest_id').value = g.id;
    document.getElementById('guest_mode').value = 'existing';
    document.getElementById('sg_name').innerText = g.full_name;
    document.getElementById('sg_phone').innerText = g.phone;
    document.getElementById('sg_email').innerText = g.email || '—';
    document.getElementById('selectedGuestBox').classList.remove('d-none');

    // Closing the "new guest" panel (if it was open) since an existing guest was just picked,
    // but keeping the search input and results visible so another guest can be picked right away.
    document.getElementById('newGuestBox').classList.add('d-none');
    guestInput.value = '';
    resultsBox.style.display = 'none';
    validateForm();
    updateSummary();
}

function clearGuestSelection() {
    selectedGuestId = null;
    document.getElementById('guest_id').value = '';
    document.getElementById('guest_mode').value = 'existing';
    document.getElementById('selectedGuestBox').classList.add('d-none');
    guestInput.value = '';
    guestInput.focus();
    validateForm();
    updateSummary();
}

function cancelNewGuest() {
    document.getElementById('guest_mode').value = 'existing';
    document.getElementById('newGuestBox').classList.add('d-none');
    document.querySelectorAll('#newGuestBox input').forEach(el => el.value = '');
    validateForm();
}

document.getElementById('newGuestBtn').addEventListener('click', () => {
    document.getElementById('guest_mode').value = 'new';
    document.getElementById('newGuestBox').classList.remove('d-none');

    // A reservation is for one guest, so picking "new guest" clears any existing selection —
    // but the search box itself stays visible/usable in case they change their mind.
    selectedGuestId = null;
    document.getElementById('guest_id').value = '';
    document.getElementById('selectedGuestBox').classList.add('d-none');
    resultsBox.style.display = 'none';
    document.getElementById('new_guest_name').focus();
    validateForm();
});

function onRoomChange() {
    const sel = document.getElementById('room_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('sum_room').innerText = opt.dataset.number + ' / ' + opt.dataset.type + ' / ৳' + parseFloat(opt.dataset.price).toFixed(0);
        document.getElementById('price_per_day').value = opt.dataset.price;
        document.getElementById('calc_price').innerText = '৳' + parseFloat(opt.dataset.price).toFixed(2);
    } else {
        document.getElementById('sum_room').innerText = '—';
        document.getElementById('price_per_day').value = 0;
        document.getElementById('calc_price').innerText = '৳0.00';
    }
    onDatesChange();
}

function onDatesChange() {
    calcTotal();
    checkAvailability();
    updateSummary();
}

function formatDisplayDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function updateSummary() {
    const guestName = document.getElementById('sg_name') ? document.getElementById('sg_name').innerText : '';
    document.getElementById('sum_guest').innerText = (selectedGuestId && guestName) ? guestName
        : (document.getElementById('new_guest_name') && document.getElementById('new_guest_name').value)
            ? document.getElementById('new_guest_name').value : '—';

    const adults = document.getElementById('adults').value || 0;
    const children = document.getElementById('children').value || 0;
    document.getElementById('sum_occupancy').innerText = adults + ' Adult' + (adults == 1 ? '' : 's') + ' / ' + children + ' Child' + (children == 1 ? '' : 'ren');

    const from = document.getElementById('reserved_from').value;
    const until = document.getElementById('reserved_until').value;
    document.getElementById('sum_dates').innerText = (from && until)
        ? (formatDisplayDate(from) + ' to ' + formatDisplayDate(until)) : '—';
}

function calcTotal() {
    const from = document.getElementById('reserved_from').value;
    const until = document.getElementById('reserved_until').value;
    const price = parseFloat(document.getElementById('price_per_day').value) || 0;
    let nights = 0;
    if (from && until) {
        const d1 = new Date(from), d2 = new Date(until);
        nights = Math.max(0, Math.round((d2 - d1) / (1000*60*60*24)));
    }
    document.getElementById('calc_nights').innerText = nights;
    document.getElementById('calc_total').innerText = '৳' + (nights * price).toFixed(2);
}

function checkAvailability() {
    const roomId = document.getElementById('room_id').value;
    const from = document.getElementById('reserved_from').value;
    const until = document.getElementById('reserved_until').value;
    const note = document.getElementById('availabilityNote');

    if (!roomId || !from || !until) { note.innerHTML = ''; isRoomAvailable = null; validateForm(); return; }
    if (new Date(until) <= new Date(from)) {
        note.innerHTML = '<div class="alert alert-warning py-2 small mb-0">Until date must be after From date.</div>';
        isRoomAvailable = false; validateForm(); return;
    }

    fetch(`hotel_reservations.php?ajax=check_availability&room_id=${roomId}&from=${from}&until=${until}`)
        .then(r => r.json())
        .then(data => {
            if (data.available) {
                note.innerHTML = '<div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>Room is available for these dates.</div>';
                isRoomAvailable = true;
            } else {
                const c = data.conflict;
                note.innerHTML = `<div class="alert alert-danger py-2 small mb-0"><i class="bi bi-x-circle me-1"></i>Conflicts with ${c ? c.reservation_no : 'an existing booking'} (${c ? c.reserved_from : ''} to ${c ? c.reserved_until : ''}).</div>`;
                isRoomAvailable = false;
            }
            validateForm();
        });
}

function validateForm() {
    const guestMode = document.getElementById('guest_mode').value;
    let hasGuest = false;
    if (guestMode === 'new') {
        const name = document.getElementById('new_guest_name')?.value.trim();
        const phone = document.getElementById('new_guest_phone')?.value.trim();
        hasGuest = !!(name && phone);
    } else {
        hasGuest = !!document.getElementById('guest_id').value;
    }
    const roomId = document.getElementById('room_id').value;
    const from = document.getElementById('reserved_from').value;
    const until = document.getElementById('reserved_until').value;
    const btn = document.getElementById('reserveBtn');
    btn.disabled = !(hasGuest && roomId && from && until && isRoomAvailable === true);
}

document.getElementById('adults').addEventListener('input', () => { validateForm(); updateSummary(); });
document.getElementById('new_guest_name')?.addEventListener('input', () => { validateForm(); updateSummary(); });
document.getElementById('new_guest_phone')?.addEventListener('input', validateForm);

window.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('room_id').value) {
        onRoomChange();
    }
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#guestSearchInput') && !e.target.closest('#guestSearchResults')) {
        resultsBox.style.display = 'none';
    }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>