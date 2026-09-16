<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$today = date('Y-m-d');

// Fetch all active rooms with their current/latest relevant booking (if any)
$sql = "SELECT r.*, rt.name AS type_name
        FROM rooms r
        JOIN room_types rt ON rt.id = r.room_type_id
        WHERE r.is_active = 1
        ORDER BY CAST(r.room_number AS UNSIGNED), r.room_number";
$rooms_result = mysqli_query($conn, $sql);

$rooms = [];
while ($r = mysqli_fetch_assoc($rooms_result)) {
    $rooms[] = $r;
}

// For each room, find the current active booking (checked_in), and the NEXT upcoming reservation
// (which may exist even if the room is currently available, occupied, or itself reserved for a nearer date)
$room_bookings = [];
foreach ($rooms as $r) {
    $rid = (int)$r['id'];

    // Active stay (checked in, not checked out)
    $activeSql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone
                  FROM hotel_bookings b
                  JOIN guests g ON g.id = b.guest_id
                  WHERE b.room_id = {$rid} AND b.status = 'checked_in'
                  ORDER BY b.checkin_at DESC LIMIT 1";
    $active = mysqli_fetch_assoc(mysqli_query($conn, $activeSql));

    // Nearest upcoming reservation not yet checked in (for the room card badge & Check-In/Cancel buttons)
    $upcomingSql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone
                    FROM hotel_bookings b
                    JOIN guests g ON g.id = b.guest_id
                    WHERE b.room_id = {$rid} AND b.status = 'reserved'
                      AND b.reserved_until > '{$today}'
                    ORDER BY b.reserved_from ASC LIMIT 1";
    $upcoming = mysqli_fetch_assoc(mysqli_query($conn, $upcomingSql));

    // Next reservation strictly AFTER whichever booking is currently occupying/reserving the room right now.
    // This is what should show as "Next Reservation" on an occupied room's card.
    $next_after_current = null;
    if ($active) {
        $nextSql = "SELECT b.*, g.full_name AS guest_name
                    FROM hotel_bookings b
                    JOIN guests g ON g.id = b.guest_id
                    WHERE b.room_id = {$rid} AND b.status = 'reserved'
                      AND b.reserved_from >= '{$active['reserved_until']}'
                    ORDER BY b.reserved_from ASC LIMIT 1";
        $next_after_current = mysqli_fetch_assoc(mysqli_query($conn, $nextSql)) ?: null;
    } elseif ($upcoming) {
        $nextSql = "SELECT b.*, g.full_name AS guest_name
                    FROM hotel_bookings b
                    JOIN guests g ON g.id = b.guest_id
                    WHERE b.room_id = {$rid} AND b.status = 'reserved'
                      AND b.id != {$upcoming['id']}
                      AND b.reserved_from >= '{$upcoming['reserved_until']}'
                    ORDER BY b.reserved_from ASC LIMIT 1";
        $next_after_current = mysqli_fetch_assoc(mysqli_query($conn, $nextSql)) ?: null;
    }

    $room_bookings[$rid] = [
        'active' => $active ?: null,
        'upcoming' => $upcoming ?: null,
        'next_after_current' => $next_after_current,
    ];
}

