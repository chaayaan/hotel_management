<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$tables_result = mysqli_query($conn, "SELECT * FROM restaurant_tables WHERE is_active = 1 ORDER BY CAST(table_no AS UNSIGNED), table_no ASC");
$tables = [];
while ($t = mysqli_fetch_assoc($tables_result)) $tables[] = $t;

// Determine live status + active order for each table
$table_states = [];
foreach ($tables as $t) {
    $tid = (int)$t['id'];
    $live = get_table_live_status($conn, $tid);
    $order = $live['order'];
    $bill = 0;
    $due = 0;
    if ($order) {
        $bill = (float)$order['total_amount'];
        $due = (float)$order['due_amount'];
    }
    $table_states[$tid] = ['status' => $live['status'], 'order' => $order, 'bill' => $bill, 'due' => $due];
}

$page_title = 'Restaurant Front Desk';
$active_menu = 'restaurant_front_desk';
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
    .status-hold { background: #fff2cc; color: #8a6d00; }

    .room-card-body { padding: 4px 16px 14px; font-size: 0.83rem; color: #495a52; min-height: 58px; }
    .room-card-body .order-no { font-weight: 600; color: #1c3d2e; }
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
    <span class="filter-chip active" data-filter="all">All Tables</span>
    <span class="filter-chip" data-filter="available">🟢 Available</span>
    <span class="filter-chip" data-filter="hold">🟡 Hold</span>
    <span class="filter-chip" data-filter="occupied">🔴 Occupied</span>
</div>

<div class="row g-3" id="tableGrid">
<?php foreach ($tables as $t):
    $tid = (int)$t['id'];
    $state = $table_states[$tid];
    $status = $state['status'];
    $order = $state['order'];
?>
    <div class="col-sm-6 col-lg-4 col-xl-3 room-item" data-status="<?= e($status) ?>">
        <div class="room-card h-100 d-flex flex-column">
            <div class="room-card-top">
                <div>
                    <div class="room-number"><?= e($t['table_no']) ?></div>
                    <div class="room-meta"><?= e($t['table_type']) ?> · <?= e($t['floor'] ?: '—') ?></div>
                </div>
                <span class="status-pill status-<?= e($status) ?>">
                    <?= $status === 'available' ? '🟢 Available' : ($status === 'hold' ? '🟡 Hold' : '🔴 Occupied') ?>
                </span>
            </div>
            <div class="room-card-body flex-grow-1">
                <?php if ($order): ?>
                    <div class="order-no">Order #<?= e($order['order_no']) ?></div>
                    <div class="text-muted">Bill: ৳<?= number_format($state['bill'], 2) ?></div>
                    <?php if ($state['due'] > 0.009): ?>
                        <div class="text-danger fw-semibold">Due: ৳<?= number_format($state['due'], 2) ?></div>
                    <?php else: ?>
                        <div class="text-success">Fully Paid</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted">Ready for a new order.</div>
                <?php endif; ?>
            </div>
            <div class="room-card-footer">
                <?php if ($status === 'available'): ?>
                    <a href="restaurant_food_orders.php?table_id=<?= $tid ?>" class="btn btn-brand">
                        <i class="bi bi-plus-circle"></i> New Order
                    </a>
                <?php else: ?>
                    <a href="restaurant_food_orders.php?table_id=<?= $tid ?>&order_id=<?= (int)$order['id'] ?>" class="btn btn-outline-brand">
                        <i class="bi bi-eye"></i> View Order
                    </a>
                    <a href="restaurant_payment.php?order_id=<?= (int)$order['id'] ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-cash-coin"></i> Payment
                    </a>
                    <a href="restaurant_receipt.php?order_id=<?= (int)$order['id'] ?>" class="btn btn-outline-dark">
                        <i class="bi bi-printer"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if (empty($tables)): ?>
    <div class="text-center text-muted py-5">
        No active tables found. <a href="restaurant_tables.php">Add a table</a> to get started.
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
