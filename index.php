<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$today = date('Y-m-d');

/* =========================================================================
   HOTEL — room status + today's activity + revenue + outstanding dues.
   Same batched-query approach as hotel_dashboard.php, trimmed to what an
   overview page needs (no per-booking service/payment breakdown here).
   ========================================================================= */
$roomsResult = mysqli_query($conn, "SELECT id, status FROM rooms WHERE is_active = 1");
$rooms = [];
while ($r = mysqli_fetch_assoc($roomsResult)) $rooms[] = $r;
$room_ids = array_map(fn($r) => (int)$r['id'], $rooms);

$occupied_room_ids = [];
$reserved_room_ids = [];
if (!empty($room_ids)) {
    $ids_csv = implode(',', $room_ids); // safe: every element cast to (int) above
    $today_esc = mysqli_real_escape_string($conn, $today);

    $activeRes = mysqli_query($conn, "SELECT DISTINCT room_id FROM hotel_bookings
                                       WHERE room_id IN ({$ids_csv}) AND status = 'checked_in'");
    while ($row = mysqli_fetch_assoc($activeRes)) $occupied_room_ids[(int)$row['room_id']] = true;

    $upcomingRes = mysqli_query($conn, "SELECT DISTINCT room_id FROM hotel_bookings
                                         WHERE room_id IN ({$ids_csv}) AND status = 'reserved'
                                           AND reserved_until > '{$today_esc}'");
    while ($row = mysqli_fetch_assoc($upcomingRes)) $reserved_room_ids[(int)$row['room_id']] = true;
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

$hotelActivityRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
        SUM(CASE WHEN DATE(checkin_at) = CURDATE() THEN 1 ELSE 0 END)  AS checkins_today,
        SUM(CASE WHEN DATE(checkout_at) = CURDATE() THEN 1 ELSE 0 END) AS checkouts_today
    FROM hotel_bookings"));
$checkins_today = (int)($hotelActivityRow['checkins_today'] ?? 0);
$checkouts_today = (int)($hotelActivityRow['checkouts_today'] ?? 0);

$hotelRevenueRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS revenue_today,
        COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount ELSE 0 END), 0) AS revenue_month
    FROM hotel_payments"));
$hotel_revenue_today = (float)($hotelRevenueRow['revenue_today'] ?? 0);
$hotel_revenue_month = (float)($hotelRevenueRow['revenue_month'] ?? 0);

// Outstanding hotel dues — cancelled/no_show bookings excluded at the source,
// same rule applied consistently across the app.
$hotelDueBookingsSql = "SELECT b.id, b.room_charge_total, b.extension_charge_total, b.discount, b.tax
                         FROM hotel_bookings b
                         WHERE b.status NOT IN ('cancelled', 'no_show')
                           AND b.created_at >= (CURDATE() - INTERVAL 90 DAY)";
$hotelDueRes = mysqli_query($conn, $hotelDueBookingsSql);
$hotel_booking_rows = [];
$hotel_booking_ids = [];
while ($row = mysqli_fetch_assoc($hotelDueRes)) {
    $hotel_booking_rows[] = $row;
    $hotel_booking_ids[] = (int)$row['id'];
}
$hotel_service_totals = [];
$hotel_payment_totals = [];
if (!empty($hotel_booking_ids)) {
    $idsCsv = implode(',', $hotel_booking_ids); // safe: every element cast to (int) above
    $svcRes = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount - discount), 0) t
                                    FROM hotel_booking_services WHERE booking_id IN ({$idsCsv}) GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($svcRes)) $hotel_service_totals[(int)$row['booking_id']] = (float)$row['t'];

    $payRes = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount), 0) t
                                    FROM hotel_payments WHERE booking_id IN ({$idsCsv}) GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($payRes)) $hotel_payment_totals[(int)$row['booking_id']] = (float)$row['t'];
}
$hotel_outstanding = 0.0;
foreach ($hotel_booking_rows as $b) {
    $bid = (int)$b['id'];
    $service_total = $hotel_service_totals[$bid] ?? 0.0;
    $total_payable = max(0, (float)$b['room_charge_total'] + (float)$b['extension_charge_total']
                        + $service_total - (float)$b['discount'] + (float)$b['tax']);
    $total_paid = $hotel_payment_totals[$bid] ?? 0.0;
    $balance_due = max(0, $total_payable - $total_paid);
    if ($balance_due > 0.009) $hotel_outstanding += $balance_due;
}

