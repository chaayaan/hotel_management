<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);

$user = current_user();
$dept_options = ['hotel' => 'Hotel', 'restaurant' => 'Restaurant', 'resort' => 'Resort', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: expense_history.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $dept = $_POST['dept'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $expense_date = trim($_POST['expense_date'] ?? '');

        if ($id <= 0 || $category_id <= 0 || !array_key_exists($dept, $dept_options) || $amount <= 0 || $expense_date === '') {
            flash_set('danger', 'Please fill all required fields correctly.');
            header('Location: expense_history.php');
            exit;
        }

        $stmt = mysqli_prepare($conn, "UPDATE resort_expenses SET category_id=?, dept=?, amount=?, note=?, expense_date=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'isdssi', $category_id, $dept, $amount, $note, $expense_date, $id);
        if (mysqli_stmt_execute($stmt)) {
            flash_set('success', 'Expense updated successfully.');
        } else {
            flash_set('danger', 'Failed to update expense: ' . mysqli_error($conn));
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM resort_expenses WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (mysqli_stmt_execute($stmt)) {
            flash_set('success', 'Expense deleted successfully.');
        } else {
            flash_set('danger', 'Failed to delete expense: ' . mysqli_error($conn));
        }
    }

    header('Location: expense_history.php?' . http_build_query($_GET));
    exit;
}

$categories_result = mysqli_query($conn, "SELECT id, category FROM resort_expenses_category ORDER BY category ASC");
$categories_arr = [];
while ($c = mysqli_fetch_assoc($categories_result)) $categories_arr[] = $c;

// --------------------------------------------------------
// Filters
// --------------------------------------------------------
$filter_from = trim($_GET['from'] ?? date('Y-m-01'));
$filter_to = trim($_GET['to'] ?? date('Y-m-d'));
$filter_category = (int)($_GET['category'] ?? 0);
$filter_dept = trim($_GET['dept'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "SELECT e.id, e.category_id, e.amount, e.dept, e.note, e.expense_date, e.created_at, c.category AS category_name
        FROM resort_expenses e
        JOIN resort_expenses_category c ON c.id = e.category_id
        WHERE 1=1";

if ($filter_from !== '') {
    $fromEsc = mysqli_real_escape_string($conn, $filter_from);
    $sql .= " AND e.expense_date >= '{$fromEsc}'";
}
if ($filter_to !== '') {
    $toEsc = mysqli_real_escape_string($conn, $filter_to);
    $sql .= " AND e.expense_date <= '{$toEsc}'";
}
if ($filter_category > 0) {
    $sql .= " AND e.category_id = {$filter_category}";
}
if ($filter_dept !== '' && array_key_exists($filter_dept, $dept_options)) {
    $deptEsc = mysqli_real_escape_string($conn, $filter_dept);
    $sql .= " AND e.dept = '{$deptEsc}'";
}
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (c.category LIKE '%{$searchEsc}%' OR e.note LIKE '%{$searchEsc}%')";
}

$sql .= " ORDER BY e.expense_date DESC, e.created_at DESC";
$result = mysqli_query($conn, $sql);

$expenses_arr = [];
$grand_total = 0;
$dept_totals = ['hotel' => 0, 'restaurant' => 0, 'resort' => 0, 'other' => 0];
while ($row = mysqli_fetch_assoc($result)) {
    $expenses_arr[] = $row;
    $grand_total += (float)$row['amount'];
    if (isset($dept_totals[$row['dept']])) {
        $dept_totals[$row['dept']] += (float)$row['amount'];
    }
}

// --------------------------------------------------------
// Chart data: last 12 months, category-wise totals (for the stacked bar)
// and department-wise totals with week-over-week trend (for the side cards).
// These are independent of the table filters above — always a rolling 12-month view.
// --------------------------------------------------------
$chart_months = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $chart_months[$ym] = date('M', strtotime("-{$i} months"));
}
$chart_start = date('Y-m-01', strtotime('-11 months'));

// All active categories, so the stacked series stays consistent even for months with no data.
$chart_categories_result = mysqli_query($conn, "SELECT id, category FROM resort_expenses_category WHERE is_active = 1 ORDER BY category ASC");
$chart_categories = [];
while ($cc = mysqli_fetch_assoc($chart_categories_result)) $chart_categories[] = $cc;

// month => [category_id => total]
$monthly_category_totals = [];
foreach ($chart_months as $ym => $label) $monthly_category_totals[$ym] = [];

$chartStartEsc = mysqli_real_escape_string($conn, $chart_start);
$chart_res = mysqli_query($conn, "
    SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, category_id, SUM(amount) AS total
    FROM resort_expenses
    WHERE expense_date >= '{$chartStartEsc}'
    GROUP BY ym, category_id
");
while ($cr = mysqli_fetch_assoc($chart_res)) {
    if (isset($monthly_category_totals[$cr['ym']])) {
        $monthly_category_totals[$cr['ym']][(int)$cr['category_id']] = (float)$cr['total'];
    }
}

$monthly_grand_totals = [];
foreach ($chart_months as $ym => $label) {
    $sum = 0;
    foreach ($monthly_category_totals[$ym] as $v) $sum += $v;
    $monthly_grand_totals[$ym] = $sum;
}

// Department cards: this week vs the previous week, over all-time data.
$dept_icons = [
    'hotel'      => 'bi-building',
    'restaurant' => 'bi-cup-hot-fill',
    'resort'     => 'bi-flower1',
    'other'      => 'bi-three-dots',
];
$dept_colors = [
    'hotel'      => '#0f5132',
    'restaurant' => '#d4a537',
    'resort'     => '#7c5cbf',
    'other'      => '#3d8bfd',
];

$dept_card_data = [];
$dept_all_time_total = 0;
foreach ($dept_options as $key => $label) {
    $keyEsc = mysqli_real_escape_string($conn, $key);

    $totalRes = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) t FROM resort_expenses WHERE dept = '{$keyEsc}'");
    $total = (float)mysqli_fetch_assoc($totalRes)['t'];
    $dept_all_time_total += $total;

    $dept_card_data[$key] = [
        'label' => $label,
        'total' => $total,
        'icon' => $dept_icons[$key],
        'color' => $dept_colors[$key],
    ];
}
// Keep department order as defined in $dept_options (Hotel, Restaurant, Resort, Other).

$page_title = 'Expense History';
$active_menu = 'expense_history';
require __DIR__ . '/navbar.php';
?>

<style>
    .chart-icon-badge {
        width: 42px; height: 42px; border-radius: 10px;
        background: #e7f3ec; color: #0f5132;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.15rem; flex-shrink: 0;
    }
    .chart-legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; color: #495a52; }
    .chart-legend-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }

    .dept-card {
        border: 1px solid #eef0ef; border-radius: 12px;
        padding: 16px; display: flex; align-items: center; gap: 14px;
    }
    .dept-card-total {
        background: #eaf5ee; border-color: #d7ebdd;
        flex: 0 0 auto;
    }
    .dept-card-total .dept-card-name { color: #4b5f56; font-weight: 600; font-size: 0.95rem; }
    .dept-card-total .dept-card-amount { font-size: 1.3rem; }

    .dept-card-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        flex: 1 1 auto;
    }
    .dept-card-grid .dept-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        justify-content: center;
    }
    .dept-card-icon {
        width: 46px; height: 46px; border-radius: 10px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; color: #fff;
    }
    .dept-card-body { flex: 1 1 auto; min-width: 0; }
    .dept-card-name { font-weight: 700; color: #1c3d2e; font-size: 0.98rem; }
    .dept-card-amount { font-weight: 800; color: #1c3d2e; font-size: 1.05rem; white-space: nowrap; flex-shrink: 0; }
    .dept-card-grid .dept-card-amount { font-size: 1rem; margin-top: 2px; }

    @media (max-width: 575px) {
        .dept-card-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="chart-icon-badge"><i class="bi bi-bar-chart-line-fill"></i></div>
                        <div>
                            <div class="fw-bold" style="color:#1c3d2e; font-size:1.05rem;">Expense Overview</div>
                            <div class="text-muted small">Category-wise breakdown, last 12 months</div>
                        </div>
                    </div>
                </div>
                <div style="position: relative; height: 320px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="d-flex flex-wrap gap-3 mt-3" id="chartLegend"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="d-flex flex-column gap-2 h-100">
            <div class="dept-card dept-card-total">
                <div class="dept-card-icon" style="background: #0f5132;">
                    <i class="bi bi-wallet2"></i>
                </div>
                <div class="dept-card-body">
                    <div class="dept-card-name">Total</div>
                </div>
                <div class="dept-card-amount">৳<?= number_format($dept_all_time_total, 2) ?></div>
            </div>

            <div class="dept-card-grid">
                <?php foreach ($dept_card_data as $key => $d): ?>
                <div class="dept-card" style="background: <?= e($d['color']) ?>0d;">
                    <div class="dept-card-icon" style="background: <?= e($d['color']) ?>;">
                        <i class="bi <?= e($d['icon']) ?>"></i>
                    </div>
                    <div class="dept-card-body">
                        <div class="dept-card-name"><?= e($d['label']) ?></div>
                        <div class="dept-card-amount">৳<?= number_format($d['total'], 2) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <i class="bi bi-journal-text me-1"></i> Expense History
    </div>
    <div class="card-body border-bottom">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filter_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filter_to) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($categories_arr as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filter_category === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['category']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($dept_options as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filter_dept === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-8 col-md-3">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Category or note..." value="<?= e($search) ?>">
            </div>
            <div class="col-4 col-md-1">
                <button class="btn btn-sm btn-brand w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Department</th>
                        <th>Note</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($expenses_arr)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No expenses found for the selected filters.</td></tr>
                <?php else: foreach ($expenses_arr as $row): ?>
                    <tr>
                        <td><?= e(date('d M Y', strtotime($row['expense_date']))) ?></td>
                        <td class="fw-semibold"><?= e($row['category_name']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= e(ucfirst($row['dept'])) ?></span></td>
                        <td class="text-muted"><?= e($row['note']) ?: '—' ?></td>
                        <td class="text-end fw-semibold">৳<?= number_format((float)$row['amount'], 2) ?></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['category_name'])) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($expenses_arr)): ?>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="4" class="text-end">Total</td>
                        <td class="text-end">৳<?= number_format($grand_total, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Edit Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
                        <select name="category_id" id="editCategoryId" class="form-select" required>
                            <?php foreach ($categories_arr as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= e($c['category']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Department <span class="text-danger">*</span></label>
                        <select name="dept" id="editDept" class="form-select" required>
                            <?php foreach ($dept_options as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Amount (৳) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="editAmount" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Expense Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" id="editExpenseDate" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Note</label>
                        <input type="text" name="note" id="editNote" class="form-control" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand btn-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this expense for <strong id="deleteName"></strong>? This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const chartLabels = <?= json_encode(array_values($chart_months)) ?>;
const chartCategories = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['category']], $chart_categories)) ?>;
const chartMonthKeys = <?= json_encode(array_keys($chart_months)) ?>;
const monthlyCategoryTotals = <?= json_encode($monthly_category_totals) ?>;

const palette = ['#0f5132', '#d4a537', '#7c5cbf', '#3d8bfd', '#e07a5f', '#4ea8a0', '#c9576b', '#8d99ae', '#f2a541', '#5c7a5c'];

const chartDatasets = chartCategories.map((cat, i) => {
    const color = palette[i % palette.length];
    return {
        label: cat.name,
        backgroundColor: color,
        borderRadius: 4,
        maxBarThickness: 34,
        data: chartMonthKeys.map(ym => (monthlyCategoryTotals[ym] && monthlyCategoryTotals[ym][cat.id]) ? monthlyCategoryTotals[ym][cat.id] : 0)
    };
});

const ctx = document.getElementById('categoryChart');
if (ctx && chartDatasets.length > 0) {
    new Chart(ctx, {
        type: 'bar',
        data: { labels: chartLabels, datasets: chartDatasets },
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
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, grid: { color: '#f0f2f1' }, ticks: { callback: v => '৳' + v.toLocaleString() } }
            }
        }
    });

    // Build a simple legend under the chart since we render our own to match the page style.
    const legendWrap = document.getElementById('chartLegend');
    chartDatasets.forEach(ds => {
        const item = document.createElement('div');
        item.className = 'chart-legend-item';
        item.innerHTML = `<span class="chart-legend-dot" style="background:${ds.backgroundColor}"></span>${ds.label}`;
        legendWrap.appendChild(item);
    });
} else if (ctx) {
    ctx.parentElement.innerHTML = '<div class="text-muted text-center py-5">No expense data yet to chart.</div>';
}
</script>

<script>
function openEditModal(row) {
    document.getElementById('editId').value = row.id;
    document.getElementById('editCategoryId').value = row.category_id || '';
    document.getElementById('editDept').value = row.dept;
    document.getElementById('editAmount').value = row.amount;
    document.getElementById('editExpenseDate').value = row.expense_date;
    document.getElementById('editNote').value = row.note || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>