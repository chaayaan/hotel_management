<?php
require_once __DIR__ . '/auth.php';
require_login();

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_rooms.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $room_number = trim($_POST['room_number'] ?? '');
        $room_type_id = (int)($_POST['room_type_id'] ?? 0);
        $price_per_day = (float)($_POST['price_per_day'] ?? 0);
        $floor = trim($_POST['floor'] ?? '');
        $status = $_POST['status'] ?? 'available';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $valid_statuses = ['available', 'occupied', 'maintenance', 'out_of_service'];
        if (!in_array($status, $valid_statuses)) $status = 'available';

        if ($room_number === '' || $room_type_id <= 0 || $price_per_day < 0) {
            flash_set('danger', 'Please fill all required fields correctly.');
            header('Location: hotel_rooms.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO rooms (room_number, room_type_id, price_per_day, floor, status, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sidssi', $room_number, $room_type_id, $price_per_day, $floor, $status, $is_active);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room added successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Room number already exists.' : 'Failed to add room: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE rooms SET room_number=?, room_type_id=?, price_per_day=?, floor=?, status=?, is_active=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sidssii', $room_number, $room_type_id, $price_per_day, $floor, $status, $is_active, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room updated successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Room number already exists.' : 'Failed to update room: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM hotel_bookings WHERE room_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: this room has {$count} booking record(s). Consider deactivating it instead.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM rooms WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Room deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete room: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: hotel_rooms.php');
    exit;
}

// ---------- Fetch room types for dropdown ----------
$room_types = mysqli_query($conn, "SELECT id, name FROM room_types ORDER BY name ASC");
$room_types_arr = [];
while ($rt = mysqli_fetch_assoc($room_types)) $room_types_arr[] = $rt;

// ---------- Fetch list with filters ----------
$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_type = (int)($_GET['type'] ?? 0);

$sql = "SELECT r.*, rt.name AS type_name
        FROM rooms r
        JOIN room_types rt ON rt.id = r.room_type_id
        WHERE 1=1";

if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (r.room_number LIKE '%{$searchEsc}%' OR r.floor LIKE '%{$searchEsc}%')";
}
if ($filter_status !== '' && in_array($filter_status, ['available','occupied','maintenance','out_of_service'])) {
    $sql .= " AND r.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}
if ($filter_type > 0) {
    $sql .= " AND r.room_type_id = " . $filter_type;
}
$sql .= " ORDER BY r.room_number ASC";
$result = mysqli_query($conn, $sql);

$page_title = 'Rooms';
$active_menu = 'rooms';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-door-closed me-1"></i> Rooms</span>
        <div class="d-flex flex-wrap gap-2">
            <form class="d-flex flex-wrap gap-2" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search room / floor..." value="<?= e($search) ?>" style="width:170px;">
                <select name="status" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <?php foreach (['available','occupied','maintenance','out_of_service'] as $st): ?>
                        <option value="<?= $st ?>" <?= $filter_status === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$st))) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                    <option value="0">All Types</option>
                    <?php foreach ($room_types_arr as $rt): ?>
                        <option value="<?= (int)$rt['id'] ?>" <?= $filter_type === (int)$rt['id'] ? 'selected' : '' ?>><?= e($rt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Room
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Room #</th>
                        <th>Type</th>
                        <th>Price/Day</th>
                        <th>Floor</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No rooms found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['room_number']) ?></td>
                        <td><?= e($row['type_name']) ?></td>
                        <td>৳<?= number_format((float)$row['price_per_day'], 2) ?></td>
                        <td><?= e($row['floor']) ?: '—' ?></td>
                        <td><span class="badge badge-status-<?= e($row['status']) ?>"><?= e(ucwords(str_replace('_',' ',$row['status']))) ?></span></td>
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
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['room_number'])) ?>')">
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
<div class="modal fade" id="roomModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Room Number <span class="text-danger">*</span></label>
                        <input type="text" name="room_number" id="formRoomNumber" class="form-control" required maxlength="20">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Floor</label>
                        <input type="text" name="floor" id="formFloor" class="form-control" maxlength="20" placeholder="e.g. 1st Floor">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Room Type <span class="text-danger">*</span></label>
                        <select name="room_type_id" id="formRoomTypeId" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($room_types_arr as $rt): ?>
                                <option value="<?= (int)$rt['id'] ?>"><?= e($rt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Price / Day <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="price_per_day" id="formPrice" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" id="formStatus" class="form-select">
                            <option value="available">Available</option>
                            <option value="occupied">Occupied</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="out_of_service">Out of Service</option>
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
                Are you sure you want to delete room <strong id="deleteName"></strong>? This cannot be undone.
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
    document.getElementById('modalTitle').innerText = 'Add Room';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formRoomNumber').value = '';
    document.getElementById('formFloor').value = '';
    document.getElementById('formRoomTypeId').value = '';
    document.getElementById('formPrice').value = '';
    document.getElementById('formStatus').value = 'available';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Room';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formRoomNumber').value = row.room_number;
    document.getElementById('formFloor').value = row.floor || '';
    document.getElementById('formRoomTypeId').value = row.room_type_id;
    document.getElementById('formPrice').value = row.price_per_day;
    document.getElementById('formStatus').value = row.status;
    document.getElementById('formIsActive').checked = row.is_active == 1;
    new bootstrap.Modal(document.getElementById('roomModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
