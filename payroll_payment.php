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

/* ---------- Live running totals (source of truth: sum of the payment log) ---------- */
$net          = (float) $payroll['calculated_salary'];
$totals       = recalcPayrollTotals($conn, $payrollId); // also keeps payroll_payroll in sync
$currentPaid  = $totals['paid'];
$currentDue   = $totals['due'];

/* ---------- Save: always INSERT a new transaction row, never overwrite ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date   = $_POST['payment_date'] ?? '';
    $method = $_POST['payment_method'] ?? '';
    $amount = (float) ($_POST['amount_paid'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!isValidDate($date) || !in_array($method, $methods, true) || !in_array($status, $statuses, true)) {
        $error = 'Check the date, method, amount and status, then save again.';
    } elseif ($amount <= 0) {
        $error = 'Enter an amount greater than 0.';
    } elseif ($amount > $currentDue + 0.009) {
        // Boundary check: never allow paying more than what's currently due.
        $error = 'This amount is more than the balance due (' . money($currentDue) . '). Reduce the amount and try again.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO payroll_payments (payroll_id, payment_date, payment_method, amount_paid, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issds', $payrollId, $date, $method, $amount, $status);
        $stmt->execute();

        // Recalculate running totals on payroll_payroll from the full payment log.
        $totals = recalcPayrollTotals($conn, $payrollId);

        flash_set('success', 'Payment of ' . money($amount) . ' saved for ' . $payroll['name'] . ' (' . monthName($payroll['month']) . ' ' . $payroll['year'] . ').');
        header('Location: payroll_list.php');
        exit;
    }
}

/* ---------- Payment history (transaction log) ---------- */
$histStmt = $conn->prepare("SELECT * FROM payroll_payments WHERE payroll_id = ? ORDER BY created_at DESC, id DESC");
$histStmt->bind_param('i', $payrollId);
$histStmt->execute();
$history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ---------- Defaults for the form: date/method/status remember the last input; amount defaults to the current due ---------- */
$defaultAmount = $_POST['amount_paid'] ?? ($currentDue > 0 ? number_format($currentDue, 2, '.', '') : '0.00');
$defaultDate   = $_POST['payment_date'] ?? date('Y-m-d');
$defaultMethod = $_POST['payment_method'] ?? ($history[0]['payment_method'] ?? 'Cash');
$defaultStatus = $_POST['status'] ?? ($currentDue <= 0.009 ? 'Paid' : 'Partial');

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

          <div class="row mt-2 g-2">
            <div class="col-4">
              <div class="text-muted small">Net payable</div>
              <div class="fw-semibold"><?= money($net) ?></div>
            </div>
            <div class="col-4">
              <div class="text-muted small">Paid so far</div>
              <div class="fw-semibold text-success" id="paidSoFar" data-value="<?= e($currentPaid) ?>"><?= money($currentPaid) ?></div>
            </div>
            <div class="col-4">
              <div class="text-muted small">Balance due</div>
              <div class="fw-semibold <?= $currentDue > 0 ? 'text-danger' : 'text-success' ?>" id="balanceDue" data-value="<?= e($currentDue) ?>"><?= money($currentDue) ?></div>
            </div>
          </div>
          <div class="mt-2"><?= paymentBadge(paymentStatusFromTotals($currentPaid, $currentDue)) ?></div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($currentDue <= 0.009): ?>
          <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>This payroll is fully paid. No balance remains.</div>
        <?php endif; ?>

        <form method="POST" id="paymentForm">
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
              <label class="form-label" for="amount_paid">Amount paid now (<?= currencySymbol() ?>)</label>
              <input
                type="number" step="0.01" min="0.01"
                max="<?= e(number_format($currentDue, 2, '.', '')) ?>"
                name="amount_paid" id="amount_paid" class="form-control"
                value="<?= e($defaultAmount) ?>"
                <?= $currentDue <= 0.009 ? 'disabled' : 'required' ?>>
              <div class="form-text">
                Maximum allowed: <span id="maxHint"><?= money($currentDue) ?></span>.
                This will be added as a <strong>new</strong> payment entry; it never overwrites earlier payments.
              </div>
              <div class="invalid-feedback d-block text-danger small" id="amountError" style="display:none;"></div>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="form-label" for="status">Status</label>
              <select name="status" id="status" class="form-select" <?= $currentDue <= 0.009 ? 'disabled' : '' ?>>
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= e($s) ?>" <?= $defaultStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-brand" id="saveBtn" <?= $currentDue <= 0.009 ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i>Save payment
          </button>
          <a href="payroll_list.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
      </div>
    </div>

    <?php if ($history): ?>
    <div class="card mt-3">
      <div class="card-header">Payment history</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th>Date</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th>Recorded</th></tr>
          </thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><?= e(date('d M Y', strtotime($h['payment_date']))) ?></td>
                <td><?= e($h['payment_method']) ?></td>
                <td class="text-end"><?= money($h['amount_paid']) ?></td>
                <td><?= paymentBadge($h['status']) ?></td>
                <td class="text-muted small"><?= e(date('d M Y, h:i A', strtotime($h['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light fw-semibold">
              <td colspan="2" class="text-end">Total paid</td>
              <td class="text-end text-success"><?= money($currentPaid) ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var due      = <?= json_encode(round($currentDue, 2)) ?>;
  var input    = document.getElementById('amount_paid');
  var saveBtn  = document.getElementById('saveBtn');
  var errBox   = document.getElementById('amountError');
  if (!input) return; // fully paid: field is absent/disabled, nothing to wire up

  function validate() {
    var val = parseFloat(input.value);
    var valid = !isNaN(val) && val > 0 && val <= due + 0.009;

    if (isNaN(val) || input.value.trim() === '') {
      errBox.style.display = 'none';
    } else if (val <= 0) {
      errBox.textContent = 'Amount must be greater than 0.';
      errBox.style.display = 'block';
    } else if (val > due + 0.009) {
      errBox.textContent = 'Amount cannot exceed the balance due (' + due.toFixed(2) + ').';
      errBox.style.display = 'block';
    } else {
      errBox.style.display = 'none';
    }

    // Boundary rule: Save button only active while amount is within (0, due].
    saveBtn.disabled = !valid;
    return valid;
  }

  input.addEventListener('input', validate);
  document.getElementById('paymentForm').addEventListener('submit', function (e) {
    if (!validate()) e.preventDefault();
  });

  validate();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>