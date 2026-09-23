<?php
/**
 * Payroll > Employees
 * One file handles the whole CRUD cycle:
 *   list + search/filter (GET) | add + edit (POST action=save) | delete (POST action=delete)
 * Designation is MANDATORY and must be picked from payroll_designations
 * (manage that list on the Designations page).
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Employees';
$active_menu = 'payroll_employees';

$departments = payrollDepartments($conn); // all, for filter bar
$deptIds     = array_column($departments, 'id');
$form        = ['id' => 0, 'name' => '', 'designation_id' => 0, 'monthly_salary' => '', 'status' => 'Active'];
$formError   = '';
$reopenModal = false;

/* ---------- Handle writes (must run before any HTML output) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Your session expired. Please try again.');
        header('Location: payroll_employees.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = (int) ($_POST['id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $designationId = (int) ($_POST['designation_id'] ?? 0);
        $salary        = (float) ($_POST['monthly_salary'] ?? 0);
        $status        = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';

        // Designation must exist, and be active unless the employee already holds it
        $desig = null;
        if ($designationId > 0) {
            $ds = $conn->prepare("SELECT id, status FROM payroll_designations WHERE id = ?");
            $ds->bind_param('i', $designationId);
            $ds->execute();
            $desig = $ds->get_result()->fetch_assoc();
        }
        $currentDesignation = 0;
        if ($id > 0) {
            $cs = $conn->prepare("SELECT designation_id FROM payroll_employees WHERE id = ?");
            $cs->bind_param('i', $id);
            $cs->execute();
            $currentDesignation = (int) ($cs->get_result()->fetch_assoc()['designation_id'] ?? 0);
        }

        if ($name === '' || $salary <= 0) {
            $formError = 'Enter a name and a monthly salary greater than 0.';
        } elseif (!$desig) {
            $formError = 'Select a designation. It is required for every employee.';
        } elseif ($desig['status'] !== 'Active' && $currentDesignation !== $designationId) {
            $formError = 'That designation is inactive. Choose an active one.';
        }

        if ($formError !== '') {
            $form        = ['id' => $id, 'name' => $name, 'designation_id' => $designationId, 'monthly_salary' => $_POST['monthly_salary'] ?? '', 'status' => $status];
            $reopenModal = true;
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE payroll_employees SET name = ?, designation_id = ?, monthly_salary = ?, status = ? WHERE id = ?");
                $stmt->bind_param('sidsi', $name, $designationId, $salary, $status, $id);
                $stmt->execute();
                flash_set('success', 'Employee updated.');
            } else {
                $stmt = $conn->prepare("INSERT INTO payroll_employees (name, designation_id, monthly_salary, status) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('sids', $name, $designationId, $salary, $status);
                $stmt->execute();
                flash_set('success', 'Employee added.');
            }
            header('Location: payroll_employees.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM payroll_employees WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                flash_set('success', 'Employee deleted.');
            } catch (Throwable $ex) {
                flash_set('danger', 'This employee could not be deleted. Set the status to Inactive instead.');
            }
        }
        header('Location: payroll_employees.php');
        exit;
    }
}

/* ---------- Designation dropdown data (active only, grouped by department) ---------- */
$desigRows = $conn->query("
    SELECT d.id, d.name, dept.name AS department
    FROM payroll_designations d
    LEFT JOIN payroll_departments dept ON dept.id = d.department_id
    WHERE d.status = 'Active'
    ORDER BY dept.name, d.name
")->fetch_all(MYSQLI_ASSOC);
$desigByDept = [];
foreach ($desigRows as $d) {
    $desigByDept[$d['department'] ?? 'Unassigned'][] = $d;
}

/* ---------- Read: list with optional search + department + status filter ---------- */
$q            = trim($_GET['q'] ?? '');
$deptFilter   = (int) ($_GET['department'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT e.*, d.name AS designation_name, dept.name AS department, dept.id AS department_id
    FROM payroll_employees e
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    LEFT JOIN payroll_departments dept ON dept.id = d.department_id
    WHERE 1=1
";
$params = [];
$types  = '';
if ($q !== '') {
    $sql     .= " AND (e.name LIKE ? OR d.name LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if (in_array($deptFilter, $deptIds, true)) {
    $sql     .= " AND dept.id = ?";
    $params[] = $deptFilter;
    $types   .= 'i';
} else {
    $deptFilter = 0;
}
if (in_array($statusFilter, ['Active', 'Inactive'], true)) {
    $sql     .= " AND e.status = ?";
    $params[] = $statusFilter;
    $types   .= 's';
} else {
    $statusFilter = '';
}
$sql .= " ORDER BY e.name ASC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$filtered = ($q !== '' || $deptFilter !== 0 || $statusFilter !== '');

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <div class="input-group" style="max-width:260px;">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input type="text" name="q" class="form-control" placeholder="Search name or designation" value="<?= e($q) ?>">
    </div>
    <select name="department" class="form-select" style="width:auto;" onchange="this.form.submit()">
      <option value="">All departments</option>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= (int) $dept['id'] ?>" <?= $deptFilter === (int) $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:auto;" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
      <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <button class="btn btn-outline-brand">Search</button>
    <?php if ($filtered): ?>
      <a href="payroll_employees.php" class="btn btn-link text-muted">Clear</a>
    <?php endif; ?>
  </form>
  <button type="button" class="btn btn-brand" id="btnAddEmployee">
    <i class="bi bi-plus-lg me-1"></i>Add employee
  </button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Designation</th>
          <th>Department</th>
          <th>Monthly salary</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$employees): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <?= $filtered ? 'No employees match your filters.' : 'No employees yet. Use “Add employee” to create the first one.' ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $emp): ?>
          <tr>
            <td class="fw-semibold"><?= e($emp['name']) ?></td>
            <td>
              <?php if ($emp['designation_name']): ?>
                <?= e($emp['designation_name']) ?>
              <?php else: ?>
                <span class="badge bg-warning-subtle text-warning-emphasis">Not assigned</span>
              <?php endif; ?>
            </td>
            <td><?= departmentBadge($emp['department'], $emp['department_id']) ?></td>
            <td><?= money($emp['monthly_salary']) ?></td>
            <td><span class="badge <?= $emp['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= e($emp['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-brand btn-edit"
                      data-id="<?= (int) $emp['id'] ?>"
                      data-name="<?= e($emp['name']) ?>"
                      data-designation-id="<?= (int) $emp['designation_id'] ?>"
                      data-designation-label="<?= e(($emp['designation_name'] ?? '') . ($emp['department'] ? ' (' . $emp['department'] . ')' : '')) ?>"
                      data-salary="<?= e($emp['monthly_salary']) ?>"
                      data-status="<?= e($emp['status']) ?>">
                <i class="bi bi-pencil-square me-1"></i>Edit
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger btn-delete"
                      data-id="<?= (int) $emp['id'] ?>"
                      data-name="<?= e($emp['name']) ?>">
                <i class="bi bi-trash me-1"></i>Delete
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== Add / Edit modal ===== -->
<div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="empId" value="<?= (int) $form['id'] ?>">

      <div class="modal-header">
        <h5 class="modal-title" id="employeeModalTitle"><?= $form['id'] ? 'Edit employee' : 'Add employee' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($formError): ?>
          <div class="alert alert-danger py-2"><?= e($formError) ?></div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label" for="empName">Full name</label>
          <input type="text" name="name" id="empName" class="form-control" required value="<?= e($form['name']) ?>">
        </div>

        <div class="mb-3">
          <label class="form-label" for="empDesignation">Designation</label>
          <select name="designation_id" id="empDesignation" class="form-select" required>
            <option value="">Select designation</option>
            <?php foreach ($desigByDept as $dept => $items): ?>
              <optgroup label="<?= e($dept) ?>">
                <?php foreach ($items as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" <?= (int) $form['designation_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            <?php if (!$desigRows): ?>
              <span class="text-danger">No active designations yet.</span>
            <?php endif; ?>
            Missing one? <a href="payroll_designations.php">Manage designations</a>.
          </div>
        </div>

        <div class="row">
          <div class="col-sm-7 mb-3">
            <label class="form-label" for="empSalary">Monthly salary (<?= currencySymbol() ?>)</label>
            <input type="number" step="0.01" min="0" name="monthly_salary" id="empSalary" class="form-control" required value="<?= e($form['monthly_salary']) ?>">
          </div>
          <div class="col-sm-5 mb-3">
            <label class="form-label" for="empStatus">Status</label>
            <select name="status" id="empStatus" class="form-select">
              <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
              <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand" id="empSubmit"><?= $form['id'] ? 'Save changes' : 'Add employee' ?></button>
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
        <h5 class="modal-title" id="deleteModalTitle">Delete employee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Delete <strong id="deleteName"></strong>?</p>
        <p class="text-muted small mb-0">Their attendance, payroll and payment records are removed too. To keep the history, set the employee to Inactive instead.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep employee</button>
        <button type="submit" class="btn btn-danger">Delete employee</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var empModal = new bootstrap.Modal(document.getElementById('employeeModal'));
    var delModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    var desSelect = document.getElementById('empDesignation');

    function fillForm(id, name, designationId, designationLabel, salary, status) {
        document.getElementById('empId').value = id;
        document.getElementById('empName').value = name;
        document.getElementById('empSalary').value = salary;
        document.getElementById('empStatus').value = status;

        // An employee may hold a designation that has since been set to Inactive:
        // it isn't in the list, so add it temporarily so the form keeps it.
        desSelect.querySelectorAll('.tmp-opt').forEach(function (o) { o.remove(); });
        if (designationId && designationId !== '0' && !desSelect.querySelector('option[value="' + designationId + '"]')) {
            var opt = document.createElement('option');
            opt.value = designationId;
            opt.textContent = designationLabel + ' (inactive)';
            opt.className = 'tmp-opt';
            desSelect.appendChild(opt);
        }
        desSelect.value = (designationId && designationId !== '0') ? designationId : '';

        document.getElementById('employeeModalTitle').textContent = id ? 'Edit employee' : 'Add employee';
        document.getElementById('empSubmit').textContent = id ? 'Save changes' : 'Add employee';
        var err = document.querySelector('#employeeModal .alert-danger');
        if (err) err.remove();
    }

    document.getElementById('btnAddEmployee').addEventListener('click', function () {
        fillForm(0, '', '', '', '', 'Active');
        empModal.show();
    });

    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fillForm(btn.dataset.id, btn.dataset.name, btn.dataset.designationId, btn.dataset.designationLabel, btn.dataset.salary, btn.dataset.status);
            empModal.show();
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
    empModal.show();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>