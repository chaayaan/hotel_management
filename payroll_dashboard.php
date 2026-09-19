<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Payroll Dashboard';
$active_menu = 'payroll_dashboard';

$totalEmployees = (int) $conn->query("SELECT COUNT(*) c FROM payroll_employees WHERE status = 'Active'")->fetch_assoc()['c'];

$today     = date('Y-m-d');
$todayStmt = $conn->prepare("SELECT status, COUNT(*) c FROM payroll_attendance WHERE attendance_date = ? GROUP BY status");
$todayStmt->bind_param('s', $today);
$todayStmt->execute();
$todayRes    = $todayStmt->get_result();
$todayCounts = ['Present' => 0, 'Absent' => 0, 'Leave' => 0];
while ($row = $todayRes->fetch_assoc()) {
    $todayCounts[$row['status']] = (int) $row['c'];
}
$notMarked = max(0, $totalEmployees - array_sum($todayCounts));

$curMonth    = (int) date('n');
$curYear     = (int) date('Y');
$payrollStmt = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(calculated_salary), 0) total FROM payroll_payroll WHERE month = ? AND year = ?");
$payrollStmt->bind_param('ii', $curMonth, $curYear);
$payrollStmt->execute();
$payrollRow = $payrollStmt->get_result()->fetch_assoc();

$pendingRow = $conn->query("
    SELECT COUNT(*) c,
           COALESCE(SUM(p.calculated_salary - COALESCE(pay.amount_paid, 0)), 0) due
    FROM payroll_payroll p
    LEFT JOIN payroll_payments pay ON pay.payroll_id = p.id
    WHERE pay.id IS NULL OR pay.status != 'Paid'
")->fetch_assoc();

require_once __DIR__ . '/navbar.php';
?>

<div class="row g-3 mb-3">
  <div class="col-xl-3 col-6">
    <div class="card stat-card">
      <div class="stat-icon green"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-value"><?= $totalEmployees ?></div><div class="stat-label">Active employees</div></div>
    </div>
  </div>
  <div class="col-xl-3 col-6">
    <div class="card stat-card">
      <div class="stat-icon green"><i class="bi bi-person-check-fill"></i></div>
      <div><div class="stat-value text-success"><?= $todayCounts['Present'] ?></div><div class="stat-label">Present today</div></div>
    </div>
  </div>
  <div class="col-xl-3 col-6">
    <div class="card stat-card">
      <div class="stat-icon red"><i class="bi bi-person-x-fill"></i></div>
      <div><div class="stat-value text-danger"><?= $todayCounts['Absent'] ?></div><div class="stat-label">Absent today</div></div>
    </div>
  </div>
  <div class="col-xl-3 col-6">
    <div class="card stat-card">
      <div class="stat-icon amber"><i class="bi bi-calendar2-minus-fill"></i></div>
      <div><div class="stat-value" style="color:#8a6a10;"><?= $todayCounts['Leave'] ?></div><div class="stat-label">On leave today</div></div>
    </div>
  </div>
</div>

<?php if ($notMarked > 0): ?>
  <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><?= $notMarked ?> active employee<?= $notMarked === 1 ? ' has' : 's have' ?> no attendance saved for today.</span>
    <a href="payroll_attendance.php" class="btn btn-sm btn-outline-dark">Mark attendance</a>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card stat-card">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div>
        <div class="stat-label">Payroll generated for <?= monthName($curMonth) . ' ' . $curYear ?></div>
        <div class="stat-value"><?= money($payrollRow['total']) ?></div>
        <div class="stat-label"><?= (int) $payrollRow['c'] ?> salary record(s)</div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card">
      <div class="stat-icon red"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-label">Unpaid or part-paid salaries</div>
        <div class="stat-value text-danger"><?= (int) $pendingRow['c'] ?></div>
        <div class="stat-label"><?= money($pendingRow['due']) ?> still to pay</div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex flex-wrap gap-2">
  <a href="payroll_employees.php" class="btn btn-brand"><i class="bi bi-people me-1"></i>Manage employees</a>
  <a href="payroll_attendance.php" class="btn btn-outline-brand"><i class="bi bi-calendar-check me-1"></i>Mark attendance</a>
  <a href="payroll_generate.php" class="btn btn-outline-brand"><i class="bi bi-calculator me-1"></i>Generate salary</a>
  <a href="payroll_salary_history.php" class="btn btn-outline-brand"><i class="bi bi-clock-history me-1"></i>Salary history</a>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
