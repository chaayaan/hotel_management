<?php
require_once __DIR__ . '/auth.php';
require_login();

// ---------- Handle POST actions (add/edit/delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_room_type.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            flash_set('danger', 'Room type name is required.');
            header('Location: hotel_room_type.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO room_types (name, description) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, 'ss', $name, $description);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room type added successfully.');
            } else {
                flash_set('danger', 'Failed to add room type: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE room_types SET name = ?, description = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ssi', $name, $description, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room type updated successfully.');
            } else {
                flash_set('danger', 'Failed to update room type: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM rooms WHERE room_type_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: {$count} room(s) are using this room type.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM room_types WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room type deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete room type: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: hotel_room_type.php');
    exit;
}

// ---------- Fetch list ----------
$search = trim($_GET['q'] ?? '');
$sql = "SELECT rt.*, (SELECT COUNT(*) FROM rooms r WHERE r.room_type_id = rt.id) AS room_count
        FROM room_types rt";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " WHERE rt.name LIKE '%{$searchEsc}%' OR rt.description LIKE '%{$searchEsc}%'";
}
$sql .= " ORDER BY rt.id DESC";
$result = mysqli_query($conn, $sql);

$page_title = 'Room Types';
$active_menu = 'room_types';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-grid-3x3-gap me-1"></i> Room Types</span>
        <div class="d-flex gap-2">
            <form class="d-flex" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search room type..." value="<?= e($search) ?>">
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#roomTypeModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Room Type
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Rooms</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No room types found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= e($row['name']) ?></td>
                        <td class="text-muted"><?= e($row['description']) ?: '—' ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark"><?= (int)$row['room_count'] ?></span></td>
                        <td class="text-muted small"><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['name'])) ?>')">
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
<div class="modal fade" id="roomTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Room Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="formName" class="form-control" required maxlength="100">
                </div>
                <div class="mb-1">
                    <label class="form-label small fw-semibold">Description</label>
                    <textarea name="description" id="formDescription" class="form-control" rows="3" maxlength="255"></textarea>
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
    document.getElementById('modalTitle').innerText = 'Add Room Type';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formDescription').value = '';
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Room Type';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formName').value = row.name;
    document.getElementById('formDescription').value = row.description || '';
    new bootstrap.Modal(document.getElementById('roomTypeModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
