<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

/* =========================================================================
   1) TODAY'S ACTIVITY + SALES — one query with conditional aggregates.
   Orders placed today, orders currently pending (hold/ordered, regardless
   of date), completed (paid) and cancelled today, plus today's / this
   month's collected sales. Mirrors the single-query aggregate pattern used
   in hotel_dashboard.php.
   ========================================================================= */
$activitySql = "SELECT
        SUM(CASE WHEN DATE(order_datetime) = CURDATE() THEN 1 ELSE 0 END) AS orders_today,
        SUM(CASE WHEN status IN ('hold','ordered') THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN status = 'paid' AND DATE(order_datetime) = CURDATE() THEN 1 ELSE 0 END) AS completed_today,
        SUM(CASE WHEN status = 'cancelled' AND DATE(order_datetime) = CURDATE() THEN 1 ELSE 0 END) AS cancelled_today,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND DATE(order_datetime) = CURDATE() THEN total_amount ELSE 0 END), 0) AS sales_today,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND order_datetime >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN total_amount ELSE 0 END), 0) AS sales_month
    FROM restaurant_food_orders";
$activityRow = mysqli_fetch_assoc(mysqli_query($conn, $activitySql));
$orders_today      = (int)($activityRow['orders_today'] ?? 0);
$pending_orders     = (int)($activityRow['pending_orders'] ?? 0);
$completed_today    = (int)($activityRow['completed_today'] ?? 0);
$cancelled_today    = (int)($activityRow['cancelled_today'] ?? 0);
$sales_today        = (float)($activityRow['sales_today'] ?? 0);
$sales_month         = (float)($activityRow['sales_month'] ?? 0);

/* =========================================================================
   2) OUTSTANDING DUE — across all non-cancelled orders that still owe
   money, regardless of date. Cancelled orders are excluded from Due, same
   principle as the hotel dashboard's booking due calculation.
   ========================================================================= */
$dueRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(due_amount), 0) AS total_due, COUNT(*) AS due_count
     FROM restaurant_food_orders
     WHERE status != 'cancelled' AND due_amount > 0.009"));
$outstanding_total = (float)($dueRow['total_due'] ?? 0);
$outstanding_count = (int)($dueRow['due_count'] ?? 0);

/* =========================================================================
   3) TODAY'S RESTAURANT EXPENSES — same resort_expenses table the hotel
   dashboard reads, filtered to the restaurant department.
   ========================================================================= */
$expenseRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount), 0) t FROM resort_expenses WHERE dept = 'restaurant' AND expense_date = CURDATE()"));
$expenses_today = (float)($expenseRow['t'] ?? 0);

/* =========================================================================
   4) TABLE STATUS SNAPSHOT — same live-status logic as restaurant_front_desk.php,
   summarized into counts instead of full per-table detail.
   ========================================================================= */
$tables_result = mysqli_query($conn, "SELECT id FROM restaurant_tables WHERE is_active = 1");
$table_ids = [];
while ($t = mysqli_fetch_assoc($tables_result)) $table_ids[] = (int)$t['id'];

$table_status_counts = ['available' => 0, 'hold' => 0, 'occupied' => 0];
foreach ($table_ids as $tid) {
    $live = get_table_live_status($conn, $tid);
    $status = $live['status'];
    if (!isset($table_status_counts[$status])) $table_status_counts[$status] = 0;
    $table_status_counts[$status]++;
}
$total_tables = count($table_ids);

/* =========================================================================
   5) RECENT ORDERS — last 8 orders with table info, newest first. Bounded
   to the last 90 days like the hotel dashboard's recent bookings list.
   ========================================================================= */
$recentSql = "SELECT o.id, o.order_no, o.status, o.order_datetime, o.total_amount, o.total_paid, o.due_amount,
                     t.table_no, t.floor
              FROM restaurant_food_orders o
              JOIN restaurant_tables t ON t.id = o.table_id
              WHERE o.created_at >= (CURDATE() - INTERVAL 90 DAY)
              ORDER BY o.created_at DESC
              LIMIT 8";
$recentResult = mysqli_query($conn, $recentSql);
$recent_orders = [];
while ($row = mysqli_fetch_assoc($recentResult)) $recent_orders[] = $row;

/* =========================================================================
   6) ORDER STATUS OVERVIEW (last 30 days) — counts by status, for a simple
   at-a-glance breakdown card.
   ========================================================================= */
$statusSql = "SELECT status, COUNT(*) AS cnt
              FROM restaurant_food_orders
              WHERE order_datetime >= (CURDATE() - INTERVAL 30 DAY)
              GROUP BY status";
$statusResult = mysqli_query($conn, $statusSql);
$status_counts = ['hold' => 0, 'ordered' => 0, 'paid' => 0, 'cancelled' => 0];
$status_total = 0;
while ($row = mysqli_fetch_assoc($statusResult)) {
    $status_counts[$row['status']] = (int)$row['cnt'];
    $status_total += (int)$row['cnt'];
}