/* =========================================================================
   RESTAURANT — today's sales/orders + outstanding dues.
   Cancelled orders excluded from Due, mirroring the hotel rule.
   ========================================================================= */
$restActivityRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
        SUM(CASE WHEN DATE(order_datetime) = CURDATE() THEN 1 ELSE 0 END) AS orders_today,
        SUM(CASE WHEN status IN ('hold','ordered') THEN 1 ELSE 0 END) AS pending_orders,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND DATE(order_datetime) = CURDATE() THEN total_amount ELSE 0 END), 0) AS sales_today,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND order_datetime >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN total_amount ELSE 0 END), 0) AS sales_month
    FROM restaurant_food_orders"));
$restaurant_orders_today = (int)($restActivityRow['orders_today'] ?? 0);
$restaurant_pending_orders = (int)($restActivityRow['pending_orders'] ?? 0);
$restaurant_sales_today = (float)($restActivityRow['sales_today'] ?? 0);
$restaurant_sales_month = (float)($restActivityRow['sales_month'] ?? 0);

$restDueRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(due_amount), 0) t FROM restaurant_food_orders WHERE status != 'cancelled' AND due_amount > 0.009"));
$restaurant_outstanding = (float)($restDueRow['t'] ?? 0);

$tables_result = mysqli_query($conn, "SELECT id FROM restaurant_tables WHERE is_active = 1");
$rest_table_ids = [];
while ($t = mysqli_fetch_assoc($tables_result)) $rest_table_ids[] = (int)$t['id'];
$rest_table_status = ['available' => 0, 'hold' => 0, 'occupied' => 0];
foreach ($rest_table_ids as $tid) {
    $live = get_table_live_status($conn, $tid);
    $status = $live['status'];
    if (!isset($rest_table_status[$status])) $rest_table_status[$status] = 0;
    $rest_table_status[$status]++;
}
$total_rest_tables = count($rest_table_ids);

/* =========================================================================
   COMBINED TOTALS
   ========================================================================= */
$combined_revenue_today = $hotel_revenue_today + $restaurant_sales_today;
$combined_revenue_month = $hotel_revenue_month + $restaurant_sales_month;
$combined_outstanding   = $hotel_outstanding + $restaurant_outstanding;

/* =========================================================================
   EXPENSES SNAPSHOT — today's total + department split, same source table
   as expense_history.php.
   ========================================================================= */
$expTodayRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount), 0) t FROM resort_expenses WHERE expense_date = CURDATE()"));
$expenses_today_total = (float)($expTodayRow['t'] ?? 0);

$dept_options = ['hotel' => 'Hotel', 'restaurant' => 'Restaurant', 'resort' => 'Resort', 'other' => 'Other'];
$dept_icons  = ['hotel' => 'bi-building', 'restaurant' => 'bi-cup-hot-fill', 'resort' => 'bi-flower1', 'other' => 'bi-three-dots'];
$dept_colors = ['hotel' => '#0f5132', 'restaurant' => '#d4a537', 'resort' => '#7c5cbf', 'other' => '#3d8bfd'];

$deptTodayRes = mysqli_query($conn,
    "SELECT dept, COALESCE(SUM(amount),0) t FROM resort_expenses WHERE expense_date = CURDATE() GROUP BY dept");
$dept_today_totals = ['hotel' => 0, 'restaurant' => 0, 'resort' => 0, 'other' => 0];
while ($row = mysqli_fetch_assoc($deptTodayRes)) {
    if (isset($dept_today_totals[$row['dept']])) $dept_today_totals[$row['dept']] = (float)$row['t'];
}

