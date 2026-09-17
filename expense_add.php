<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);

$user = current_user();
$dept_options = ['hotel' => 'Hotel', 'restaurant' => 'Restaurant', 'resort' => 'Resort', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: expense_add.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $dept = $_POST['dept'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $expense_date = trim($_POST['expense_date'] ?? '');

        if ($category_id <= 0 || !array_key_exists($dept, $dept_options) || $amount <= 0 || $expense_date === '') {
            flash_set('danger', 'Please fill all required fields correctly.');
            header('Location: expense_add.php');
            exit;
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO resort_expenses (category_id, dept, amount, note, expense_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isdssi', $category_id, $dept, $amount, $note, $expense_date, $user['id']);
        if (mysqli_stmt_execute($stmt)) {
            flash_set('success', 'Expense recorded successfully.');
        } else {
            flash_set('danger', 'Failed to record expense: ' . mysqli_error($conn));
        }

        header('Location: expense_add.php');
        exit;
    }
}

$categories_result = mysqli_query($conn, "SELECT id, category FROM resort_expenses_category WHERE is_active = 1 ORDER BY category ASC");
$categories_arr = [];
while ($c = mysqli_fetch_assoc($categories_result)) $categories_arr[] = $c;

// Today's expenses, most recent first — a quick running log on this same page.
$today_result = mysqli_query($conn, "
    SELECT e.id, e.amount, e.dept, e.note, e.expense_date, e.created_at, c.category AS category_name
    FROM resort_expenses e
    JOIN resort_expenses_category c ON c.id = e.category_id
    WHERE e.expense_date = CURDATE()
    ORDER BY e.created_at DESC
");
$today_arr = [];
$today_total = 0;
while ($row = mysqli_fetch_assoc($today_result)) {
    $today_arr[] = $row;
    $today_total += (float)$row['amount'];
}

$page_title = 'Add Expense';
$active_menu = 'expense_add';
require __DIR__ . '/navbar.php';
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-plus-circle me-1"></i> Record New Expense
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_expense">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                <span>Category <span class="text-danger">*</span></span>
                                <a href="expense_categories.php" class="small text-decoration-none">Manage categories</a>
                            </label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($categories_arr as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= e($c['category']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Department / Source <span class="text-danger">*</span></label>
                            <select name="dept" class="form-select" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($dept_options as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount (৳) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Note</label>
                            <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. Kitchen gas cylinder refill">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg me-1"></i>Save Expense</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-day me-1"></i> Today's Expenses</span>
                <span class="badge bg-success-subtle text-success">৳<?= number_format($today_total, 2) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Dept</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($today_arr)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No expenses recorded today.</td></tr>
                        <?php else: foreach ($today_arr as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($row['category_name']) ?></div>
                                    <?php if (!empty($row['note'])): ?>
                                        <div class="text-muted small"><?= e($row['note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-secondary"><?= e(ucfirst($row['dept'])) ?></span></td>
                                <td class="text-end fw-semibold">৳<?= number_format((float)$row['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>