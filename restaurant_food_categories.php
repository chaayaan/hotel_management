<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: restaurant_food_categories.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $type = trim($_POST['type'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($type === '') {
            flash_set('danger', 'Category name is required.');
            header('Location: restaurant_food_categories.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_food_categories (type, is_active, created_by) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sii', $type, $is_active, $user['id']);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Category added successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Category already exists.' : 'Failed to add category: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_categories SET type=?, is_active=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sii', $type, $is_active, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Category updated successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Category already exists.' : 'Failed to update category: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM restaurant_food_items WHERE food_category = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: {$count} food item(s) are using this category.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM restaurant_food_categories WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Category deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete category: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: restaurant_food_categories.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT c.*, (SELECT COUNT(*) FROM restaurant_food_items i WHERE i.food_category = c.id) AS item_count
        FROM restaurant_food_categories c WHERE 1=1";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND c.type LIKE '%{$searchEsc}%'";
}
$sql .= " ORDER BY c.type ASC";
$result = mysqli_query($conn, $sql);

$page_title = 'Food Categories';
$active_menu = 'restaurant_food_categories';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-tags me-1"></i> Food Categories</span>
        <div class="d-flex gap-2">
            <form class="d-flex" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search category..." value="<?= e($search) ?>">
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
                        <th>#</th>
                        <th>Category</th>
                        <th>Items</th>
                        <th>Active</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No categories found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= e($row['type']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark"><?= (int)$row['item_count'] ?></span></td>
                        <td>
                            <?php if ((int)$row['is_active'] === 1): ?>
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['type'])) ?>')">
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
                <h5 class="modal-title" id="modalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="type" id="formType" class="form-control" required maxlength="100" placeholder="e.g. Chinese">
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="formIsActive" class="form-check-input" checked>
                    <label class="form-check-label small" for="formIsActive">Active</label>
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
    document.getElementById('modalTitle').innerText = 'Add Category';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formType').value = '';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Category';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formType').value = row.type;
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
