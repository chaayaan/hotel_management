<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Payroll';
$active_menu = 'payroll_list';

$filterMonth = $_GET['month'] ?? '';
$filterYear  = $_GET['year'] ?? '';

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

require_once __DIR__ . '/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <select name="month" class="form-select" style="width:auto;">
      <option value="">All months</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= (string) $filterMonth === (string) $m ? 'selected' : '' ?>><?= monthName($m) ?></option>
      <?php endfor; ?>
    </select>
    <input type="number" name="year" class="form-control" style="width:110px;" placeholder="Year" value="<?= e($filterYear) ?>">
    <button class="btn btn-outline-brand">Filter</button>
    <?php if ($filterMonth !== '' || $filterYear !== ''): ?>
      <a href="payroll_list.php" class="btn btn-link text-muted">Clear</a>
    <?php endif; ?>
  </form>
  <a href="payroll_generate.php" class="btn btn-brand"><i class="bi bi-plus-lg me-1"></i>Generate salary</a>
</div>

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
              <a href="payroll_salary_slip.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer me-1"></i>Slip</a>
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

<?php require_once __DIR__ . '/footer.php'; ?>
