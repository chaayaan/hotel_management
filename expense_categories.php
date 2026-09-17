<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: expense_categories.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $category = trim($_POST['category'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($category === '') {
            flash_set('danger', 'Category name is required.');
            header('Location: expense_categories.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO resort_expenses_category (category, is_active, created_by) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sii', $category, $is_active, $user['id']);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Expense category added successfully.');
            } else {
                if (mysqli_errno($conn) === 1062) {
                    flash_set('danger', 'This category already exists.');
                } else {
                    flash_set('danger', 'Failed to add category: ' . mysqli_error($conn));
                }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE resort_expenses_category SET category=?, is_active=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sii', $category, $is_active, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Expense category updated successfully.');
            } else {
                if (mysqli_errno($conn) === 1062) {
                    flash_set('danger', 'This category already exists.');
                } else {
                    flash_set('danger', 'Failed to update category: ' . mysqli_error($conn));
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM resort_expenses WHERE category_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: this category has {$count} expense record(s). Consider deactivating it instead.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM resort_expenses_category WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Expense category deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete category: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: expense_categories.php');
    exit;
}

$search = trim($_GET['q'] ?? '');

$sql = "SELECT c.*,
        (SELECT COUNT(*) FROM resort_expenses e WHERE e.category_id = c.id) AS expense_count,
        (SELECT COALESCE(SUM(e.amount), 0) FROM resort_expenses e WHERE e.category_id = c.id) AS total_spent
        FROM resort_expenses_category c
        WHERE 1=1";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND c.category LIKE '%{$searchEsc}%'";
}
$sql .= " ORDER BY c.category ASC";
$result = mysqli_query($conn, $sql);

$page_title = 'Expense Categories';
$active_menu = 'expense_categories';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-tags me-1"></i> Expense Categories</span>
        <div class="d-flex flex-wrap gap-2">
            <form class="d-flex gap-2" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search category..." value="<?= e($search) ?>" style="width:190px;">
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Category
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-end">Expenses Logged</th>
                        <th class="text-end">Total Spent</th>
                        <th>Active</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No expense categories found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['category']) ?></td>
                        <td class="text-end"><?= (int)$row['expense_count'] ?></td>
                        <td class="text-end">৳<?= number_format((float)$row['total_spent'], 2) ?></td>
                        <td>
                            <?php if ((int)$row['is_active'] === 1): ?>
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['category'])) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Expense Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category" id="formCategory" class="form-control" required maxlength="150" placeholder="e.g. Utilities, Maintenance, Staff Salary">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="formIsActive" class="form-check-input" checked>
                            <label class="form-check-label small" for="formIsActive">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand btn-sm">Save</button>
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
                Are you sure you want to delete <strong id="deleteName"></strong>? This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').innerText = 'Add Expense Category';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formCategory').value = '';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Expense Category';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formCategory').value = row.category;
    document.getElementById('formIsActive').checked = row.is_active == 1;
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>