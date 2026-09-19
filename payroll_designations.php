<?php
/**
 * Payroll > Designations
 * The fixed list of job titles per department (Hotel / Restaurant / Resort).
 * Employees must pick one of these - they can no longer type a free-text designation.
 * Single file: list + filter (GET) | add/edit (POST action=save) | delete (POST action=delete)
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Designations';
$active_menu = 'payroll_designations';

$departments = payrollDepartments();
$form        = ['id' => 0, 'name' => '', 'department' => '', 'status' => 'Active'];
$formError   = '';
$reopenModal = false;

/* ---------- Handle writes ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Your session expired. Please try again.');
        header('Location: payroll_designations.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int) ($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $department = $_POST['department'] ?? '';
        $status     = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';

        if ($name === '' || !in_array($department, $departments, true)) {
            $formError = 'Enter a designation name and choose a department.';
        } else {
            $dup = $conn->prepare("SELECT id FROM payroll_designations WHERE name = ? AND department = ? AND id <> ?");
            $dup->bind_param('ssi', $name, $department, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $formError = '“' . $name . '” already exists in ' . $department . '.';
            }
        }

        if ($formError !== '') {
            $form        = ['id' => $id, 'name' => $name, 'department' => $department, 'status' => $status];
            $reopenModal = true;
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE payroll_designations SET name = ?, department = ?, status = ? WHERE id = ?");
                $stmt->bind_param('sssi', $name, $department, $status, $id);
                $stmt->execute();
                flash_set('success', 'Designation updated.');
            } else {
                $stmt = $conn->prepare("INSERT INTO payroll_designations (name, department, status) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $name, $department, $status);
                $stmt->execute();
                flash_set('success', 'Designation added.');
            }
            header('Location: payroll_designations.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $chk = $conn->prepare("SELECT COUNT(*) c FROM payroll_employees WHERE designation_id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $inUse = (int) $chk->get_result()->fetch_assoc()['c'];

            if ($inUse > 0) {
                flash_set('danger', "That designation is used by $inUse employee(s). Reassign them or set the designation to Inactive instead.");
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM payroll_designations WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    flash_set('success', 'Designation deleted.');
                } catch (Throwable $ex) {
                    flash_set('danger', 'This designation could not be deleted.');
                }
            }
        }
        header('Location: payroll_designations.php');
        exit;
    }
}

/* ---------- Read ---------- */
$deptFilter = $_GET['department'] ?? '';
if (!in_array($deptFilter, $departments, true)) {
    $deptFilter = '';
}

$sql = "
    SELECT d.*, COUNT(e.id) AS emp_count
    FROM payroll_designations d
    LEFT JOIN payroll_employees e ON e.designation_id = d.id
";
if ($deptFilter !== '') {
    $sql .= " WHERE d.department = ?";
}
$sql .= " GROUP BY d.id ORDER BY FIELD(d.department, 'Hotel', 'Restaurant', 'Resort'), d.name";

$stmt = $conn->prepare($sql);
if ($deptFilter !== '') {
    $stmt->bind_param('s', $deptFilter);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="btn-group">
    <a href="payroll_designations.php" class="btn <?= $deptFilter === '' ? 'btn-brand' : 'btn-outline-brand' ?>">All</a>
    <?php foreach ($departments as $dept): ?>
      <a href="?department=<?= urlencode($dept) ?>" class="btn <?= $deptFilter === $dept ? 'btn-brand' : 'btn-outline-brand' ?>"><?= e($dept) ?></a>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-brand" id="btnAddDesignation">
    <i class="bi bi-plus-lg me-1"></i>Add designation
  </button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Designation</th>
          <th>Department</th>
          <th class="text-center">Employees</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">No designations here yet. Use “Add designation” to create one.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $d): $used = (int) $d['emp_count']; ?>
          <tr>
            <td class="fw-semibold"><?= e($d['name']) ?></td>
            <td><?= departmentBadge($d['department']) ?></td>
            <td class="text-center"><?= $used ?></td>
            <td><span class="badge <?= $d['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= e($d['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-brand btn-edit"
                      data-id="<?= (int) $d['id'] ?>"
                      data-name="<?= e($d['name']) ?>"
                      data-department="<?= e($d['department']) ?>"
                      data-status="<?= e($d['status']) ?>">
                <i class="bi bi-pencil-square me-1"></i>Edit
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger btn-delete"
                      data-id="<?= (int) $d['id'] ?>"
                      data-name="<?= e($d['name']) ?>"
                      <?= $used > 0 ? 'disabled title="Used by ' . $used . ' employee(s). Set it to Inactive instead."' : '' ?>>
                <i class="bi bi-trash me-1"></i>Delete
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-2">Inactive designations stay on existing employees but can’t be chosen for new ones.</p>

<!-- ===== Add / Edit modal ===== -->
<div class="modal fade" id="designationModal" tabindex="-1" aria-labelledby="designationModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="desId" value="<?= (int) $form['id'] ?>">

      <div class="modal-header">
        <h5 class="modal-title" id="designationModalTitle"><?= $form['id'] ? 'Edit designation' : 'Add designation' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($formError): ?>
          <div class="alert alert-danger py-2"><?= e($formError) ?></div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label" for="desName">Designation name</label>
          <input type="text" name="name" id="desName" class="form-control" required maxlength="100" value="<?= e($form['name']) ?>">
        </div>
        <div class="row">
          <div class="col-sm-7 mb-3">
            <label class="form-label" for="desDepartment">Department</label>
            <select name="department" id="desDepartment" class="form-select" required>
              <option value="">Select department</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept) ?>" <?= $form['department'] === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-5 mb-3">
            <label class="form-label" for="desStatus">Status</label>
            <select name="status" id="desStatus" class="form-select">
              <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
              <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand" id="desSubmit"><?= $form['id'] ? 'Save changes' : 'Add designation' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Delete confirm modal ===== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="deleteId" value="">

      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalTitle">Delete designation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Delete <strong id="deleteName"></strong>? No employee uses it.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep designation</button>
        <button type="submit" class="btn btn-danger">Delete designation</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var desModal = new bootstrap.Modal(document.getElementById('designationModal'));
    var delModal = new bootstrap.Modal(document.getElementById('deleteModal'));

    function fillForm(id, name, department, status) {
        document.getElementById('desId').value = id;
        document.getElementById('desName').value = name;
        document.getElementById('desDepartment').value = department;
        document.getElementById('desStatus').value = status;
        document.getElementById('designationModalTitle').textContent = id ? 'Edit designation' : 'Add designation';
        document.getElementById('desSubmit').textContent = id ? 'Save changes' : 'Add designation';
        var err = document.querySelector('#designationModal .alert-danger');
        if (err) err.remove();
    }

    document.getElementById('btnAddDesignation').addEventListener('click', function () {
        // Pre-select the department tab the user is looking at
        fillForm(0, '', <?= json_encode($deptFilter) ?>, 'Active');
        desModal.show();
    });

    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fillForm(btn.dataset.id, btn.dataset.name, btn.dataset.department, btn.dataset.status);
            desModal.show();
        });
    });

    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('deleteId').value = btn.dataset.id;
            document.getElementById('deleteName').textContent = btn.dataset.name;
            delModal.show();
        });
    });

    <?php if ($reopenModal): ?>
    desModal.show();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
