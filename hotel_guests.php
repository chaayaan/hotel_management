<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_helpers.php';
require_login();

// ---------- AJAX: search guests (used by reservation guest picker) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $out = [];
    if ($q !== '') {
        $qEsc = '%' . mysqli_real_escape_string($conn, $q) . '%';
        $stmt = mysqli_prepare($conn, "SELECT id, full_name, phone, email, id_proof_type, id_proof_number, address
                                        FROM guests WHERE full_name LIKE ? OR phone LIKE ? LIMIT 10");
        mysqli_stmt_bind_param($stmt, 'ss', $qEsc, $qEsc);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    }
    echo json_encode($out);
    exit;
}

// ---------- AJAX: quick-create guest (used by reservation "new guest" form) ----------
if (isset($_POST['ajax']) && $_POST['ajax'] === 'create_guest') {
    header('Content-Type: application/json');
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
        exit;
    }
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_proof_type = trim($_POST['id_proof_type'] ?? '');
    $id_proof_number = trim($_POST['id_proof_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($full_name === '' || $phone === '') {
        echo json_encode(['ok' => false, 'error' => 'Name and phone are required.']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO guests (full_name, phone, email, id_proof_type, id_proof_number, address) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssssss', $full_name, $phone, $email, $id_proof_type, $id_proof_number, $address);
    if (mysqli_stmt_execute($stmt)) {
        $id = mysqli_insert_id($conn);
        echo json_encode(['ok' => true, 'guest' => [
            'id' => $id, 'full_name' => $full_name, 'phone' => $phone, 'email' => $email,
            'id_proof_type' => $id_proof_type, 'id_proof_number' => $id_proof_number, 'address' => $address,
        ]]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Failed to add guest: ' . mysqli_error($conn)]);
    }
    exit;
}

// ---------- Handle POST actions (add/edit/delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_guests.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $id_proof_type = trim($_POST['id_proof_type'] ?? '');
        $id_proof_number = trim($_POST['id_proof_number'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($full_name === '' || $phone === '') {
            flash_set('danger', 'Guest name and phone are required.');
            header('Location: hotel_guests.php');
            exit;
        }

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO guests (full_name, phone, email, id_proof_type, id_proof_number, address) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssss', $full_name, $phone, $email, $id_proof_type, $id_proof_number, $address);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Guest added successfully.');
            } else {
                flash_set('danger', 'Failed to add guest: ' . mysqli_error($conn));
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, "UPDATE guests SET full_name=?, phone=?, email=?, id_proof_type=?, id_proof_number=?, address=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssssssi', $full_name, $phone, $email, $id_proof_type, $id_proof_number, $address, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Guest updated successfully.');
            } else {
                flash_set('danger', 'Failed to update guest: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM hotel_bookings WHERE guest_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: {$count} booking(s) are linked to this guest.");
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM guests WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Guest deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete guest: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: hotel_guests.php');
    exit;
}

// ---------- Fetch list ----------
$search = trim($_GET['q'] ?? '');
$sql = "SELECT g.*, (SELECT COUNT(*) FROM hotel_bookings b WHERE b.guest_id = g.id) AS booking_count
        FROM guests g";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " WHERE g.full_name LIKE '%{$searchEsc}%' OR g.phone LIKE '%{$searchEsc}%' OR g.email LIKE '%{$searchEsc}%'";
}
$sql .= " ORDER BY g.id DESC";
$result = mysqli_query($conn, $sql);

$page_title = 'Guests';
$active_menu = 'guests';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-person-vcard me-1"></i> Guests</span>
        <div class="d-flex gap-2">
            <form class="d-flex" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name / phone / email..." value="<?= e($search) ?>" style="width:220px;">
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#guestModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Guest
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>ID Proof</th>
                        <th>Bookings</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No guests found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= e($row['full_name']) ?></td>
                        <td><?= e($row['phone']) ?></td>
                        <td class="text-muted"><?= e($row['email']) ?: '—' ?></td>
                        <td class="text-muted small"><?= e(trim(($row['id_proof_type'] ?? '') . ' ' . ($row['id_proof_number'] ?? ''))) ?: '—' ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark"><?= (int)$row['booking_count'] ?></span></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['full_name'])) ?>')">
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
<div class="modal fade" id="guestModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Guest</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="formFullName" class="form-control" required maxlength="150">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="phone" id="formPhone" class="form-control" required maxlength="30">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" name="email" id="formEmail" class="form-control" maxlength="150">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">ID Proof Type</label>
                        <input type="text" name="id_proof_type" id="formIdProofType" class="form-control" maxlength="50" placeholder="e.g. NID / Passport">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">ID Proof Number</label>
                        <input type="text" name="id_proof_number" id="formIdProofNumber" class="form-control" maxlength="100">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Address</label>
                        <input type="text" name="address" id="formAddress" class="form-control" maxlength="255">
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
                Are you sure you want to delete guest <strong id="deleteName"></strong>? This cannot be undone.
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
    document.getElementById('modalTitle').innerText = 'Add Guest';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formFullName').value = '';
    document.getElementById('formPhone').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formIdProofType').value = '';
    document.getElementById('formIdProofNumber').value = '';
    document.getElementById('formAddress').value = '';
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Guest';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formFullName').value = row.full_name;
    document.getElementById('formPhone').value = row.phone;
    document.getElementById('formEmail').value = row.email || '';
    document.getElementById('formIdProofType').value = row.id_proof_type || '';
    document.getElementById('formIdProofNumber').value = row.id_proof_number || '';
    document.getElementById('formAddress').value = row.address || '';
    new bootstrap.Modal(document.getElementById('guestModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>