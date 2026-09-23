<?php
/**
 * Payroll > Designations
 * The list of job titles per department. Departments themselves are managed
 * on the Departments page (payroll_departments.php); this page just links
 * a designation to one via department_id.
 * Employees must pick one of these - they can no longer type a free-text designation.
 * Single file: list + filter (GET) | add/edit (POST action=save) | delete (POST action=delete)
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Designations';
$active_menu = 'payroll_designations';

$departments   = payrollDepartments($conn);               // all, for the filter bar (so inactive ones still show existing data)
$activeDepartments = array_filter($departments, fn($d) => $d['status'] === 'Active'); // for the add/edit dropdown
$deptIds       = array_column($departments, 'id');
$form        = ['id' => 0, 'name' => '', 'department_id' => 0, 'status' => 'Active'];
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
        $id           = (int) ($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $departmentId = (int) ($_POST['department_id'] ?? 0);

        // Department must exist. Allow the employee's current department even if it's
        // since gone Inactive, same pattern used for designations on the employees page.
        $deptRow = null;
        if ($departmentId > 0) {
            $dsq = $conn->prepare("SELECT id, status FROM payroll_departments WHERE id = ?");
            $dsq->bind_param('i', $departmentId);
            $dsq->execute();
            $deptRow = $dsq->get_result()->fetch_assoc();
        }

        $status = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';

        if ($name === '' || !$deptRow) {
            $formError = 'Enter a designation name and choose a department.';
        } else {
            $dup = $conn->prepare("SELECT id FROM payroll_designations WHERE name = ? AND department_id = ? AND id <> ?");
            $dup->bind_param('sii', $name, $departmentId, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $formError = '“' . $name . '” already exists in that department.';
            }
        }

        if ($formError !== '') {
            $form        = ['id' => $id, 'name' => $name, 'department_id' => $departmentId, 'status' => $status];
            $reopenModal = true;
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE payroll_designations SET name = ?, department_id = ?, status = ? WHERE id = ?");
                $stmt->bind_param('sisi', $name, $departmentId, $status, $id);
                $stmt->execute();
                flash_set('success', 'Designation updated.');
            } else {
                $stmt = $conn->prepare("INSERT INTO payroll_designations (name, department_id, status) VALUES (?, ?, ?)");
                $stmt->bind_param('sis', $name, $departmentId, $status);
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
$deptFilter = (int) ($_GET['department'] ?? 0);
if (!in_array($deptFilter, $deptIds, true)) {
    $deptFilter = 0;
}

$sql = "
    SELECT d.*, dept.name AS department, dept.id AS department_id, COUNT(e.id) AS emp_count
    FROM payroll_designations d
    LEFT JOIN payroll_departments dept ON dept.id = d.department_id
    LEFT JOIN payroll_employees e ON e.designation_id = d.id
";
if ($deptFilter !== 0) {
    $sql .= " WHERE d.department_id = ?";
}
$sql .= " GROUP BY d.id ORDER BY dept.name, d.name";

$stmt = $conn->prepare($sql);
if ($deptFilter !== 0) {
    $stmt->bind_param('i', $deptFilter);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="btn-group flex-wrap">
    <a href="payroll_designations.php" class="btn <?= $deptFilter === 0 ? 'btn-brand' : 'btn-outline-brand' ?>">All</a>
    <?php foreach ($departments as $dept): ?>
      <a href="?department=<?= (int) $dept['id'] ?>" class="btn <?= $deptFilter === (int) $dept['id'] ? 'btn-brand' : 'btn-outline-brand' ?>"><?= e($dept['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2">
    <a href="payroll_departments.php" class="btn btn-outline-brand">
      <i class="bi bi-diagram-3 me-1"></i>Manage departments
    </a>
    <button type="button" class="btn btn-brand" id="btnAddDesignation">
      <i class="bi bi-plus-lg me-1"></i>Add designation
    </button>
  </div>
</div>

<?php if (!$departments): ?>
  <div class="alert alert-warning">No departments yet. <a href="payroll_departments.php">Add one</a> before creating designations.</div>
<?php endif; ?>

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
            <td><?= departmentBadge($d['department'], $d['department_id']) ?></td>
            <td class="text-center"><?= $used ?></td>
            <td><span class="badge <?= $d['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= e($d['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-brand btn-edit"
                      data-id="<?= (int) $d['id'] ?>"
                      data-name="<?= e($d['name']) ?>"
                      data-department-id="<?= (int) $d['department_id'] ?>"
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
            <select name="department_id" id="desDepartment" class="form-select" required>
              <option value="">Select department</option>
              <?php foreach ($activeDepartments as $dept): ?>
                <option value="<?= (int) $dept['id'] ?>" <?= (int) $form['department_id'] === (int) $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Missing one? <a href="payroll_departments.php">Manage departments</a>.</div>
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

    function fillForm(id, name, departmentId, status) {
        document.getElementById('desId').value = id;
        document.getElementById('desName').value = name;
        document.getElementById('desDepartment').value = departmentId;
        document.getElementById('desStatus').value = status;
        document.getElementById('designationModalTitle').textContent = id ? 'Edit designation' : 'Add designation';
        document.getElementById('desSubmit').textContent = id ? 'Save changes' : 'Add designation';
        var err = document.querySelector('#designationModal .alert-danger');
        if (err) err.remove();
    }

    document.getElementById('btnAddDesignation').addEventListener('click', function () {
        // Pre-select the department tab the user is looking at
        fillForm(0, '', <?= json_encode($deptFilter ?: '') ?>, 'Active');
        desModal.show();
    });

    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fillForm(btn.dataset.id, btn.dataset.name, btn.dataset.departmentId, btn.dataset.status);
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