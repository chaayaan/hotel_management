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
//
// Instead of firing up to 3 queries per room (N+1), we run 3 batch queries covering all rooms
// at once, then group the results by room_id in PHP.

$room_ids = array_map(fn($r) => (int)$r['id'], $rooms);

$active_by_room = [];    // room_id => active (checked_in) booking
$upcoming_by_room = [];  // room_id => nearest not-yet-checked-in reservation
$reserved_by_room = [];  // room_id => list of ALL 'reserved' bookings, sorted by reserved_from ASC

if (!empty($room_ids)) {
    $ids_csv = implode(',', $room_ids); // safe: every element is cast to (int) above
    $today_esc = mysqli_real_escape_string($conn, $today);

    // 1) Active stays (checked in, not checked out) for every room, latest first per room.
    $activeSql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone
                  FROM hotel_bookings b
                  JOIN guests g ON g.id = b.guest_id
                  WHERE b.room_id IN ({$ids_csv}) AND b.status = 'checked_in'
                  ORDER BY b.room_id, b.checkin_at DESC";
    $activeResult = mysqli_query($conn, $activeSql);
    while ($row = mysqli_fetch_assoc($activeResult)) {
        $rid = (int)$row['room_id'];
        // Keep only the first (latest checkin_at) row per room.
        if (!isset($active_by_room[$rid])) {
            $active_by_room[$rid] = $row;
        }
    }

    // 2) Nearest upcoming reservation not yet checked in, for every room.
    $upcomingSql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone
                    FROM hotel_bookings b
                    JOIN guests g ON g.id = b.guest_id
                    WHERE b.room_id IN ({$ids_csv}) AND b.status = 'reserved'
                      AND b.reserved_until > '{$today_esc}'
                    ORDER BY b.room_id, b.reserved_from ASC";
    $upcomingResult = mysqli_query($conn, $upcomingSql);
    while ($row = mysqli_fetch_assoc($upcomingResult)) {
        $rid = (int)$row['room_id'];
        // Keep only the first (earliest reserved_from) row per room.
        if (!isset($upcoming_by_room[$rid])) {
            $upcoming_by_room[$rid] = $row;
        }
    }

    // 3) All 'reserved' bookings for every room (used to compute "next after current" in PHP,
    //    instead of one extra query per room).
    $reservedSql = "SELECT b.*, g.full_name AS guest_name
                    FROM hotel_bookings b
                    JOIN guests g ON g.id = b.guest_id
                    WHERE b.room_id IN ({$ids_csv}) AND b.status = 'reserved'
                    ORDER BY b.room_id, b.reserved_from ASC";
    $reservedResult = mysqli_query($conn, $reservedSql);
    while ($row = mysqli_fetch_assoc($reservedResult)) {
        $rid = (int)$row['room_id'];
        $reserved_by_room[$rid][] = $row;
    }
}

$room_bookings = [];
foreach ($room_ids as $rid) {
    $active = $active_by_room[$rid] ?? null;
    $upcoming = $upcoming_by_room[$rid] ?? null;

    // Next reservation strictly AFTER whichever booking is currently occupying/reserving the room right now.
    // This is what should show as "Next Reservation" on an occupied room's card.
    $next_after_current = null;
    $room_reserved = $reserved_by_room[$rid] ?? [];

    if ($active) {
        $cutoff = $active['reserved_until'];
        foreach ($room_reserved as $candidate) {
            if ($candidate['reserved_from'] >= $cutoff) {
                $next_after_current = $candidate;
                break; // list is sorted by reserved_from ASC, so first match wins
            }
        }
    } elseif ($upcoming) {
        $cutoff = $upcoming['reserved_until'];
        foreach ($room_reserved as $candidate) {
            if ((int)$candidate['id'] === (int)$upcoming['id']) {
                continue;
            }
            if ($candidate['reserved_from'] >= $cutoff) {
                $next_after_current = $candidate;
                break;
            }
        }
    }

    $room_bookings[$rid] = [
        'active' => $active,
        'upcoming' => $upcoming,
        'next_after_current' => $next_after_current,
    ];
}

// Group rooms by floor, preserving each room's original sort order within its floor.
$floors = [];
foreach ($rooms as $r) {
    $floor_name = trim((string)($r['floor'] ?? '')) !== '' ? $r['floor'] : 'Unassigned';
    $floors[$floor_name][] = $r;
}

// Sort floor groups naturally: Ground Floor first, then 1st, 2nd, 3rd... by their number, Unassigned last.
function floor_sort_key($floor) {
    if ($floor === 'Unassigned') return PHP_INT_MAX;
    if (stripos($floor, 'ground') !== false) return 0;
    if (preg_match('/-?\d+/', $floor, $m)) return (int)$m[0];
    return PHP_INT_MAX - 1;
}
uksort($floors, function ($a, $b) {
    $ka = floor_sort_key($a);
    $kb = floor_sort_key($b);
    return $ka === $kb ? strnatcasecmp($a, $b) : ($ka <=> $kb);
});

