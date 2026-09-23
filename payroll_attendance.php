<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Daily Attendance';
$active_menu = 'payroll_attendance';

$date = $_GET['date'] ?? date('Y-m-d');
if (!isValidDate($date)) {
    $date = date('Y-m-d');
}

$departments = payrollDepartments($conn);
$deptIds     = array_column($departments, 'id');
$department  = (int) ($_GET['department'] ?? 0);
if (!in_array($department, $deptIds, true)) {
    $department = 0;
}
$deptQuery = $department !== 0 ? '&department=' . $department : '';

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postDate = $_POST['attendance_date'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? '') || !isValidDate($postDate)) {
        flash_set('danger', 'Attendance could not be saved. Reload the page and try again.');
        header('Location: payroll_attendance.php');
        exit;
    }

    $allowed   = ['Present', 'Absent', 'Leave'];
    $statuses  = $_POST['status'] ?? [];
    $checkIns  = $_POST['check_in'] ?? [];
    $checkOuts = $_POST['check_out'] ?? [];

    $stmt = $conn->prepare("
        INSERT INTO payroll_attendance (employee_id, attendance_date, status, check_in, check_out)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), check_in = VALUES(check_in), check_out = VALUES(check_out)
    ");

    $saved = 0;
    foreach ($statuses as $empId => $status) {
        if (!in_array($status, $allowed, true)) {
            continue;
        }
        $empId    = (int) $empId;
        $in       = $checkIns[$empId] ?? '';
        $out      = $checkOuts[$empId] ?? '';
        $checkIn  = preg_match('/^\d{2}:\d{2}$/', $in) ? $in . ':00' : null;
        $checkOut = preg_match('/^\d{2}:\d{2}$/', $out) ? $out . ':00' : null;
        $stmt->bind_param('issss', $empId, $postDate, $status, $checkIn, $checkOut);
        $stmt->execute();
        $saved++;
    }

    $postDept = (int) ($_POST['department'] ?? 0);
    $postDeptQuery = in_array($postDept, $deptIds, true) ? '&department=' . $postDept : '';

    flash_set('success', 'Attendance saved for ' . date('d M Y', strtotime($postDate)) . " ($saved employees).");
    header('Location: payroll_attendance.php?date=' . urlencode($postDate) . $postDeptQuery);
    exit;
}

/* ---------- Load ---------- */
$sql = "
    SELECT e.id, e.name, d.name AS designation, dept.name AS department, dept.id AS department_id,
           a.status, a.check_in, a.check_out
    FROM payroll_employees e
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    LEFT JOIN payroll_departments dept ON dept.id = d.department_id
    LEFT JOIN payroll_attendance a ON a.employee_id = e.id AND a.attendance_date = ?
    WHERE e.status = 'Active'
";
if ($department !== 0) {
    $sql .= " AND dept.id = ?";
}
$sql .= " ORDER BY e.name ASC";

$stmt = $conn->prepare($sql);
if ($department !== 0) {
    $stmt->bind_param('si', $date, $department);
} else {
    $stmt->bind_param('s', $date);
}
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <div class="fw-semibold fs-5"><?= date('l, d F Y', strtotime($date)) ?></div>
    <div class="text-muted small">Rows without a saved record start as Present. Nothing counts until you save.</div>
  </div>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <select name="department" class="form-select" style="width:auto;" onchange="this.form.submit()">
      <option value="">All departments</option>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= (int) $dept['id'] ?>" <?= $department === (int) $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="?date=<?= e($prevDate) . e($deptQuery) ?>" class="btn btn-outline-brand" title="Previous day"><i class="bi bi-chevron-left"></i></a>
    <input type="date" name="date" class="form-control" value="<?= e($date) ?>" onchange="this.form.submit()">
    <a href="?date=<?= e($nextDate) . e($deptQuery) ?>" class="btn btn-outline-brand" title="Next day"><i class="bi bi-chevron-right"></i></a>
  </form>
</div>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
  <input type="hidden" name="department" value="<?= e($department) ?>">

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= count($employees) ?> active employee(s)</span>
      <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-secondary" data-set-all="Present">All present</button>
        <button type="button" class="btn btn-outline-secondary" data-set-all="Absent">All absent</button>
        <button type="button" class="btn btn-outline-secondary" data-set-all="Leave">All on leave</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Employee</th>
            <th style="width:160px;">Status</th>
            <th style="width:150px;">Check-in</th>
            <th style="width:150px;">Check-out</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$employees): ?>
            <tr><td colspan="4" class="text-center text-muted py-5"><?= $department !== 0 ? 'No active employees in that department.' : 'No active employees.' ?> <a href="payroll_employees.php">Add an employee</a>.</td></tr>
          <?php endif; ?>
          <?php foreach ($employees as $emp): $status = $emp['status'] ?? 'Present'; $id = (int) $emp['id']; ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= e($emp['name']) ?>
                  <?php if ($emp['status'] === null): ?><span class="badge bg-light text-muted border ms-1">Not saved</span><?php endif; ?>
                </div>
                <div class="text-muted small">
                  <?= $emp['designation'] ? e($emp['designation']) : 'No designation' ?>
                  <?php if ($emp['department']): ?><span class="ms-1"><?= departmentBadge($emp['department'], $emp['department_id']) ?></span><?php endif; ?>
                </div>
              </td>
              <td>
                <select name="status[<?= $id ?>]" class="form-select form-select-sm att-status">
                  <?php foreach (['Present', 'Absent', 'Leave'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="time" name="check_in[<?= $id ?>]" class="form-control form-control-sm" value="<?= e(toTimeInput($emp['check_in'])) ?>"></td>
              <td><input type="time" name="check_out[<?= $id ?>]" class="form-control form-control-sm" value="<?= e(toTimeInput($emp['check_out'])) ?>"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($employees): ?>
    <button type="submit" class="btn btn-brand mt-3"><i class="bi bi-check2-circle me-1"></i>Save attendance</button>
  <?php endif; ?>
</form>

<script>
document.querySelectorAll('[data-set-all]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.att-status').forEach(function (sel) { sel.value = btn.dataset.setAll; });
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>