<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Generate Salary';
$active_menu = 'payroll_generate';

$month = (int) ($_GET['month'] ?? date('n'));
$year  = (int) ($_GET['year'] ?? date('Y'));

/* ---------- Generate ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month     = (int) ($_POST['month'] ?? 0);
    $year      = (int) ($_POST['year'] ?? 0);
    $empChoice = $_POST['employee_id'] ?? 'all';

    if (!csrf_verify($_POST['csrf_token'] ?? '') || $month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        flash_set('danger', 'Choose a valid month and year, then try again.');
        header('Location: payroll_generate.php');
        exit;
    }

    $totalDays = daysInMonth($month, $year);

    if ($empChoice === 'all') {
        $empList = $conn->query("SELECT id, name, monthly_salary FROM payroll_employees WHERE status = 'Active'");
    } else {
        $empIdFilter = (int) $empChoice;
        $empStmt = $conn->prepare("SELECT id, name, monthly_salary FROM payroll_employees WHERE status = 'Active' AND id = ?");
        $empStmt->bind_param('i', $empIdFilter);
        $empStmt->execute();
        $empList = $empStmt->get_result();
    }

    $attStmt = $conn->prepare("
        SELECT
          SUM(status = 'Present') AS present_days,
          SUM(status = 'Absent')  AS absent_days,
          SUM(status = 'Leave')   AS leave_days
        FROM payroll_attendance
        WHERE employee_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?
    ");

    $insertStmt = $conn->prepare("
        INSERT INTO payroll_payroll (employee_id, month, year, total_days, present_days, absent_days, leave_days, basic_salary, calculated_salary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          total_days = VALUES(total_days),
          present_days = VALUES(present_days),
          absent_days = VALUES(absent_days),
          leave_days = VALUES(leave_days),
          basic_salary = VALUES(basic_salary),
          calculated_salary = VALUES(calculated_salary)
    ");

    $count = 0;
    while ($emp = $empList->fetch_assoc()) {
        $attStmt->bind_param('iii', $emp['id'], $month, $year);
        $attStmt->execute();
        $att = $attStmt->get_result()->fetch_assoc();

        $present = (int) ($att['present_days'] ?? 0);
        $absent  = (int) ($att['absent_days'] ?? 0);
        $leave   = (int) ($att['leave_days'] ?? 0);

        $perDay     = $totalDays > 0 ? $emp['monthly_salary'] / $totalDays : 0;
        $calculated = round($perDay * $present, 2);

        $insertStmt->bind_param(
            'iiiiiiidd',
            $emp['id'], $month, $year, $totalDays, $present, $absent, $leave, $emp['monthly_salary'], $calculated
        );
        $insertStmt->execute();
        $count++;
    }

    if ($count === 0) {
        flash_set('warning', 'No active employees matched, so no salary was generated.');
        header('Location: payroll_generate.php?month=' . $month . '&year=' . $year);
    } else {
        flash_set('success', $count . ' salary record(s) generated for ' . monthName($month) . ' ' . $year . '.');
        header('Location: payroll_list.php?month=' . $month . '&year=' . $year);
    }
    exit;
}

$employees = $conn->query("SELECT id, name FROM payroll_employees WHERE status = 'Active' ORDER BY name");

require_once __DIR__ . '/navbar.php';
?>

<div class="row">
  <div class="col-lg-7 col-xl-6">
    <div class="card">
      <div class="card-header">Generate monthly salary</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

          <div class="mb-3">
            <label class="form-label" for="employee_id">Employee</label>
            <select name="employee_id" id="employee_id" class="form-select">
              <option value="all">All active employees</option>
              <?php while ($emp = $employees->fetch_assoc()): ?>
                <option value="<?= (int) $emp['id'] ?>"><?= e($emp['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label" for="month">Month</label>
              <select name="month" id="month" class="form-select">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= monthName($m) ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label" for="year">Year</label>
              <input type="number" name="year" id="year" class="form-control" min="2000" max="2100" value="<?= $year ?>">
            </div>
          </div>

          <div class="alert alert-info small">
            Salary = (monthly salary &divide; days in month) &times; present days.<br>
            Generating again for the same employee and month updates the existing record.
          </div>

          <button type="submit" class="btn btn-brand"><i class="bi bi-calculator me-1"></i>Generate salary</button>
          <a href="payroll_list.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
