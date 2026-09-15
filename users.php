<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);

$roles = ['admin','general_manager','hotel_desk_manager','restaurant_desk_manager','staff'];

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $designation = $_POST['designation'] ?? 'staff';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        if (!in_array($designation, $roles)) $designation = 'staff';

        if ($username === '' || $full_name === '') {
            flash_set('danger', 'Username and full name are required.');
            header('Location: users.php');
            exit;
        }

        if ($action === 'create') {
            if ($password === '' || strlen($password) < 6) {
                flash_set('danger', 'Password is required and must be at least 6 characters.');
                header('Location: users.php');
                exit;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password_hash, full_name, designation, is_active) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssi', $username, $hash, $full_name, $designation, $is_active);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'User added successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Username already exists.' : 'Failed to add user: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);

            // Prevent locking yourself out
            if ($id === (int)current_user()['id'] && $is_active === 0) {
                flash_set('danger', 'You cannot deactivate your own account.');
                header('Location: users.php');
                exit;
            }

            if ($password !== '') {
                if (strlen($password) < 6) {
                    flash_set('danger', 'Password must be at least 6 characters.');
                    header('Location: users.php');
                    exit;
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, "UPDATE users SET username=?, password_hash=?, full_name=?, designation=?, is_active=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'ssssii', $username, $hash, $full_name, $designation, $is_active, $id);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE users SET username=?, full_name=?, designation=?, is_active=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'sssii', $username, $full_name, $designation, $is_active, $id);
            }

            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'User updated successfully.');
            } else {
                flash_set('danger', mysqli_errno($conn) === 1062 ? 'Username already exists.' : 'Failed to update user: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)current_user()['id']) {
            flash_set('danger', 'You cannot delete your own account.');
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'User deleted successfully.');
            } else {
                flash_set('danger', 'Cannot delete: user has related records (bookings, payments, etc). Deactivate instead.');
            }
        }
    }

    header('Location: users.php');
    exit;
}

// ---------- Fetch list ----------
$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM users WHERE 1=1";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (username LIKE '%{$searchEsc}%' OR full_name LIKE '%{$searchEsc}%')";
}
$sql .= " ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

$page_title = 'Users';
$active_menu = 'users';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-people me-1"></i> Users</span>
        <div class="d-flex gap-2">
            <form class="d-flex" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search user..." value="<?= e($search) ?>">
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add User
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= e($row['username']) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><span class="badge bg-info-subtle text-dark"><?= e(ucwords(str_replace('_',' ',$row['designation']))) ?></span></td>
                        <td>
                            <?php if ((int)$row['is_active'] === 1): ?>
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= $row['last_login_at'] ? e(date('d M Y, h:i A', strtotime($row['last_login_at']))) : 'Never' ?></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php if ((int)$row['id'] !== (int)current_user()['id']): ?>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['username'])) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="formUsername" class="form-control" required maxlength="50">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="formFullName" class="form-control" required maxlength="150">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Role</label>
                        <select name="designation" id="formDesignation" class="form-select">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r ?>"><?= e(ucwords(str_replace('_',' ',$r))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">
                            Password <span id="passwordRequired" class="text-danger">*</span>
                        </label>
                        <input type="password" name="password" id="formPassword" class="form-control" minlength="6" placeholder="Min 6 characters">
                        <div class="form-text" id="passwordHint" style="display:none;">Leave blank to keep current password.</div>
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
                Are you sure you want to delete user <strong id="deleteName"></strong>? This cannot be undone.
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
    document.getElementById('modalTitle').innerText = 'Add User';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formUsername').value = '';
    document.getElementById('formFullName').value = '';
    document.getElementById('formDesignation').value = 'staff';
    document.getElementById('formPassword').value = '';
    document.getElementById('formPassword').required = true;
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordHint').style.display = 'none';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit User';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formUsername').value = row.username;
    document.getElementById('formFullName').value = row.full_name;
    document.getElementById('formDesignation').value = row.designation;
    document.getElementById('formPassword').value = '';
    document.getElementById('formPassword').required = false;
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordHint').style.display = 'block';
    document.getElementById('formIsActive').checked = row.is_active == 1;
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