/* =========================================================================
   7-DAY REVENUE TREND (hotel payments + restaurant sales), for a small
   sparkline-style chart — gives the overview page a "glance and understand"
   trend, not just a snapshot number.
   ========================================================================= */
$trend_days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_days[$d] = ['label' => date('D', strtotime($d)), 'hotel' => 0.0, 'restaurant' => 0.0];
}
$trendStart = array_key_first($trend_days);
$trendStartEsc = mysqli_real_escape_string($conn, $trendStart);

$hotelTrendRes = mysqli_query($conn, "SELECT DATE(created_at) d, COALESCE(SUM(amount),0) t
                                       FROM hotel_payments WHERE created_at >= '{$trendStartEsc}' GROUP BY d");
while ($row = mysqli_fetch_assoc($hotelTrendRes)) {
    if (isset($trend_days[$row['d']])) $trend_days[$row['d']]['hotel'] = (float)$row['t'];
}
$restTrendRes = mysqli_query($conn, "SELECT DATE(order_datetime) d, COALESCE(SUM(total_amount),0) t
                                      FROM restaurant_food_orders
                                      WHERE status != 'cancelled' AND order_datetime >= '{$trendStartEsc}' GROUP BY d");
while ($row = mysqli_fetch_assoc($restTrendRes)) {
    if (isset($trend_days[$row['d']])) $trend_days[$row['d']]['restaurant'] = (float)$row['t'];
}

/* =========================================================================
   RECENT ACTIVITY FEED — most recent hotel bookings + restaurant orders,
   merged and sorted by time, so the overview shows "what just happened"
   across both sides of the business in one glance.
   ========================================================================= */
$recentBookingsRes = mysqli_query($conn, "SELECT b.id, b.reservation_no AS ref_no, b.status, b.created_at,
                                                  g.full_name AS who, r.room_number AS where_val
                                           FROM hotel_bookings b
                                           JOIN guests g ON g.id = b.guest_id
                                           JOIN rooms r ON r.id = b.room_id
                                           ORDER BY b.created_at DESC LIMIT 6");
$recent_feed = [];
while ($row = mysqli_fetch_assoc($recentBookingsRes)) {
    $recent_feed[] = [
        'type' => 'hotel', 'ref_no' => $row['ref_no'], 'status' => $row['status'],
        'who' => $row['who'], 'where' => 'Room ' . $row['where_val'], 'time' => $row['created_at'],
    ];
}
$recentOrdersRes = mysqli_query($conn, "SELECT o.id, o.order_no AS ref_no, o.status, o.created_at, t.table_no
                                         FROM restaurant_food_orders o
                                         JOIN restaurant_tables t ON t.id = o.table_id
                                         ORDER BY o.created_at DESC LIMIT 6");
while ($row = mysqli_fetch_assoc($recentOrdersRes)) {
    $recent_feed[] = [
        'type' => 'restaurant', 'ref_no' => $row['ref_no'], 'status' => $row['status'],
        'who' => 'Table ' . $row['table_no'], 'where' => '', 'time' => $row['created_at'],
    ];
}
usort($recent_feed, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
$recent_feed = array_slice($recent_feed, 0, 8);

$page_title = 'Dashboard';
$active_menu = 'index';
require __DIR__ . '/navbar.php';
?>

<style>
    /* ---- Stat cards (shared language with hotel/restaurant dashboards) ---- */
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
    .stat-card .stat-sub { font-size: 0.7rem; color: #a5aca6; margin-top: 1px; }

    .icon-green   { background: #0f5132; }
    .icon-red     { background: #c9313f; }
    .icon-amber   { background: #b8860b; }
    .icon-blue    { background: #2f6fb0; }
    .icon-orange  { background: #c9700f; }
    .icon-grey    { background: #6c757d; }
    .icon-purple  { background: #7c5cbf; }

    .card-icon-badge {
        width: 40px; height: 40px; border-radius: 10px;
        background: #e7f3ec; color: #0f5132;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem; flex-shrink: 0;
    }

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

    /* ---- Business unit summary cards (Hotel / Restaurant) ---- */
    .unit-card {
        border-radius: 16px;
        border: 1px solid #e8ebe9;
        background: #fff;
        padding: 20px;
        height: 100%;
    }
    .unit-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    .unit-card-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; color: #fff; flex-shrink: 0;
    }
    .unit-card-title { font-weight: 800; font-size: 1.05rem; color: #1c3d2e; }
    .unit-card-sub { font-size: 0.78rem; color: #8a938e; }
    .unit-metric-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 9px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.86rem;
    }
    .unit-metric-row:last-of-type { border-bottom: none; }
    .unit-metric-row .m-label { color: #6b756f; display: flex; align-items: center; gap: 6px; }
    .unit-metric-row .m-value { font-weight: 700; color: #1c3d2e; }
    .unit-metric-row .m-value.due { color: #c9313f; }
    .unit-metric-row .m-value.ok { color: #0f5132; }
    .unit-card-footer { margin-top: 14px; display: flex; gap: 8px; }
    .unit-card-footer .btn { flex: 1; font-size: 0.78rem; }

    .occupancy-bar, .status-bar-track { height: 9px; border-radius: 6px; background: #eef1ee; overflow: hidden; }
    .occupancy-bar-fill { height: 100%; background: #0f5132; border-radius: 6px; }
    .status-bar-track { display: flex; }
    .status-bar-seg { height: 100%; }

    /* ---- Department expense mini cards (from expense_history language) ---- */
    .dept-card {
        border: 1px solid #eef0ef; border-radius: 12px;
        padding: 14px; display: flex; align-items: center; gap: 12px;
    }
    .dept-card-icon {
        width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem; color: #fff;
    }
    .dept-card-body { flex: 1 1 auto; min-width: 0; }
    .dept-card-name { font-weight: 700; color: #1c3d2e; font-size: 0.88rem; }
    .dept-card-amount { font-weight: 800; color: #1c3d2e; font-size: 0.95rem; margin-top: 1px; }

    /* ---- Activity feed ---- */
    .feed-row {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 12px 18px; border-bottom: 1px solid #f0f2f0;
    }
    .feed-row:last-child { border-bottom: none; }
    .feed-icon {
        width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem; color: #fff;
    }
    .feed-body { flex: 1 1 auto; min-width: 0; }
    .feed-title { font-weight: 600; font-size: 0.85rem; color: #1c3d2e; }
    .feed-meta { font-size: 0.75rem; color: #8a938e; margin-top: 1px; }
    .feed-time { font-size: 0.72rem; color: #a5aca6; white-space: nowrap; flex-shrink: 0; padding-top: 2px; }

    .payment-status-paid { background: #d1f5e0; color: #0f5132; }
    .payment-status-due { background: #fddede; color: #a91d2c; }

    @media (max-width: 767px) {
        .unit-card-footer { flex-direction: column; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-0" style="color:#1c3d2e;"><i class="bi bi-speedometer2 me-1"></i> Dashboard</h5>
        <div class="text-muted small"><?= e(date('l, d M Y')) ?> · Resort-wide overview</div>
    </div>
    <div class="d-flex gap-2">
        <a href="hotel_dashboard.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-building"></i> Hotel</a>
        <a href="restaurant_dashboard.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-cup-hot-fill"></i> Restaurant</a>
        <a href="expense_history.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt"></i> Expenses</a>
    </div>
</div>

<!-- Top-line combined stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-blue"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($combined_revenue_today, 0) ?></div>
                <div class="stat-label">Collected Today</div>
                <div class="stat-sub">Hotel + Restaurant</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-amber"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($combined_revenue_month, 0) ?></div>
                <div class="stat-label">Revenue This Month</div>
                <div class="stat-sub">Hotel + Restaurant</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-red"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($combined_outstanding, 0) ?></div>
                <div class="stat-label">Outstanding Due</div>
                <div class="stat-sub">Cancelled excluded</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-orange"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($expenses_today_total, 0) ?></div>
                <div class="stat-label">Expenses Today</div>
                <div class="stat-sub">All departments</div>
            </div>
        </div>
    </div>
</div>

<!-- Hotel & Restaurant unit summary cards -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="unit-card">
            <div class="unit-card-head">
                <div class="unit-card-icon" style="background:#0f5132;"><i class="bi bi-building"></i></div>
                <div>
                    <div class="unit-card-title">Hotel</div>
                    <div class="unit-card-sub"><?= $occupancy_rate ?>% occupancy · <?= $room_stats['occupied'] ?>/<?= $total_rooms ?> rooms</div>
                </div>
            </div>

            <div class="occupancy-bar mb-3">
                <div class="occupancy-bar-fill" style="width: <?= $occupancy_rate ?>%;"></div>
            </div>

            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-box-arrow-in-right"></i> Check-ins Today</span>
                <span class="m-value"><?= $checkins_today ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-box-arrow-right"></i> Check-outs Today</span>
                <span class="m-value"><?= $checkouts_today ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-cash-coin"></i> Collected Today</span>
                <span class="m-value ok">৳<?= number_format($hotel_revenue_today, 2) ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-exclamation-circle"></i> Outstanding Due</span>
                <span class="m-value <?= $hotel_outstanding > 0.009 ? 'due' : 'ok' ?>">৳<?= number_format($hotel_outstanding, 2) ?></span>
            </div>

            <div class="unit-card-footer">
                <a href="hotel_dashboard.php" class="btn btn-brand"><i class="bi bi-speedometer2"></i> Full Dashboard</a>
                <a href="hotel_front_desk.php" class="btn btn-outline-secondary"><i class="bi bi-door-open"></i> Front Desk</a>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="unit-card">
            <div class="unit-card-head">
                <div class="unit-card-icon" style="background:#d4a537;"><i class="bi bi-cup-hot-fill"></i></div>
                <div>
                    <div class="unit-card-title">Restaurant</div>
                    <div class="unit-card-sub"><?= $rest_table_status['available'] ?? 0 ?>/<?= $total_rest_tables ?> tables free</div>
                </div>
            </div>

            <?php $rest_occ_rate = $total_rest_tables > 0 ? round((($rest_table_status['occupied'] ?? 0) / $total_rest_tables) * 100) : 0; ?>
            <div class="occupancy-bar mb-3">
                <div class="occupancy-bar-fill" style="width: <?= $rest_occ_rate ?>%; background:#d4a537;"></div>
            </div>

            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-receipt-cutoff"></i> Orders Today</span>
                <span class="m-value"><?= $restaurant_orders_today ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-hourglass-split"></i> Pending Orders</span>
                <span class="m-value"><?= $restaurant_pending_orders ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-cash-coin"></i> Sales Today</span>
                <span class="m-value ok">৳<?= number_format($restaurant_sales_today, 2) ?></span>
            </div>
            <div class="unit-metric-row">
                <span class="m-label"><i class="bi bi-exclamation-circle"></i> Outstanding Due</span>
                <span class="m-value <?= $restaurant_outstanding > 0.009 ? 'due' : 'ok' ?>">৳<?= number_format($restaurant_outstanding, 2) ?></span>
            </div>

            <div class="unit-card-footer">
                <a href="restaurant_dashboard.php" class="btn btn-brand"><i class="bi bi-speedometer2"></i> Full Dashboard</a>
                <a href="restaurant_front_desk.php" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3-gap"></i> Front Desk</a>
            </div>
        </div>
    </div>
</div>

<!-- Revenue trend + Recent activity -->
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="section-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="fw-bold d-flex align-items-center gap-2" style="color:#1c3d2e;">
                    <span class="card-icon-badge"><i class="bi bi-bar-chart-line-fill"></i></span>
                    Revenue Trend <span class="text-muted small fw-normal">(last 7 days)</span>
                </span>
            </div>
            <div style="position: relative; height: 260px;">
                <canvas id="trendChart"></canvas>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-3">
                <div class="d-flex align-items-center gap-2 small text-muted">
                    <span style="width:10px;height:10px;border-radius:3px;background:#0f5132;display:inline-block;"></span> Hotel
                </div>
                <div class="d-flex align-items-center gap-2 small text-muted">
                    <span style="width:10px;height:10px;border-radius:3px;background:#d4a537;display:inline-block;"></span> Restaurant
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="section-card">
            <div class="section-header">
                <span class="d-flex align-items-center gap-2">
                    <span class="card-icon-badge"><i class="bi bi-clock-history"></i></span>
                    Recent Activity
                </span>
            </div>
            <div>
                <?php if (empty($recent_feed)): ?>
                    <div class="text-center text-muted py-5">No recent activity.</div>
                <?php else: foreach ($recent_feed as $item):
                    $is_hotel = $item['type'] === 'hotel';
                    $icon = $is_hotel ? 'bi-building' : 'bi-cup-hot-fill';
                    $color = $is_hotel ? '#0f5132' : '#d4a537';
                    $status_label = ucwords(str_replace('_', ' ', $item['status']));
                ?>
                    <div class="feed-row">
                        <div class="feed-icon" style="background:<?= $color ?>;"><i class="bi <?= $icon ?>"></i></div>
                        <div class="feed-body">
                            <div class="feed-title"><?= e($item['ref_no']) ?> <span class="text-muted fw-normal">· <?= e($status_label) ?></span></div>
                            <div class="feed-meta"><?= e($item['who']) ?><?= $item['where'] ? ' · ' . e($item['where']) : '' ?></div>
                        </div>
                        <div class="feed-time"><?= e(date('d M, h:i A', strtotime($item['time']))) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Expense breakdown by department -->
<div class="row g-3">
    <div class="col-12">
        <div class="section-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="fw-bold d-flex align-items-center gap-2" style="color:#1c3d2e;">
                    <span class="card-icon-badge"><i class="bi bi-wallet2"></i></span>
                    Today's Expenses by Department
                </span>
                <a href="expense_history.php" class="btn btn-sm btn-outline-secondary">Full History</a>
            </div>
            <div class="row g-2">
                <?php foreach ($dept_options as $key => $label): ?>
                <div class="col-6 col-md-3">
                    <div class="dept-card">
                        <div class="dept-card-icon" style="background: <?= e($dept_colors[$key]) ?>;">
                            <i class="bi <?= e($dept_icons[$key]) ?>"></i>
                        </div>
                        <div class="dept-card-body">
                            <div class="dept-card-name"><?= e($label) ?></div>
                            <div class="dept-card-amount">৳<?= number_format($dept_today_totals[$key], 2) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const trendLabels = <?= json_encode(array_map(fn($d) => $d['label'], array_values($trend_days))) ?>;
const trendHotel = <?= json_encode(array_map(fn($d) => round($d['hotel'], 2), array_values($trend_days))) ?>;
const trendRestaurant = <?= json_encode(array_map(fn($d) => round($d['restaurant'], 2), array_values($trend_days))) ?>;

const trendCtx = document.getElementById('trendChart');
if (trendCtx) {
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'Hotel',
                    data: trendHotel,
                    borderColor: '#0f5132',
                    backgroundColor: '#0f513222',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#0f5132',
                },
                {
                    label: 'Restaurant',
                    data: trendRestaurant,
                    borderColor: '#d4a537',
                    backgroundColor: '#d4a53722',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#d4a537',
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ৳${ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: '#f0f2f1' }, ticks: { callback: v => '৳' + v.toLocaleString() } }
            }
        }
    });
}
</script>

<?php require __DIR__ . '/footer.php'; ?>