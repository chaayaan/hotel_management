<?php
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$page_title  = 'Record Payment';
$active_menu = 'payroll_list';   // keeps "Payroll List" highlighted in the sidebar

$payrollId = (int) ($_GET['payroll_id'] ?? $_POST['payroll_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT p.*, e.name FROM payroll_payroll p JOIN payroll_employees e ON e.id = p.employee_id WHERE p.id = ?
");
$stmt->bind_param('i', $payrollId);
$stmt->execute();
$payroll = $stmt->get_result()->fetch_assoc();

if (!$payroll) {
    flash_set('danger', 'Payroll record not found.');
    header('Location: payroll_list.php');
    exit;
}

$methods  = ['Cash', 'Bank', 'bKash', 'Nagad', 'Other'];
$statuses = ['Paid', 'Partial', 'Pending'];
$error    = '';

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date   = $_POST['payment_date'] ?? '';
    $method = $_POST['payment_method'] ?? '';
    $amount = (float) ($_POST['amount_paid'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isValidDate($date) || !in_array($method, $methods, true) || !in_array($status, $statuses, true) || $amount < 0) {
        $error = 'Check the date, method, amount and status, then save again.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO payroll_payments (payroll_id, payment_date, payment_method, amount_paid, status)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              payment_date = VALUES(payment_date),
              payment_method = VALUES(payment_method),
              amount_paid = VALUES(amount_paid),
              status = VALUES(status)
        ");
        $stmt->bind_param('issds', $payrollId, $date, $method, $amount, $status);
        $stmt->execute();

        flash_set('success', 'Payment saved for ' . $payroll['name'] . ' (' . monthName($payroll['month']) . ' ' . $payroll['year'] . ').');
        header('Location: payroll_list.php');
        exit;
    }
}

/* ---------- Existing payment / defaults ---------- */
$payStmt = $conn->prepare("SELECT * FROM payroll_payments WHERE payroll_id = ?");
$payStmt->bind_param('i', $payrollId);
$payStmt->execute();
$payment = $payStmt->get_result()->fetch_assoc();

$defaultAmount = $_POST['amount_paid'] ?? $payment['amount_paid'] ?? $payroll['calculated_salary'];
$defaultDate   = $_POST['payment_date'] ?? $payment['payment_date'] ?? date('Y-m-d');
$defaultMethod = $_POST['payment_method'] ?? $payment['payment_method'] ?? 'Cash';
$defaultStatus = $_POST['status'] ?? $payment['status'] ?? 'Paid';

require_once __DIR__ . '/navbar.php';
?>

<div class="row">
  <div class="col-lg-7 col-xl-6">
    <div class="card">
      <div class="card-header">Record salary payment</div>
      <div class="card-body">
        <div class="mb-3 pb-3 border-bottom">
          <div class="fw-semibold fs-5"><?= e($payroll['name']) ?></div>
          <div class="text-muted"><?= monthName($payroll['month']) . ' ' . (int) $payroll['year'] ?></div>
          <div class="mt-1">Salary due: <strong><?= money($payroll['calculated_salary']) ?></strong>
            <?php if ($payment): ?><span class="ms-2"><?= paymentBadge($payment['status']) ?></span><?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="payroll_id" value="<?= $payrollId ?>">

          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="form-label" for="payment_date">Payment date</label>
              <input type="date" name="payment_date" id="payment_date" class="form-control" value="<?= e($defaultDate) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="form-label" for="payment_method">Payment method</label>
              <select name="payment_method" id="payment_method" class="form-select">
                <?php foreach ($methods as $m): ?>
                  <option value="<?= e($m) ?>" <?= $defaultMethod === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="form-label" for="amount_paid">Amount paid (<?= currencySymbol() ?>)</label>
              <input type="number" step="0.01" min="0" name="amount_paid" id="amount_paid" class="form-control" value="<?= e($defaultAmount) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="form-label" for="status">Status</label>
              <select name="status" id="status" class="form-select">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= e($s) ?>" <?= $defaultStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-brand"><i class="bi bi-check2-circle me-1"></i>Save payment</button>
          <a href="payroll_list.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
