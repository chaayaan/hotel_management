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

// Group tables by floor, preserving each table's original sort order within its floor.
$floors = [];
foreach ($tables as $t) {
    $floor_name = trim((string)($t['floor'] ?? '')) !== '' ? $t['floor'] : 'Unassigned';
    $floors[$floor_name][] = $t;
}
// Sort floor groups naturally (e.g. Ground Floor, 1st floor, 2nd floor...) with Unassigned last.
uksort($floors, function ($a, $b) {
    if ($a === 'Unassigned') return 1;
    if ($b === 'Unassigned') return -1;
    return strnatcasecmp($a, $b);
});

$page_title = 'Restaurant Front Desk';
$active_menu = 'restaurant_front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .room-card {
        border-radius: 16px;
        border: 2px solid var(--card-border, #e8ebe9);
        transition: box-shadow 0.15s ease, transform 0.15s ease;
        overflow: hidden;
        background: var(--card-bg, #fff);
        aspect-ratio: 1 / 1;
    }
    .room-card:hover { box-shadow: 0 10px 26px rgba(0,0,0,0.1); transform: translateY(-3px); }

    .room-item[data-status="available"] .room-card { --card-bg: #f2fbf6; --card-border: #a9e6c3; }
    .room-item[data-status="hold"] .room-card { --card-bg: #fffaf0; --card-border: #f4d98a; }
    .room-item[data-status="occupied"] .room-card { --card-bg: #fef2f3; --card-border: #f3a9b0; }

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
    .status-hold { background: #b8860b; color: #fff; }

    .room-card-body { padding: 2px 14px 8px; font-size: 0.8rem; color: #495a52; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .room-card-body .order-no { font-weight: 700; color: #1c3d2e; }

    .room-card-footer {
        padding: 8px 10px;
        border-top: 1px solid rgba(0,0,0,0.06);
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }
    .room-card-footer .btn { flex: 1; font-size: 0.72rem; padding: 6px 6px; white-space: nowrap; }

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
    .room-grid { --room-min: 165px; }
</style>

<div class="d-flex flex-wrap gap-2 mb-3" id="filterChips">
    <span class="filter-chip active" data-filter="all">All Tables</span>
    <span class="filter-chip" data-filter="available">🟢 Available</span>
    <span class="filter-chip" data-filter="hold">🟡 Hold</span>
    <span class="filter-chip" data-filter="occupied">🔴 Occupied</span>
</div>

<div id="tableGroups">
<?php foreach ($floors as $floor_name => $floor_tables): ?>
    <div class="floor-group" data-floor="<?= e($floor_name) ?>">
        <div class="floor-group-header">
            <h5><i class="bi bi-building"></i> <?= e($floor_name) ?></h5>
            <span class="floor-line"></span>
            <span class="floor-count"><?= count($floor_tables) ?> table<?= count($floor_tables) === 1 ? '' : 's' ?></span>
        </div>
        <div class="room-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 16px;">
        <?php foreach ($floor_tables as $t):
            $tid = (int)$t['id'];
            $state = $table_states[$tid];
            $status = $state['status'];
            $order = $state['order'];
        ?>
            <div class="room-item" data-status="<?= e($status) ?>">
                <div class="room-card h-100 d-flex flex-column">
                    <div class="room-card-top">
                        <div>
                            <div class="room-number"><?= e($t['table_no']) ?></div>
                            <div class="room-meta"><?= e($t['table_type']) ?></div>
                        </div>
                        <span class="status-pill status-<?= e($status) ?>">
                            <?= $status === 'available' ? '🟢 Free' : ($status === 'hold' ? '🟡 Hold' : '🔴 Busy') ?>
                        </span>
                    </div>
                    <div class="room-card-body">
                        <?php if ($order): ?>
                            <div class="order-no">#<?= e($order['order_no']) ?></div>
                            <div class="text-muted">৳<?= number_format($state['bill'], 2) ?></div>
                            <?php if ($state['due'] > 0.009): ?>
                                <div class="text-danger fw-semibold">Due ৳<?= number_format($state['due'], 2) ?></div>
                            <?php else: ?>
                                <div class="text-success">Fully Paid</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">Ready for order</div>
                        <?php endif; ?>
                    </div>
                    <div class="room-card-footer">
                        <?php if ($status === 'available'): ?>
                            <a href="restaurant_food_orders.php?table_id=<?= $tid ?>" class="btn btn-brand">
                                <i class="bi bi-plus-circle"></i> New
                            </a>
                        <?php else: ?>
                            <a href="restaurant_food_orders.php?table_id=<?= $tid ?>&order_id=<?= (int)$order['id'] ?>" class="btn btn-outline-brand">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="restaurant_payment.php?order_id=<?= (int)$order['id'] ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-cash-coin"></i>
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