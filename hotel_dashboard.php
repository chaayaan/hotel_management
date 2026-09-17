<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$today = date('Y-m-d');

/* =========================================================================
   1) ROOM STATUS BREAKDOWN
   Mirrors the display-status logic in hotel_front_desk.php (active/upcoming
   booking wins over the raw rooms.status column), but only needs room ids
   in each bucket, not full booking rows — 3 small batch queries total,
   flat regardless of room count.
   ========================================================================= */
$roomsSql = "SELECT id, status FROM rooms WHERE is_active = 1";
$roomsResult = mysqli_query($conn, $roomsSql);
$rooms = [];
while ($r = mysqli_fetch_assoc($roomsResult)) {
    $rooms[] = $r;
}
$room_ids = array_map(fn($r) => (int)$r['id'], $rooms);

$occupied_room_ids = [];
$reserved_room_ids = [];
if (!empty($room_ids)) {
    $ids_csv = implode(',', $room_ids); // safe: every element cast to (int) above
    $today_esc = mysqli_real_escape_string($conn, $today);

    $activeRes = mysqli_query($conn, "SELECT DISTINCT room_id FROM hotel_bookings
                                       WHERE room_id IN ({$ids_csv}) AND status = 'checked_in'");
    while ($row = mysqli_fetch_assoc($activeRes)) {
        $occupied_room_ids[(int)$row['room_id']] = true;
    }

    $upcomingRes = mysqli_query($conn, "SELECT DISTINCT room_id FROM hotel_bookings
                                         WHERE room_id IN ({$ids_csv}) AND status = 'reserved'
                                           AND reserved_until > '{$today_esc}'");
    while ($row = mysqli_fetch_assoc($upcomingRes)) {
        $reserved_room_ids[(int)$row['room_id']] = true;
    }
}

$room_stats = ['available' => 0, 'occupied' => 0, 'reserved' => 0, 'maintenance' => 0, 'out_of_service' => 0];
foreach ($rooms as $r) {
    $rid = (int)$r['id'];
    if ($r['status'] === 'maintenance' || $r['status'] === 'out_of_service') {
        $room_stats[$r['status']]++;
    } elseif (isset($occupied_room_ids[$rid])) {
        $room_stats['occupied']++;
    } elseif (isset($reserved_room_ids[$rid])) {
        $room_stats['reserved']++;
    } else {
        $room_stats['available']++;
    }
}
$total_rooms = count($rooms);
$occupancy_rate = $total_rooms > 0 ? round(($room_stats['occupied'] / $total_rooms) * 100) : 0;

/* =========================================================================
   2) TODAY'S ACTIVITY — one query with conditional aggregates instead of
   three separate COUNT() round trips.
   ========================================================================= */
$activitySql = "SELECT
        SUM(CASE WHEN DATE(checkin_at) = CURDATE() THEN 1 ELSE 0 END)      AS checkins_today,
        SUM(CASE WHEN DATE(checkout_at) = CURDATE() THEN 1 ELSE 0 END)     AS checkouts_today,
        SUM(CASE WHEN reservation_date = CURDATE() AND status != 'cancelled' THEN 1 ELSE 0 END) AS new_reservations_today
    FROM hotel_bookings";
$activityRow = mysqli_fetch_assoc(mysqli_query($conn, $activitySql));
$checkins_today = (int)($activityRow['checkins_today'] ?? 0);
$checkouts_today = (int)($activityRow['checkouts_today'] ?? 0);
$new_reservations_today = (int)($activityRow['new_reservations_today'] ?? 0);

/* =========================================================================
   3) REVENUE — today's collections + this month's collections in one query.
   ========================================================================= */
$revenueSql = "SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS revenue_today,
        COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount ELSE 0 END), 0) AS revenue_month
    FROM hotel_payments";
$revenueRow = mysqli_fetch_assoc(mysqli_query($conn, $revenueSql));
$revenue_today = (float)($revenueRow['revenue_today'] ?? 0);
$revenue_month = (float)($revenueRow['revenue_month'] ?? 0);

/* =========================================================================
   4) TODAY'S HOTEL EXPENSES
   ========================================================================= */
$expenseRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount), 0) t FROM resort_expenses WHERE dept = 'hotel' AND expense_date = CURDATE()"));
$expenses_today = (float)($expenseRow['t'] ?? 0);

