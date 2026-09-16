<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? 'all';
$filter_today = isset($_GET['today']) && $_GET['today'] === '1';

$sql = "SELECT o.*, t.table_no, t.floor, t.table_type
        FROM restaurant_food_orders o
        JOIN restaurant_tables t ON t.id = o.table_id
        WHERE 1=1";

if ($search !== '') {
    $qEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (o.order_no LIKE '%{$qEsc}%' OR t.table_no LIKE '%{$qEsc}%')";
}
if ($filter_status !== 'all' && in_array($filter_status, ['hold','ordered','paid','cancelled'])) {
    $sql .= " AND o.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}
if ($filter_today) {
    $sql .= " AND DATE(o.order_datetime) = CURDATE()";
}
$sql .= " ORDER BY o.created_at DESC LIMIT 200";
$orders_result = mysqli_query($conn, $sql);

$orders = [];
while ($o = mysqli_fetch_assoc($orders_result)) $orders[] = $o;

$page_title = 'Order List';
$active_menu = 'restaurant_order_list';
require __DIR__ . '/navbar.php';
?>

<style>
    .payment-status-paid { background: #d1f5e0; color: #0f5132; }
    .payment-status-due { background: #fddede; color: #a91d2c; }
    .filter-chip {
        border: 1px solid #d8ddd9; background: #fff; padding: 5px 14px; border-radius: 20px;
        font-size: 0.8rem; cursor: pointer; color: #495a52; text-decoration: none; display: inline-block;
    }
    .filter-chip.active { background: #0f5132; color: #fff; border-color: #0f5132; }
</style>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="restaurant_order_list.php" class="filter-chip <?= ($filter_status==='all' && !$filter_today) ? 'active':'' ?>">All</a>
    <a href="restaurant_order_list.php?today=1" class="filter-chip <?= $filter_today ? 'active':'' ?>">Today</a>
    <a href="restaurant_order_list.php?status=hold" class="filter-chip <?= $filter_status==='hold' ? 'active':'' ?>">Hold</a>
    <a href="restaurant_order_list.php?status=ordered" class="filter-chip <?= $filter_status==='ordered' ? 'active':'' ?>">Ordered</a>
    <a href="restaurant_order_list.php?status=paid" class="filter-chip <?= $filter_status==='paid' ? 'active':'' ?>">Paid</a>
    <a href="restaurant_order_list.php?status=cancelled" class="filter-chip <?= $filter_status==='cancelled' ? 'active':'' ?>">Cancelled</a>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-journal-text me-1"></i> Order List</span>
        <form method="GET" class="d-flex gap-2">
            <?php if ($filter_status !== 'all'): ?><input type="hidden" name="status" value="<?= e($filter_status) ?>"><?php endif; ?>
            <?php if ($filter_today): ?><input type="hidden" name="today" value="1"><?php endif; ?>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search order # or table..." value="<?= e($search) ?>" style="width:220px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Table</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th>Payment</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No orders found.</td></tr>
                <?php else: foreach ($orders as $o):
                    $oid = (int)$o['id'];
                    $is_paid = (float)$o['due_amount'] <= 0.009;
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($o['order_no']) ?></td>
                        <td><?= e($o['table_no']) ?> <span class="text-muted small">(<?= e($o['table_type']) ?>)</span></td>
                        <td class="small"><?= e(date('d M Y, h:i A', strtotime($o['order_datetime']))) ?></td>
                        <td><span class="badge <?= order_status_badge($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
                        <td class="text-end">৳<?= number_format((float)$o['total_amount'], 2) ?></td>
                        <td class="text-end">৳<?= number_format((float)$o['total_paid'], 2) ?></td>
                        <td class="text-end fw-semibold <?= $o['due_amount'] > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format((float)$o['due_amount'], 2) ?></td>
                        <td><span class="badge <?= $is_paid ? 'payment-status-paid' : 'payment-status-due' ?>"><?= $is_paid ? 'Paid' : 'Due' ?></span></td>
                        <td class="text-end pe-3">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                            <?php if (in_array($o['status'], ['hold','ordered'])): ?>
                                <a href="restaurant_payment.php?order_id=<?= $oid ?>" class="btn btn-sm btn-brand"><i class="bi bi-cash-coin"></i> Payment</a>
                                <a href="restaurant_receipt.php?order_id=<?= $oid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i></a>
                            <?php elseif ($o['status'] === 'paid'): ?>
                                <a href="restaurant_receipt.php?order_id=<?= $oid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Receipt</a>
                            <?php elseif ($o['status'] === 'cancelled'): ?>
                                <a href="restaurant_receipt.php?order_id=<?= $oid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