$page_title = 'Front Desk';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .room-card {
        border-radius: 16px;
        border: 2px solid var(--card-border, #e8ebe9);
        transition: box-shadow 0.15s ease, transform 0.15s ease;
        overflow: hidden;
        background: var(--card-bg, #fff);
    }
    .room-card:hover { box-shadow: 0 10px 26px rgba(0,0,0,0.1); transform: translateY(-3px); }

    .room-item[data-status="available"] .room-card { --card-bg: #f2fbf6; --card-border: #a9e6c3; }
    .room-item[data-status="reserved"] .room-card { --card-bg: #fffaf0; --card-border: #f4d98a; }
    .room-item[data-status="occupied"] .room-card { --card-bg: #fef2f3; --card-border: #f3a9b0; }
    .room-item[data-status="maintenance"] .room-card { --card-bg: #fff5ea; --card-border: #f5c48a; }
    .room-item[data-status="out_of_service"] .room-card { --card-bg: #f3f3f4; --card-border: #cfd2d5; }

    .room-card-top {
        padding: 14px 14px 8px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .room-number { font-size: 1.3rem; font-weight: 800; color: #1c3d2e; line-height: 1.1; }
    .room-meta { font-size: 0.72rem; color: #8a938e; margin-top: 2px; }
    .status-pill {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        padding: 4px 9px;
        border-radius: 20px;
        text-transform: uppercase;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .status-available { background: #0f5132; color: #fff; }
    .status-occupied { background: #c9313f; color: #fff; }
    .status-reserved { background: #b8860b; color: #fff; }
    .status-maintenance { background: #c9700f; color: #fff; }
    .status-out_of_service { background: #6c757d; color: #fff; }

    .room-card-body { padding: 2px 14px 10px; font-size: 0.8rem; color: #495a52; flex: 1 1 auto; min-height: 62px; }
    .room-card-body .guest-name { font-weight: 700; color: #1c3d2e; }
    .next-reservation-note { font-size: 0.7rem; color: #8a6d00; background: rgba(255,255,255,0.7); border-radius: 6px; padding: 3px 8px; display: inline-block; margin-top: 4px; }

    .room-card-footer {
        padding: 8px 10px;
        border-top: 1px solid rgba(0,0,0,0.06);
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }
    .room-card-footer .btn { flex: 1 1 calc(50% - 5px); font-size: 0.68rem; padding: 6px 4px; white-space: nowrap; }

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

    .floor-group { margin-bottom: 28px; }
    .floor-group-header {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 12px;
    }
    .floor-group-header h5 {
        margin: 0; font-weight: 800; color: #1c3d2e; font-size: 1.05rem;
        display: flex; align-items: center; gap: 8px;
    }
    .floor-group-header .floor-count {
        font-size: 0.75rem; font-weight: 700; color: #0f5132; background: #e6f4ec;
        padding: 3px 10px; border-radius: 20px;
    }
    .floor-group-header .floor-line { flex: 1 1 auto; height: 1px; background: #e3e7e4; }
    .room-grid { --room-min: 230px; }
</style>

<div class="d-flex flex-wrap gap-2 mb-3" id="filterChips">
    <span class="filter-chip active" data-filter="all">All Rooms</span>
    <span class="filter-chip" data-filter="available">🟢 Available</span>
    <span class="filter-chip" data-filter="occupied">🔴 Occupied</span>
    <span class="filter-chip" data-filter="reserved">🟡 Reserved</span>
    <span class="filter-chip" data-filter="maintenance">🟠 Maintenance</span>
</div>

<div id="roomGroups">
<?php foreach ($floors as $floor_name => $floor_rooms): ?>
    <div class="floor-group" data-floor="<?= e($floor_name) ?>">
        <div class="floor-group-header">
            <h5><i class="bi bi-building"></i> <?= e($floor_name) ?></h5>
            <span class="floor-line"></span>
            <span class="floor-count"><?= count($floor_rooms) ?> room<?= count($floor_rooms) === 1 ? '' : 's' ?></span>
        </div>
        <div class="room-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px;">
        <?php foreach ($floor_rooms as $r):
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
            <div class="room-item" data-status="<?= e($display_status) ?>">
                <div class="room-card h-100 d-flex flex-column">
                    <div class="room-card-top">
                        <div>
                            <div class="room-number"><?= e($r['room_number']) ?></div>
                            <div class="room-meta"><?= e($r['type_name']) ?> · ৳<?= number_format((float)$r['price_per_day'],0) ?>/night</div>
                        </div>
                        <span class="status-pill status-<?= e($display_status) ?>">
                            <?php
                                $badge = ['available' => '🟢 Free', 'occupied' => '🔴 Busy', 'reserved' => '🟡 Hold', 'maintenance' => '🟠 Maint.', 'out_of_service' => '⚫ Out'];
                                echo $badge[$display_status] ?? e(str_replace('_',' ',$display_status));
                            ?>
                        </span>
                    </div>
                    <div class="room-card-body">
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
                            <div class="next-reservation-note">
                                <i class="bi bi-calendar-event me-1"></i>Next: <?= e(date('d M', strtotime($next_after_current['reserved_from']))) ?> – <?= e(date('d M', strtotime($next_after_current['reserved_until']))) ?>
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
                                <i class="bi bi-box-arrow-right"></i> Checkout
                            </a>
                            <a href="hotel_reservations.php?room_id=<?= $rid ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-calendar-plus"></i> Future Res.
                            </a>
                        <?php elseif ($display_status === 'reserved' && $upcoming): ?>
                            <a href="hotel_reservations_checkin.php?booking_id=<?= (int)$upcoming['id'] ?>" class="btn btn-brand">
                                <i class="bi bi-box-arrow-in-right"></i> Check In
                            </a>
                            <a href="hotel_reservations_cancelled.php?booking_id=<?= (int)$upcoming['id'] ?>" class="btn btn-outline-danger">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <a href="hotel_reservations.php?room_id=<?= $rid ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-calendar-plus"></i> Future Res.
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

        document.querySelectorAll('.floor-group').forEach(group => {
            let visibleCount = 0;
            group.querySelectorAll('.room-item').forEach(item => {
                const show = (filter === 'all' || item.dataset.status === filter);
                item.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });
            group.style.display = visibleCount > 0 ? '' : 'none';
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>