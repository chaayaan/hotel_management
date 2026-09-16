<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$user = current_user();
$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    flash_set('danger', 'No order selected for payment.');
    header('Location: restaurant_front_desk.php');
    exit;
}

$order = get_order_full($conn, $order_id);
if (!$order) {
    flash_set('danger', 'Order not found.');
    header('Location: restaurant_front_desk.php');
    exit;
}

if (in_array($order['status'], ['paid', 'cancelled'])) {
    flash_set('danger', 'This order is already ' . $order['status'] . ' and cannot accept further payment.');
    header('Location: restaurant_order_list.php');
    exit;
}

$errors = [];
$items = get_order_items($conn, $order_id);
$payments = [];
$res = mysqli_query($conn, "SELECT * FROM restaurant_payments WHERE order_id = {$order_id} ORDER BY paid_at ASC");
while ($p = mysqli_fetch_assoc($res)) $payments[] = $p;

$total_paid_so_far = get_order_payments_total($conn, $order_id);
$due_now = max(0, (float)$order['total_amount'] - $total_paid_so_far);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['receive_payment', 'mark_paid'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $action = $_POST['action'];
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $transaction_no = trim($_POST['transaction_no'] ?? '');
        $valid_methods = ['cash','card','mobile_banking','bank','other'];
        if (!in_array($payment_method, $valid_methods)) $payment_method = 'cash';

        if ($action === 'mark_paid') {
            // Mark Paid requires the remaining due to be settled by exactly this payment (or already zero)
            if ($due_now > 0.009 && $payment_amount < $due_now - 0.01) {
                $errors[] = 'To mark this order as Paid, the payment must cover the full remaining due (৳' . number_format($due_now, 2) . ').';
            }
        }

        if ($payment_amount < 0) {
            $errors[] = 'Payment amount cannot be negative.';
        }
        if ($payment_amount > $due_now + 0.01) {
            $errors[] = 'Payment amount cannot exceed the remaining due (৳' . number_format($due_now, 2) . ').';
        }
        if ($action === 'receive_payment' && $payment_amount <= 0) {
            $errors[] = 'Enter a payment amount greater than zero.';
        }

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            $ok = true;

            if ($payment_amount > 0) {
                $payment_type = ($due_now - $payment_amount) <= 0.009 ? 'final_payment' : 'initial_payment';
                $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_payments (order_id, payment_type, payment_method, amount, transaction_no, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'issdsi', $order_id, $payment_type, $payment_method, $payment_amount, $transaction_no, $user['id']);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $ok = recalc_order_total($conn, $order_id);
            }

            // Re-check due after this payment; if zero, mark paid + free the table
            if ($ok) {
                $fresh = get_order_full($conn, $order_id);
                if ((float)$fresh['due_amount'] <= 0.009) {
                    $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_orders SET status = 'paid' WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'i', $order_id);
                    $ok = mysqli_stmt_execute($stmt) && $ok;
                }
            }

            if ($ok) {
                mysqli_commit($conn);
                flash_set('success', 'Payment recorded successfully.');
                header('Location: restaurant_receipt.php?order_id=' . $order_id);
                exit;
            } else {
                mysqli_rollback($conn);
                $errors[] = 'Failed to record payment: ' . mysqli_error($conn);
            }
        }
    }
}

