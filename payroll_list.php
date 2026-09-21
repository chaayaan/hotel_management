<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';
require_once __DIR__ . '/payroll_attendance_calendar.php';

$page_title  = 'Payroll';
$active_menu = 'payroll_list';

/* Default to the current month/year on first visit (no filter params in the URL).
   Once the filter form is submitted, "All months" / blank year are respected. */
$isFirstVisit = !isset($_GET['month']) && !isset($_GET['year']);
$defaultMonth = (string) (int) date('n');
$defaultYear  = (string) (int) date('Y');
$filterMonth  = $isFirstVisit ? $defaultMonth : ($_GET['month'] ?? '');
$filterYear   = $isFirstVisit ? $defaultYear  : ($_GET['year'] ?? '');

/* ---------- Year dropdown: earliest payroll year -> current year (auto-grows every year) ---------- */
$currentYear = (int) date('Y');
$firstYear   = $currentYear;
try {
    $yr = $conn->query("SELECT MIN(year) AS y FROM payroll_payroll");
    if ($yr && ($yrow = $yr->fetch_assoc()) && $yrow['y']) {
        $firstYear = min($currentYear, (int) $yrow['y']);
    }
} catch (Throwable $ex) { /* fall back to current year only */ }
$yearOptions = range($firstYear, $currentYear);          // ascending: current year is last
// Keep a manually-typed/bookmarked year (e.g. ?year=2031) selectable so the filter never silently changes
if ($filterYear !== '' && !in_array((int) $filterYear, $yearOptions, true)) {
    $yearOptions[] = (int) $filterYear;
    sort($yearOptions);
}

$sql = "
    SELECT p.*, e.name,
           pay.amount_paid, pay.status AS payment_status
    FROM payroll_payroll p
    JOIN payroll_employees e ON e.id = p.employee_id
    LEFT JOIN payroll_payments pay ON pay.payroll_id = p.id
    WHERE 1=1
";
$params = [];
$types  = '';
if ($filterMonth !== '') {
    $sql     .= " AND p.month = ?";
    $params[] = (int) $filterMonth;
    $types   .= 'i';
}
if ($filterYear !== '') {
    $sql     .= " AND p.year = ?";
    $params[] = (int) $filterYear;
    $types   .= 'i';
}
$sql .= " ORDER BY p.year DESC, p.month DESC, e.name ASC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sumSalary = 0;
$sumPaid   = 0;
foreach ($rows as $r) {
    $sumSalary += $r['calculated_salary'];
    $sumPaid   += $r['amount_paid'] ?? 0;
}

/* ---------- Attendance grid view (needs a specific month + year) ---------- */
$view      = $_GET['view'] ?? 'salary';
$canGrid   = ($filterMonth !== '' && $filterYear !== '');
if ($view === 'attendance' && !$canGrid) { $view = 'salary'; $gridNeedsPeriod = true; }
$attGrid = '';
if ($view === 'attendance') {
    $people = [];
    foreach ($rows as $r) { $people[(int) $r['employee_id']] = ['id' => (int) $r['employee_id'], 'name' => $r['name'], 'url' => 'employee_daily_history.php?' . http_build_query(['employee_id' => (int) $r['employee_id'], 'month' => (int) $filterMonth, 'year' => (int) $filterYear])]; }
    $attData = loadMonthAttendance($conn, (int) $filterMonth, (int) $filterYear, array_keys($people));
    $attGrid = renderAttendanceGrid(array_values($people), $attData, (int) $filterMonth, (int) $filterYear);
}
$qs = function (string $v) use ($filterMonth, $filterYear) { return '?' . http_build_query(['month' => $filterMonth, 'year' => $filterYear, 'view' => $v]); };

require_once __DIR__ . '/navbar.php';
echo attendanceCalendarCss();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <select name="month" class="form-select" style="width:auto;">
      <option value="">All months</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= (string) $filterMonth === (string) $m ? 'selected' : '' ?>><?= monthName($m) ?></option>
      <?php endfor; ?>
    </select>
    <select name="year" class="form-select" style="width:auto;">
      <option value="">All years</option>
      <?php foreach ($yearOptions as $y): ?>
        <option value="<?= $y ?>" <?= (string) $filterYear === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-brand">Filter</button>
    <?php if ((string) $filterMonth !== $defaultMonth || (string) $filterYear !== $defaultYear): ?>
      <a href="payroll_list.php" class="btn btn-link text-muted">Current month</a>
    <?php endif; ?>
  </form>
  <a href="payroll_generate.php" class="btn btn-brand"><i class="bi bi-plus-lg me-1"></i>Generate salary</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div class="btn-group btn-group-sm">
    <a href="<?= e($qs('salary')) ?>" class="btn <?= $view === 'salary' ? 'btn-brand' : 'btn-outline-brand' ?>"><i class="bi bi-table me-1"></i>Salary table</a>
    <a href="<?= e($qs('attendance')) ?>" class="btn <?= $view === 'attendance' ? 'btn-brand' : 'btn-outline-brand' ?>"><i class="bi bi-calendar3 me-1"></i>Attendance (day-wise)</a>
  </div>
  <?= attendanceLegend() ?>
</div>
<?php if (!empty($gridNeedsPeriod)): ?>
  <div class="alert alert-info py-2 small">Pick a <strong>month and year</strong> and press Filter to see the day-wise attendance grid.</div>
<?php endif; ?>

<?php if ($view === 'attendance'): ?>
<?= attendanceDiagnostic() ?>
<div class="card mb-3 overflow-hidden"><?= $attGrid ?></div>
<?php endif; ?>

<?php if ($view !== 'attendance'): ?>
<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Employee</th><th>Period</th><th class="text-center">Present</th><th class="text-center">Absent</th><th class="text-center">Leave</th>
          <th>Salary</th><th>Payment</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">No payroll records found. <a href="payroll_generate.php">Generate salary</a> for a month to see it here.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= e($r['name']) ?></td>
            <td><?= monthName($r['month']) . ' ' . (int) $r['year'] ?></td>
            <td class="text-center"><?= (int) $r['present_days'] ?></td>
            <td class="text-center"><?= (int) $r['absent_days'] ?></td>
            <td class="text-center"><?= (int) $r['leave_days'] ?></td>
            <td><?= money($r['calculated_salary']) ?></td>
            <td><?= paymentBadge($r['payment_status']) ?></td>
            <td class="text-end text-nowrap">
              <a href="payroll_salary_slip.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Slip</a>
              <a href="payroll_payment.php?payroll_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-brand"><i class="bi bi-cash-coin me-1"></i>Payment</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr class="table-light fw-semibold">
          <td colspan="5" class="text-end">Total (<?= count($rows) ?> records)</td>
          <td><?= money($sumSalary) ?></td>
          <td colspan="2" class="text-success">Paid <?= money($sumPaid) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>