/* =========================================================================
   5) RECENT BOOKINGS + OUTSTANDING DUES
   One query pulls every non-cancelled booking from the last 90 days (a
   reasonable, bounded window rather than the whole history table). The
   first 8 (already ordered newest-first) become the "recent bookings" list;
   the full set is used to total up outstanding dues. Financial aggregates
   (services/payments) are then batch-fetched with 2 more IN(...) queries,
   the same pattern used in hotel_booking_list.php — never one query per row.

   IMPORTANT: cancelled (and no_show) bookings are excluded right here at
   the source query, so their amounts never enter $outstanding_total below.
   Do not remove this filter — it's what keeps cancelled booking amounts
   out of the dashboard's Due figure.
   ========================================================================= */
$bookingsSql = "SELECT b.id, b.reservation_no, b.status, b.reserved_from, b.reserved_until,
                       b.room_charge_total, b.extension_charge_total, b.discount, b.tax,
                       b.created_at, g.full_name AS guest_name, r.room_number
                FROM hotel_bookings b
                JOIN guests g ON g.id = b.guest_id
                JOIN rooms r ON r.id = b.room_id
                WHERE b.status NOT IN ('cancelled', 'no_show')
                  AND b.created_at >= (CURDATE() - INTERVAL 90 DAY)
                ORDER BY b.created_at DESC";
$bookingsResult = mysqli_query($conn, $bookingsSql);

$booking_rows = [];
$booking_ids = [];
while ($row = mysqli_fetch_assoc($bookingsResult)) {
    $booking_rows[] = $row;
    $booking_ids[] = (int)$row['id'];
}

$service_totals = [];
$payment_totals = [];
if (!empty($booking_ids)) {
    $idsCsv = implode(',', $booking_ids); // safe: every element cast to (int) above

    $svcRes = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount - discount), 0) t
                                    FROM hotel_booking_services
                                    WHERE booking_id IN ({$idsCsv})
                                    GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($svcRes)) {
        $service_totals[(int)$row['booking_id']] = (float)$row['t'];
    }

    $payRes = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount), 0) t
                                    FROM hotel_payments
                                    WHERE booking_id IN ({$idsCsv})
                                    GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($payRes)) {
        $payment_totals[(int)$row['booking_id']] = (float)$row['t'];
    }
}

$outstanding_total = 0.0;
$outstanding_count = 0;
foreach ($booking_rows as &$b) {
    $bid = (int)$b['id'];
    $service_total = $service_totals[$bid] ?? 0.0;
    $total_payable = max(0, (float)$b['room_charge_total'] + (float)$b['extension_charge_total']
                        + $service_total - (float)$b['discount'] + (float)$b['tax']);
    $total_paid = $payment_totals[$bid] ?? 0.0;
    $balance_due = max(0, $total_payable - $total_paid);

    $b['total_payable'] = $total_payable;
    $b['balance_due'] = $balance_due;

    if ($balance_due > 0.009) {
        $outstanding_total += $balance_due;
        $outstanding_count++;
    }
}
unset($b);

$recent_bookings = array_slice($booking_rows, 0, 8);

$page_title = 'Dashboard';
$active_menu = 'dashboard';
require __DIR__ . '/navbar.php';
?>