$page_title = 'Payment';
$active_menu = 'restaurant_front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .pos-panel { border-radius: 14px; background: #fff; border: 1px solid #e8ebe9; padding: 20px; height: 100%; }
    .pos-panel h6 { font-weight: 700; color: #1c3d2e; margin-bottom: 14px; }
    .info-line { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.85rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .section-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8a938e; font-weight: 700; margin: 14px 0 6px; }
    .mini-table { font-size: 0.8rem; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="mb-3">
    <a href="restaurant_front_desk.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Front Desk</a>
</div>

<div class="row g-3">
    <!-- LEFT: Order info -->
    <div class="col-lg-6">
        <div class="pos-panel">
            <h6><i class="bi bi-receipt me-1"></i>Order Information</h6>
            <div class="info-line"><span class="label">Order No</span><span class="value"><?= e($order['order_no']) ?></span></div>
            <div class="info-line"><span class="label">Table</span><span class="value"><?= e($order['table_no']) ?> (<?= e($order['table_type']) ?>)</span></div>
            <div class="info-line"><span class="label">Status</span><span class="value"><span class="badge <?= order_status_badge($order['status']) ?>"><?= e(ucfirst($order['status'])) ?></span></span></div>
            <div class="info-line"><span class="label">Order Time</span><span class="value"><?= e(date('d M Y, h:i A', strtotime($order['order_datetime']))) ?></span></div>

            <div class="section-label">Ordered Items</div>
            <table class="table table-sm mini-table mb-0">
                <thead><tr><th>Item</th><th>Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['item_name']) ?><?= $it['size'] ? ' <span class="text-muted small">(' . e($it['size']) . ')</span>' : '' ?></td>
                        <td><?= (int)$it['qty'] ?></td>
                        <td class="text-end">৳<?= number_format((float)$it['price'], 2) ?></td>
                        <td class="text-end">৳<?= number_format((float)$it['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="section-label">Previous Payments</div>
            <?php if (empty($payments)): ?>
                <div class="text-muted small">No payments recorded yet.</div>
            <?php else: ?>
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Type</th><th>Method</th><th class="text-end">Amount</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e(ucfirst(str_replace('_',' ',$p['payment_type']))) ?></td>
                            <td><?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></td>
                            <td class="text-end">৳<?= number_format((float)$p['amount'], 2) ?></td>
                            <td class="text-muted"><?= e(date('d M, h:i A', strtotime($p['paid_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="total-box">
                <div class="d-flex justify-content-between small text-muted">
                    <span>Subtotal</span><span>৳<?= number_format((float)$order['subtotal'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Tax (+)</span><span>৳<?= number_format((float)$order['tax'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Discount (−)</span><span>৳<?= number_format((float)$order['discount'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="fw-semibold">Total</span>
                    <span class="grand">৳<?= number_format((float)$order['total_amount'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span>Total Paid</span><span>৳<?= number_format($total_paid_so_far, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small fw-semibold mt-1">
                    <span>Due</span><span class="<?= $due_now > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format($due_now, 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Payment section -->
    <div class="col-lg-6">
        <form method="POST" id="paymentForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="receive_payment">
            <input type="hidden" name="order_id" value="<?= $order_id ?>">

            <div class="pos-panel">
                <h6><i class="bi bi-cash-coin me-1"></i>Payment</h6>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="mobile_banking">Mobile Banking</option>
                            <option value="bank">Bank</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Transaction No (optional)</label>
                        <input type="text" name="transaction_no" class="form-control" maxlength="100">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Payment Amount</label>
                        <input type="number" step="0.01" min="0" name="payment_amount" id="payment_amount" class="form-control" value="<?= $due_now > 0 ? number_format($due_now, 2, '.', '') : '0' ?>" max="<?= $due_now ?>" oninput="validatePayment()">
                        <div class="form-text text-danger d-none" id="paymentError">Payment cannot exceed the remaining due amount.</div>
                    </div>
                </div>

                <div class="total-box">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Current Due</span><span>৳<?= number_format($due_now, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="fw-semibold">Due After This Payment</span>
                        <span class="grand" id="calc_remaining">৳<?= number_format($due_now, 2) ?></span>
                    </div>
                </div>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-brand btn-sm" id="receiveBtn" onclick="setAction('receive_payment')">
                        <i class="bi bi-cash me-1"></i> Receive Payment
                    </button>
                    <button type="submit" class="btn btn-success btn-sm" id="markPaidBtn" onclick="setAction('mark_paid')" <?= $due_now > 0.009 ? '' : 'disabled' ?>>
                        <i class="bi bi-check-circle me-1"></i> Mark Paid
                    </button>
                    <a href="restaurant_receipt.php?order_id=<?= $order_id ?>" class="btn btn-outline-brand btn-sm">
                        <i class="bi bi-printer me-1"></i> Print POS Receipt
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const dueNow = <?= $due_now ?>;

function setAction(action) {
    document.getElementById('formAction').value = action;
}

function validatePayment() {
    const payment = parseFloat(document.getElementById('payment_amount').value) || 0;
    const remaining = Math.max(0, dueNow - payment);
    document.getElementById('calc_remaining').innerText = '৳' + remaining.toFixed(2);

    const errBox = document.getElementById('paymentError');
    const receiveBtn = document.getElementById('receiveBtn');
    if (payment > dueNow + 0.01) {
        errBox.classList.remove('d-none');
        receiveBtn.disabled = true;
    } else {
        errBox.classList.add('d-none');
        receiveBtn.disabled = false;
    }
}

validatePayment();
</script>

<?php require __DIR__ . '/footer.php'; ?>
