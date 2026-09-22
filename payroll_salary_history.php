<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Salary History';
$active_menu = 'payroll_history';

$employees = $conn->query("SELECT id, name FROM payroll_employees ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$filterEmp = $_GET['employee_id'] ?? '';

$sql = "
    SELECT p.*, e.name,
           latest.payment_date, latest.payment_method
    FROM payroll_payroll p
    JOIN payroll_employees e ON e.id = p.employee_id
    LEFT JOIN payroll_payments latest
           ON latest.id = (
                SELECT pay2.id FROM payroll_payments pay2
                WHERE pay2.payroll_id = p.id
                ORDER BY pay2.created_at DESC, pay2.id DESC
                LIMIT 1
              )
    WHERE 1=1
";
$params = [];
$types  = '';
if ($filterEmp !== '') {
    $sql     .= " AND p.employee_id = ?";
    $params[] = (int) $filterEmp;
    $types   .= 'i';
}
$sql .= " ORDER BY p.year DESC, p.month DESC, e.name ASC";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalEarned = 0;
$totalPaid   = 0;
foreach ($records as $r) {
    $totalEarned += $r['calculated_salary'];
    $totalPaid   += $r['amount_paid'] ?? 0;
}
$outstanding = max(0, $totalEarned - $totalPaid);
// (both totals come straight from payroll_payroll's live running totals, kept
// in sync by recalcPayrollTotals() every time a payment is recorded)

require_once __DIR__ . '/navbar.php';
?>

<form method="GET" class="mb-3">
  <select name="employee_id" class="form-select" style="max-width:280px;" onchange="this.form.submit()">
    <option value="">All employees</option>
    <?php foreach ($employees as $emp): ?>
      <option value="<?= (int) $emp['id'] ?>" <?= (string) $filterEmp === (string) $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card stat-card">
      <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
      <div><div class="stat-label">Total salary generated</div><div class="stat-value"><?= money($totalEarned) ?></div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div><div class="stat-label">Total paid</div><div class="stat-value text-success"><?= money($totalPaid) ?></div></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card">
      <div class="stat-icon red"><i class="bi bi-hourglass-split"></i></div>
      <div><div class="stat-label">Outstanding</div><div class="stat-value text-danger"><?= money($outstanding) ?></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Employee</th><th>Period</th><th>Salary</th><th>Paid</th><th>Method</th><th>Status</th><th class="text-end">Slip</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$records): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No salary records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($records as $r): ?>
          <?php $rPaid = (float) ($r['amount_paid'] ?? 0); $rDue = (float) ($r['amount_due'] ?? $r['calculated_salary']); ?>
          <tr>
            <td class="fw-semibold"><?= e($r['name']) ?></td>
            <td><?= monthName($r['month']) . ' ' . (int) $r['year'] ?></td>
            <td><?= money($r['calculated_salary']) ?></td>
            <td><?= $rPaid > 0 ? money($rPaid) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $r['payment_method'] ? e($r['payment_method']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= paymentBadge(paymentStatusFromTotals($rPaid, $rDue)) ?></td>
            <td class="text-end">
              <a href="payroll_salary_slip.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>View slip</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>