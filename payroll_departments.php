<?php
/**
 * Payroll > Departments
 * The dynamic list of departments (used to group designations, e.g. Hotel,
 * Restaurant, Resort, or any custom department this property adds).
 * Single file: list (GET) | add/edit (POST action=save) | delete (POST action=delete)
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Departments';
$active_menu = 'payroll_departments';

$form        = ['id' => 0, 'name' => '', 'status' => 'Active'];
$formError   = '';
$reopenModal = false;

/* ---------- Handle writes ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Your session expired. Please try again.');
        header('Location: payroll_departments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $status = (($_POST['status'] ?? '') === 'Inactive') ? 'Inactive' : 'Active';

        if ($name === '') {
            $formError = 'Enter a department name.';
        } else {
            $dup = $conn->prepare("SELECT id FROM payroll_departments WHERE name = ? AND id <> ?");
            $dup->bind_param('si', $name, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $formError = '“' . $name . '” already exists.';
            }
        }

        if ($formError !== '') {
            $form        = ['id' => $id, 'name' => $name, 'status' => $status];
            $reopenModal = true;
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE payroll_departments SET name = ?, status = ? WHERE id = ?");
                $stmt->bind_param('ssi', $name, $status, $id);
                $stmt->execute();
                flash_set('success', 'Department updated.');
            } else {
                $stmt = $conn->prepare("INSERT INTO payroll_departments (name, status) VALUES (?, ?)");
                $stmt->bind_param('ss', $name, $status);
                $stmt->execute();
                flash_set('success', 'Department added.');
            }
            header('Location: payroll_departments.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $chk = $conn->prepare("SELECT COUNT(*) c FROM payroll_designations WHERE department_id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $inUse = (int) $chk->get_result()->fetch_assoc()['c'];

            if ($inUse > 0) {
                flash_set('danger', "That department is used by $inUse designation(s). Reassign or delete them first, or set the department to Inactive instead.");
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM payroll_departments WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    flash_set('success', 'Department deleted.');
                } catch (Throwable $ex) {
                    flash_set('danger', 'This department could not be deleted.');
                }
            }
        }
        header('Location: payroll_departments.php');
        exit;
    }
}

/* ---------- Read ---------- */
$rows = $conn->query("
    SELECT dept.*, COUNT(d.id) AS designation_count
    FROM payroll_departments dept
    LEFT JOIN payroll_designations d ON d.department_id = dept.id
    GROUP BY dept.id
    ORDER BY dept.name ASC
")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <div class="fw-semibold fs-5">Departments</div>
    <div class="text-muted small">Group your designations (Hotel, Restaurant, Resort, or any department you add).</div>
  </div>
  <div class="d-flex gap-2">
    <a href="payroll_designations.php" class="btn btn-outline-brand">
      <i class="bi bi-briefcase me-1"></i>Designations
    </a>
    <button type="button" class="btn btn-brand" id="btnAddDepartment">
      <i class="bi bi-plus-lg me-1"></i>Add department
    </button>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Department</th>
          <th class="text-center">Designations</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-center text-muted py-5">No departments yet. Use “Add department” to create the first one.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $d): $used = (int) $d['designation_count']; ?>
          <tr>
            <td class="fw-semibold"><?= departmentBadge($d['name'], (int) $d['id']) ?></td>
            <td class="text-center"><?= $used ?></td>
            <td><span class="badge <?= $d['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= e($d['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-brand btn-edit"
                      data-id="<?= (int) $d['id'] ?>"
                      data-name="<?= e($d['name']) ?>"
                      data-status="<?= e($d['status']) ?>">
                <i class="bi bi-pencil-square me-1"></i>Edit
              </button>
              <button type="button" class="btn btn-sm btn-outline-danger btn-delete"
                      data-id="<?= (int) $d['id'] ?>"
                      data-name="<?= e($d['name']) ?>"
                      <?= $used > 0 ? 'disabled title="Used by ' . $used . ' designation(s). Set it to Inactive instead."' : '' ?>>
                <i class="bi bi-trash me-1"></i>Delete
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-2">Inactive departments stay on existing designations but can’t be chosen for new ones.</p>

<!-- ===== Add / Edit modal ===== -->
<div class="modal fade" id="departmentModal" tabindex="-1" aria-labelledby="departmentModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="deptId" value="<?= (int) $form['id'] ?>">

      <div class="modal-header">
        <h5 class="modal-title" id="departmentModalTitle"><?= $form['id'] ? 'Edit department' : 'Add department' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($formError): ?>
          <div class="alert alert-danger py-2"><?= e($formError) ?></div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label" for="deptName">Department name</label>
          <input type="text" name="name" id="deptName" class="form-control" required maxlength="100" value="<?= e($form['name']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="deptStatus">Status</label>
          <select name="status" id="deptStatus" class="form-select">
            <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand" id="deptSubmit"><?= $form['id'] ? 'Save changes' : 'Add department' ?></button>
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
        <h5 class="modal-title" id="deleteModalTitle">Delete department</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Delete <strong id="deleteName"></strong>? No designation uses it.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep department</button>
        <button type="submit" class="btn btn-danger">Delete department</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var deptModal = new bootstrap.Modal(document.getElementById('departmentModal'));
    var delModal  = new bootstrap.Modal(document.getElementById('deleteModal'));

    function fillForm(id, name, status) {
        document.getElementById('deptId').value = id;
        document.getElementById('deptName').value = name;
        document.getElementById('deptStatus').value = status;
        document.getElementById('departmentModalTitle').textContent = id ? 'Edit department' : 'Add department';
        document.getElementById('deptSubmit').textContent = id ? 'Save changes' : 'Add department';
        var err = document.querySelector('#departmentModal .alert-danger');
        if (err) err.remove();
    }

    document.getElementById('btnAddDepartment').addEventListener('click', function () {
        fillForm(0, '', 'Active');
        deptModal.show();
    });

    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fillForm(btn.dataset.id, btn.dataset.name, btn.dataset.status);
            deptModal.show();
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
    deptModal.show();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>