$page_title = 'Front Desk';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .room-card {
        border-radius: 14px;
        border: 1px solid #e8ebe9;
        transition: box-shadow 0.15s ease, transform 0.15s ease;
        overflow: hidden;
        background: #fff;
    }
    .room-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); transform: translateY(-2px); }
    .room-card-top {
        padding: 14px 16px 10px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .room-number { font-size: 1.25rem; font-weight: 700; color: #1c3d2e; }
    .room-meta { font-size: 0.75rem; color: #8a938e; }
    .status-pill {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        padding: 4px 10px;
        border-radius: 20px;
        text-transform: uppercase;
    }
    .status-available { background: #d1f5e0; color: #0f5132; }
    .status-occupied { background: #fddede; color: #a91d2c; }
    .status-reserved { background: #fff2cc; color: #8a6d00; }
    .status-maintenance { background: #ffe4c7; color: #99530a; }
    .status-out_of_service { background: #e2e3e5; color: #495057; }

    .room-card-body { padding: 4px 16px 14px; font-size: 0.83rem; color: #495a52; min-height: 58px; }
    .room-card-body .guest-name { font-weight: 600; color: #1c3d2e; }
    .next-reservation-note { font-size: 0.74rem; color: #8a6d00; background: #fff8e1; border-radius: 6px; padding: 3px 8px; display: inline-block; }
    .room-card-footer {
        padding: 10px 12px;
        border-top: 1px solid #f0f2f1;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    .room-card-footer .btn { flex: 1; font-size: 0.75rem; padding: 6px 8px; }
    .filter-chip {
        border: 1px solid #d8ddd9;
        background: #fff;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        cursor: pointer;
        color: #495a52;
        user-select: none;
    }
    .filter-chip.active { background: #0f5132; color: #fff; border-color: #0f5132; }
</style>

<div class="d-flex flex-wrap gap-2 mb-3" id="filterChips">
    <span class="filter-chip active" data-filter="all">All Rooms</span>
    <span class="filter-chip" data-filter="available">Available</span>
    <span class="filter-chip" data-filter="occupied">Occupied</span>
    <span class="filter-chip" data-filter="reserved">Reserved</span>
    <span class="filter-chip" data-filter="maintenance">Maintenance</span>
</div>

<div class="row g-3" id="roomGrid">
<?php foreach ($rooms as $r):
    $rid = (int)$r['id'];
    $active = $room_bookings[$rid]['active'];
    $upcoming = $room_bookings[$rid]['upcoming'];
    $next_after_current = $room_bookings[$rid]['next_after_current'];

    // Determine display status
    if ($r['status'] === 'maintenance' || $r['status'] === 'out_of_service') {
        $display_status = $r['status'];
    } elseif ($active) {
        $display_status = 'occupied';
    } elseif ($upcoming) {
        $display_status = 'reserved';
    } else {
        $display_status = 'available';
    }
?>
    <div class="col-sm-6 col-lg-4 col-xl-3 room-item" data-status="<?= e($display_status) ?>">
        <div class="room-card h-100 d-flex flex-column">
            <div class="room-card-top">
                <div>
                    <div class="room-number"><?= e($r['room_number']) ?></div>
                    <div class="room-meta"><?= e($r['type_name']) ?> · <?= e($r['floor'] ?: '—') ?> · ৳<?= number_format((float)$r['price_per_day'],0) ?>/night</div>
                </div>
                <span class="status-pill status-<?= e($display_status) ?>"><?= e(str_replace('_',' ',$display_status)) ?></span>
            </div>
            <div class="room-card-body flex-grow-1">
                <?php if ($active): ?>
                    <div class="guest-name"><?= e($active['guest_name']) ?></div>
                    <div><?= e($active['guest_phone']) ?></div>
                    <div class="text-muted">Free from: <?= e(date('d M, h:i A', strtotime($active['reserved_until'] . ' 12:00:00'))) ?></div>
                <?php elseif ($upcoming): ?>
                    <div class="guest-name"><?= e($upcoming['guest_name']) ?></div>
                    <div class="text-muted">Reserved: <?= e(date('d M', strtotime($upcoming['reserved_from']))) ?> → <?= e(date('d M', strtotime($upcoming['reserved_until']))) ?></div>
                <?php elseif ($display_status === 'maintenance' || $display_status === 'out_of_service'): ?>
                    <div class="text-muted fst-italic">Room currently unavailable for booking.</div>
                <?php else: ?>
                    <div class="text-muted">Available Today</div>
                <?php endif; ?>

                <?php if ($next_after_current): ?>
                    <div class="next-reservation-note mt-1">
                        <i class="bi bi-calendar-event me-1"></i>Next Reservation: <?= e(date('d M', strtotime($next_after_current['reserved_from']))) ?> – <?= e(date('d M', strtotime($next_after_current['reserved_until']))) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="room-card-footer">
                <?php if ($display_status === 'occupied' && $active): ?>
                    <a href="hotel_services.php?booking_id=<?= (int)$active['id'] ?>" class="btn btn-outline-brand">
                        <i class="bi bi-cup-hot"></i> Service
                    </a>
                    <a href="hotel_extend_stay.php?booking_id=<?= (int)$active['id'] ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-calendar-plus"></i> Extend
                    </a>
                    <a href="hotel_reservations_checkout.php?booking_id=<?= (int)$active['id'] ?>" class="btn btn-danger">
                        <i class="bi bi-box-arrow-right"></i> Check Out
                    </a>
                    <a href="hotel_reservations.php?room_id=<?= $rid ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-calendar-plus"></i> Reserve Future
                    </a>
                <?php elseif ($display_status === 'reserved' && $upcoming): ?>
                    <a href="hotel_reservations_checkin.php?booking_id=<?= (int)$upcoming['id'] ?>" class="btn btn-brand">
                        <i class="bi bi-box-arrow-in-right"></i> Check In
                    </a>
                    <a href="hotel_reservations_cancelled.php?booking_id=<?= (int)$upcoming['id'] ?>" class="btn btn-outline-danger">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                    <a href="hotel_reservations.php?room_id=<?= $rid ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-calendar-plus"></i> Reserve Future
                    </a>
                <?php elseif ($display_status === 'available'): ?>
                    <a href="hotel_reservations.php?room_id=<?= $rid ?>" class="btn btn-brand">
                        <i class="bi bi-calendar-plus"></i> Reserve
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary" disabled>Unavailable</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if (empty($rooms)): ?>
    <div class="text-center text-muted py-5">
        No active rooms found. <a href="hotel_rooms.php">Add a room</a> to get started.
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        const filter = chip.dataset.filter;
        document.querySelectorAll('.room-item').forEach(item => {
            item.style.display = (filter === 'all' || item.dataset.status === filter) ? '' : 'none';
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>