<style>
    .stat-card {
        border-radius: 16px;
        border: 1px solid #e8ebe9;
        background: #fff;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        height: 100%;
    }
    .stat-card .stat-icon {
        width: 46px; height: 46px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; flex-shrink: 0; color: #fff;
    }
    .stat-card .stat-value { font-size: 1.35rem; font-weight: 800; color: #1c3d2e; line-height: 1.1; }
    .stat-card .stat-label { font-size: 0.75rem; color: #8a938e; margin-top: 2px; }

    .icon-green   { background: #0f5132; }
    .icon-red     { background: #c9313f; }
    .icon-amber   { background: #b8860b; }
    .icon-blue    { background: #2f6fb0; }
    .icon-orange  { background: #c9700f; }
    .icon-grey    { background: #6c757d; }

    .room-status-row {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 4px;
        border-bottom: 1px solid #f0f2f0;
    }
    .room-status-row:last-child { border-bottom: none; }
    .room-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .room-status-row .label { flex: 1 1 auto; font-size: 0.85rem; color: #495a52; }
    .room-status-row .count { font-weight: 700; color: #1c3d2e; }

    .dot-available { background: #0f5132; }
    .dot-occupied { background: #c9313f; }
    .dot-reserved { background: #b8860b; }
    .dot-maintenance { background: #c9700f; }
    .dot-out_of_service { background: #6c757d; }

    .occupancy-bar { height: 10px; border-radius: 6px; background: #eef1ee; overflow: hidden; }
    .occupancy-bar-fill { height: 100%; background: #0f5132; border-radius: 6px; }

    .section-card {
        border-radius: 16px;
        border: 1px solid #e8ebe9;
        background: #fff;
        height: 100%;
    }
    .section-card .section-header {
        padding: 14px 18px;
        border-bottom: 1px solid #f0f2f0;
        font-weight: 700;
        color: #1c3d2e;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .table-dashboard th, .table-dashboard td { font-size: 0.82rem; white-space: nowrap; }
</style>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-green"><i class="bi bi-door-open"></i></div>
            <div>
                <div class="stat-value"><?= $occupancy_rate ?>%</div>
                <div class="stat-label">Occupancy (<?= $room_stats['occupied'] ?>/<?= $total_rooms ?> rooms)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-blue"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($revenue_today, 0) ?></div>
                <div class="stat-label">Collected Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-red"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($outstanding_total, 0) ?></div>
                <div class="stat-label">Outstanding (<?= $outstanding_count ?> booking<?= $outstanding_count === 1 ? '' : 's' ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-amber"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($revenue_month, 0) ?></div>
                <div class="stat-label">Revenue This Month</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-green"><i class="bi bi-box-arrow-in-right"></i></div>
            <div>
                <div class="stat-value"><?= $checkins_today ?></div>
                <div class="stat-label">Check-ins Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-grey"><i class="bi bi-box-arrow-right"></i></div>
            <div>
                <div class="stat-value"><?= $checkouts_today ?></div>
                <div class="stat-label">Check-outs Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-amber"><i class="bi bi-calendar-plus"></i></div>
            <div>
                <div class="stat-value"><?= $new_reservations_today ?></div>
                <div class="stat-label">New Reservations Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-orange"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($expenses_today, 0) ?></div>
                <div class="stat-label">Hotel Expenses Today</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="section-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold" style="color:#1c3d2e;"><i class="bi bi-building me-1"></i> Room Status</span>
                <a href="hotel_front_desk.php" class="btn btn-sm btn-outline-secondary">Front Desk</a>
            </div>

            <div class="occupancy-bar mb-3">
                <div class="occupancy-bar-fill" style="width: <?= $occupancy_rate ?>%;"></div>
            </div>

            <div class="room-status-row">
                <span class="room-status-dot dot-available"></span>
                <span class="label">🟢 Available</span>
                <span class="count"><?= $room_stats['available'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-occupied"></span>
                <span class="label">🔴 Occupied</span>
                <span class="count"><?= $room_stats['occupied'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-reserved"></span>
                <span class="label">🟡 Reserved</span>
                <span class="count"><?= $room_stats['reserved'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-maintenance"></span>
                <span class="label">🟠 Maintenance</span>
                <span class="count"><?= $room_stats['maintenance'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-out_of_service"></span>
                <span class="label">⚫ Out of Service</span>
                <span class="count"><?= $room_stats['out_of_service'] ?></span>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="section-card">
            <div class="section-header">
                <span><i class="bi bi-journal-text me-1"></i> Recent Bookings</span>
                <a href="hotel_booking_list.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-dashboard mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">Booking #</th>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>From → Until</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Balance Due</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_bookings)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No bookings in the last 90 days.</td></tr>
                    <?php else: foreach ($recent_bookings as $b): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= e($b['reservation_no']) ?></td>
                            <td><?= e($b['guest_name']) ?></td>
                            <td><?= e($b['room_number']) ?></td>
                            <td><?= e(date('d M', strtotime($b['reserved_from']))) ?> → <?= e(date('d M', strtotime($b['reserved_until']))) ?></td>
                            <td><span class="badge <?= booking_status_badge($b['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $b['status']))) ?></span></td>
                            <td class="text-end pe-3 fw-semibold <?= $b['balance_due'] > 0 ? 'text-danger' : 'text-success' ?>">
                                ৳<?= number_format($b['balance_due'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>