$page_title = 'Restaurant Dashboard';
$active_menu = 'restaurant_dashboard';
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
    .icon-purple  { background: #7c5cbf; }

    .card-icon-badge {
        width: 38px; height: 38px; border-radius: 10px;
        background: #e7f3ec; color: #0f5132;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; flex-shrink: 0;
    }

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
    .dot-hold { background: #b8860b; }
    .dot-occupied { background: #c9313f; }

    .status-bar-track { height: 10px; border-radius: 6px; background: #eef1ee; overflow: hidden; display: flex; }
    .status-bar-seg { height: 100%; }

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

    .payment-status-paid { background: #d1f5e0; color: #0f5132; }
    .payment-status-due { background: #fddede; color: #a91d2c; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-0" style="color:#1c3d2e;"><i class="bi bi-cup-hot-fill me-1"></i> Restaurant Dashboard</h5>
        <div class="text-muted small">Live overview of today's restaurant operations</div>
    </div>
    <div class="d-flex gap-2">
        <a href="restaurant_front_desk.php" class="btn btn-sm btn-outline-brand"><i class="bi bi-grid-3x3-gap"></i> Front Desk</a>
        <a href="restaurant_order_list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-journal-text"></i> All Orders</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-blue"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($sales_today, 0) ?></div>
                <div class="stat-label">Today's Sales</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-green"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
                <div class="stat-value"><?= $orders_today ?></div>
                <div class="stat-label">Today's Orders</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-amber"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-value"><?= $pending_orders ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-purple"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= $completed_today ?></div>
                <div class="stat-label">Completed Today</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-red"><i class="bi bi-x-circle"></i></div>
            <div>
                <div class="stat-value"><?= $cancelled_today ?></div>
                <div class="stat-label">Cancelled Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-red"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($outstanding_total, 0) ?></div>
                <div class="stat-label">Outstanding (<?= $outstanding_count ?> order<?= $outstanding_count === 1 ? '' : 's' ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-amber"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($sales_month, 0) ?></div>
                <div class="stat-label">Revenue This Month</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon icon-orange"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="stat-value">৳<?= number_format($expenses_today, 0) ?></div>
                <div class="stat-label">Restaurant Expenses Today</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="section-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold d-flex align-items-center gap-2" style="color:#1c3d2e;">
                    <span class="card-icon-badge"><i class="bi bi-grid-3x3-gap"></i></span>
                    Table Status
                </span>
                <a href="restaurant_front_desk.php" class="btn btn-sm btn-outline-secondary">Front Desk</a>
            </div>

            <div class="room-status-row">
                <span class="room-status-dot dot-available"></span>
                <span class="label">🟢 Available</span>
                <span class="count"><?= $table_status_counts['available'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-hold"></span>
                <span class="label">🟡 Hold</span>
                <span class="count"><?= $table_status_counts['hold'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot dot-occupied"></span>
                <span class="label">🔴 Occupied</span>
                <span class="count"><?= $table_status_counts['occupied'] ?></span>
            </div>
            <div class="text-muted small mt-2"><?= $total_tables ?> active table<?= $total_tables === 1 ? '' : 's' ?> total</div>
        </div>

        <div class="section-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="fw-bold d-flex align-items-center gap-2" style="color:#1c3d2e;">
                    <span class="card-icon-badge"><i class="bi bi-pie-chart"></i></span>
                    Order Status <span class="text-muted small fw-normal">(30d)</span>
                </span>
            </div>

            <?php if ($status_total > 0): ?>
            <div class="status-bar-track mb-3">
                <div class="status-bar-seg" style="width:<?= ($status_counts['hold'] / $status_total) * 100 ?>%; background:#b8860b;"></div>
                <div class="status-bar-seg" style="width:<?= ($status_counts['ordered'] / $status_total) * 100 ?>%; background:#2f6fb0;"></div>
                <div class="status-bar-seg" style="width:<?= ($status_counts['paid'] / $status_total) * 100 ?>%; background:#0f5132;"></div>
                <div class="status-bar-seg" style="width:<?= ($status_counts['cancelled'] / $status_total) * 100 ?>%; background:#c9313f;"></div>
            </div>
            <?php endif; ?>

            <div class="room-status-row">
                <span class="room-status-dot" style="background:#b8860b;"></span>
                <span class="label">Hold</span>
                <span class="count"><?= $status_counts['hold'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot" style="background:#2f6fb0;"></span>
                <span class="label">Ordered</span>
                <span class="count"><?= $status_counts['ordered'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot" style="background:#0f5132;"></span>
                <span class="label">Paid</span>
                <span class="count"><?= $status_counts['paid'] ?></span>
            </div>
            <div class="room-status-row">
                <span class="room-status-dot" style="background:#c9313f;"></span>
                <span class="label">Cancelled</span>
                <span class="count"><?= $status_counts['cancelled'] ?></span>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="section-card">
            <div class="section-header">
                <span class="d-flex align-items-center gap-2">
                    <span class="card-icon-badge"><i class="bi bi-journal-text"></i></span>
                    Recent Orders
                </span>
                <a href="restaurant_order_list.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-dashboard mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="ps-3">Order #</th>
                            <th>Table</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                            <th>Payment</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_orders)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No orders in the last 90 days.</td></tr>
                    <?php else: foreach ($recent_orders as $o):
                        $oid = (int)$o['id'];
                        $is_paid = (float)$o['due_amount'] <= 0.009;
                    ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= e($o['order_no']) ?></td>
                            <td><?= e($o['table_no']) ?></td>
                            <td class="small"><?= e(date('d M, h:i A', strtotime($o['order_datetime']))) ?></td>
                            <td><span class="badge <?= order_status_badge($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
                            <td class="text-end">৳<?= number_format((float)$o['total_amount'], 2) ?></td>
                            <td><span class="badge <?= $is_paid ? 'payment-status-paid' : 'payment-status-due' ?>"><?= $is_paid ? 'Paid' : 'Due' ?></span></td>
                            <td class="text-end pe-3">
                                <?php if (in_array($o['status'], ['hold','ordered'])): ?>
                                    <a href="restaurant_payment.php?order_id=<?= $oid ?>" class="btn btn-sm btn-brand"><i class="bi bi-cash-coin"></i></a>
                                <?php else: ?>
                                    <a href="restaurant_receipt.php?order_id=<?= $oid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i></a>
                                <?php endif; ?>
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