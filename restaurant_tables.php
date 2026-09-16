<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$user = current_user();
$table_types = ['Family', 'VIP', 'Outdoor', 'Couple', 'Bar', 'Others'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: restaurant_tables.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $table_no = trim($_POST['table_no'] ?? '');
        $floor = trim($_POST['floor'] ?? '');
        $table_type = trim($_POST['table_type'] ?? 'Family');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($table_no === '') {
            flash_set('danger', 'Table number is required.');
            header('Location: restaurant_tables.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_tables (table_no, floor, table_type, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssii', $table_no, $floor, $table_type, $is_active, $user['id']);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Table added successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Table number already exists.' : 'Failed to add table: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE restaurant_tables SET table_no=?, floor=?, table_type=?, is_active=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sssii', $table_no, $floor, $table_type, $is_active, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Table updated successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Table number already exists.' : 'Failed to update table: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM restaurant_food_orders WHERE table_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: {$count} order record(s) reference this table. Consider deactivating it instead.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM restaurant_tables WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Table deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete table: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: restaurant_tables.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM restaurant_tables WHERE 1=1";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (table_no LIKE '%{$searchEsc}%' OR floor LIKE '%{$searchEsc}%' OR table_type LIKE '%{$searchEsc}%')";
}
$sql .= " ORDER BY CAST(table_no AS UNSIGNED), table_no ASC";
$result = mysqli_query($conn, $sql);

$page_title = 'Restaurant Tables';
$active_menu = 'restaurant_tables';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-grid-3x3-gap me-1"></i> Restaurant Tables</span>
        <div class="d-flex gap-2">
            <form class="d-flex" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search table..." value="<?= e($search) ?>">
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#tableModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Table
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Table No</th>
                        <th>Floor</th>
                        <th>Type</th>
                        <th>Active</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No tables found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['table_no']) ?></td>
                        <td><?= e($row['floor']) ?: '—' ?></td>
                        <td><span class="badge bg-info-subtle text-dark"><?= e($row['table_type']) ?></span></td>
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
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['table_no'])) ?>')">
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
<div class="modal fade" id="tableModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Table Number <span class="text-danger">*</span></label>
                        <input type="text" name="table_no" id="formTableNo" class="form-control" required maxlength="30" placeholder="e.g. T-01">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Floor</label>
                        <input type="text" name="floor" id="formFloor" class="form-control" maxlength="30" placeholder="e.g. Ground">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Table Type</label>
                        <select name="table_type" id="formTableType" class="form-select">
                            <?php foreach ($table_types as $tt): ?>
                                <option value="<?= e($tt) ?>"><?= e($tt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 d-flex align-items-end">
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
                Are you sure you want to delete table <strong id="deleteName"></strong>? This cannot be undone.
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
    document.getElementById('modalTitle').innerText = 'Add Table';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formTableNo').value = '';
    document.getElementById('formFloor').value = '';
    document.getElementById('formTableType').value = 'Family';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Table';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formTableNo').value = row.table_no;
    document.getElementById('formFloor').value = row.floor || '';
    document.getElementById('formTableType').value = row.table_type;
    document.getElementById('formIsActive').checked = row.is_active == 1;
    new bootstrap.Modal(document.getElementById('tableModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
