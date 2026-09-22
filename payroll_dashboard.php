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

/* ---------- Paid / Due for the current month (source of truth: payroll_payroll running totals) ---------- */
$paidDueRow = $conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) paid, COALESCE(SUM(amount_due), 0) due
    FROM payroll_payroll WHERE month = $curMonth AND year = $curYear
")->fetch_assoc();
$monthPaid = (float) $paidDueRow['paid'];
$monthDue  = (float) $paidDueRow['due'];
$monthPct  = $payrollRow['total'] > 0 ? min(100, round($monthPaid / $payrollRow['total'] * 100)) : 0;

$pendingRow = $conn->query("
    SELECT COUNT(*) c, COALESCE(SUM(amount_due), 0) due
    FROM payroll_payroll
    WHERE amount_due > 0.009
")->fetch_assoc();

/* ---------- 6-month payroll trend (generated vs paid) ---------- */
$trendRaw = $conn->query("
    SELECT month, year, COALESCE(SUM(calculated_salary),0) generated, COALESCE(SUM(amount_paid),0) paid
    FROM payroll_payroll
    GROUP BY year, month
    ORDER BY year DESC, month DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);
$trend = array_reverse($trendRaw);
$trendLabels    = array_map(fn($t) => substr(monthName($t['month']), 0, 3) . " '" . substr($t['year'], -2), $trend);
$trendGenerated = array_map(fn($t) => (float) $t['generated'], $trend);
$trendPaid      = array_map(fn($t) => (float) $t['paid'], $trend);

/* ---------- Employees with the largest outstanding balance right now ---------- */
$dueEmployees = $conn->query("
    SELECT p.id, e.name, p.month, p.year, p.calculated_salary, p.amount_paid, p.amount_due
    FROM payroll_payroll p
    JOIN payroll_employees e ON e.id = p.employee_id
    WHERE p.amount_due > 0.009
    ORDER BY p.amount_due DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/navbar.php';
?>

<style>
  .pd-hero {
    background: linear-gradient(135deg, #0f5132 0%, #146c43 55%, #198754 100%);
    border-radius: .75rem;
    color: #fff;
    padding: 1.5rem 1.75rem;
  }
  .pd-hero .pd-label { opacity: .85; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
  .pd-hero .pd-value { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
  .pd-progress { height: 8px; border-radius: 999px; background: rgba(255,255,255,.25); overflow: hidden; }
  .pd-progress > span { display: block; height: 100%; background: #fff; border-radius: 999px; }
  .pd-mini-card { border-radius: .65rem; padding: 1rem 1.1rem; height: 100%; }
  .pd-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; font-weight: 600; padding: .2rem .55rem; border-radius: 999px;
  }
  .pd-due-row:hover { background: rgba(0,0,0,.02); }
  .pd-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: #e9f5ee; color: #146c43; font-weight: 700; font-size: .85rem;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
</style>

<!-- ===== Hero: this month's payroll ===== -->
<div class="pd-hero mb-4">
  <div class="row align-items-center g-4">
    <div class="col-lg-4">
      <div class="pd-label">Payroll &middot; <?= monthName($curMonth) . ' ' . $curYear ?></div>
      <div class="pd-value"><?= money($payrollRow['total']) ?></div>
      <div class="small opacity-75"><?= (int) $payrollRow['c'] ?> salary record(s) generated</div>
    </div>
    <div class="col-lg-5">
      <div class="d-flex justify-content-between small mb-1">
        <span>Paid <strong><?= money($monthPaid) ?></strong></span>
        <span class="opacity-75">Due <strong><?= money($monthDue) ?></strong></span>
      </div>
      <div class="pd-progress"><span style="width:<?= $monthPct ?>%"></span></div>
      <div class="small opacity-75 mt-1"><?= $monthPct ?>% of this month's payroll paid out</div>
    </div>
    <div class="col-lg-3 text-lg-end">
      <a href="payroll_generate.php" class="btn btn-light btn-sm fw-semibold"><i class="bi bi-calculator me-1"></i>Generate salary</a>
      <a href="payroll_list.php" class="btn btn-outline-light btn-sm fw-semibold mt-2 mt-lg-0"><i class="bi bi-table me-1"></i>View payroll</a>
    </div>
  </div>
</div>

<!-- ===== Attendance today ===== -->
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
  <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <span><i class="bi bi-exclamation-triangle me-1"></i><?= $notMarked ?> active employee<?= $notMarked === 1 ? ' has' : 's have' ?> no attendance saved for today.</span>
    <a href="payroll_attendance.php" class="btn btn-sm btn-outline-dark">Mark attendance</a>
  </div>
<?php endif; ?>

<!-- ===== Trend + Due list ===== -->
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Payroll trend &middot; last 6 months</span>
        <span class="pd-chip bg-success-subtle text-success-emphasis"><i class="bi bi-circle-fill" style="font-size:.5rem;"></i>Generated</span>
        <span class="pd-chip bg-primary-subtle text-primary-emphasis"><i class="bi bi-circle-fill" style="font-size:.5rem;"></i>Paid</span>
      </div>
      <div class="card-body">
        <?php if (count($trend) < 2): ?>
          <div class="text-muted text-center py-5">Not enough payroll history yet to chart a trend.</div>
        <?php else: ?>
          <canvas id="payrollTrendChart" height="110"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Largest outstanding balances</span>
        <a href="payroll_salary_history.php" class="small">View all</a>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$dueEmployees): ?>
          <div class="list-group-item text-muted text-center py-4"><i class="bi bi-check-circle me-1 text-success"></i>No outstanding dues right now.</div>
        <?php endif; ?>
        <?php foreach ($dueEmployees as $d): ?>
          <a href="payroll_payment.php?payroll_id=<?= (int) $d['id'] ?>" class="list-group-item list-group-item-action pd-due-row d-flex align-items-center gap-3">
            <div class="pd-avatar"><?= e(strtoupper(substr($d['name'], 0, 1))) ?></div>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= e($d['name']) ?></div>
              <div class="small text-muted"><?= monthName($d['month']) . ' ' . (int) $d['year'] ?></div>
            </div>
            <div class="text-end">
              <div class="fw-semibold text-danger"><?= money($d['amount_due']) ?></div>
              <div class="small text-muted">of <?= money($d['calculated_salary']) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
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

<?php if (count($trend) >= 2): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
(function () {
  var ctx = document.getElementById('payrollTrendChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($trendLabels) ?>,
      datasets: [
        {
          label: 'Generated',
          data: <?= json_encode($trendGenerated) ?>,
          backgroundColor: 'rgba(25,135,84,0.18)',
          borderColor: '#198754',
          borderWidth: 1.5,
          borderRadius: 4,
          maxBarThickness: 34
        },
        {
          label: 'Paid',
          type: 'line',
          data: <?= json_encode($trendPaid) ?>,
          borderColor: '#0d6efd',
          backgroundColor: '#0d6efd',
          tension: 0.35,
          pointRadius: 4,
          pointBackgroundColor: '#0d6efd',
          fill: false
        }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: function (v) { return '<?= currencySymbol() ?>' + v.toLocaleString(); } } }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>