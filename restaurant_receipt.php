<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    flash_set('danger', 'No order selected for receipt.');
    header('Location: restaurant_front_desk.php');
    exit;
}

$order = get_order_full($conn, $order_id);
if (!$order) {
    flash_set('danger', 'Order not found.');
    header('Location: restaurant_front_desk.php');
    exit;
}

$items = get_order_items($conn, $order_id);
$payments = [];
$res = mysqli_query($conn, "SELECT * FROM restaurant_payments WHERE order_id = {$order_id} ORDER BY paid_at ASC");
while ($p = mysqli_fetch_assoc($res)) $payments[] = $p;

$total_paid = get_order_payments_total($conn, $order_id);
$due = max(0, (float)$order['total_amount'] - $total_paid);
$is_paid = $due <= 0.009;

$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($row = mysqli_fetch_assoc($res)) $settings[$row['setting_key']] = $row['setting_value'];
$resort_name = $settings['resort_name'] ?? 'Resort';
$resort_address = $settings['resort_address'] ?? '';
$resort_phone = $settings['resort_phone'] ?? '';
$resort_website = $settings['resort_website'] ?? '';

$is_active_order = in_array($order['status'], ['hold', 'ordered']);
$cashier_name = current_user()['full_name'];

$page_title = 'Order Receipt';
$active_menu = 'restaurant_order_list';
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
    .due-note { background: #fff3cd; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .paid-note { background: #d1f5e0; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }

    #printReceipt { display: none; font-family: Arial, Helvetica, sans-serif; }

    @media print {
        body * { visibility: hidden; }
        #printReceipt, #printReceipt * { visibility: visible; }
        #printReceipt { display: block !important; position: absolute; top: 0; left: 0; width: 100%; }

        .print-pos #printReceipt { max-width: 80mm; margin: 0 auto; }
        .print-a4 #printReceipt { max-width: 100%; padding: 10mm; }
    }

    .print-header { text-align: center; border-bottom: 2px dashed #dcdfdd; padding-bottom: 12px; margin-bottom: 14px; }
    .print-header h4 { margin: 0; font-weight: 800; color: #0f5132; }
    .print-header .muted { color: #666; font-size: 0.82rem; }
    .print-info-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    table.print-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; margin-top: 10px; }
    table.print-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 5px 3px; font-size: 0.72rem; text-transform: uppercase; }
    table.print-table td { padding: 5px 3px; border-bottom: 1px dashed #eee; }
    .print-totals { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .print-totals .line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-totals .grand { font-size: 1.2rem; font-weight: 800; color: #0f5132; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 8px; }
    .print-due-note { background: #fff3cd; color: #664d03; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }
    .print-paid-note { background: #d1f5e0; color: #0f5132; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }

    /* On-screen POS-style receipt */
    .receipt-wrap { display:flex; justify-content:center; }
    .receipt-slip {
        width:100%; max-width:420px; background:#fff; border:1px solid #e2e5e3;
        border-radius:6px; box-shadow:0 2px 10px rgba(20,40,30,.06);
        padding:20px 18px 16px; font-family:'Courier New', Courier, monospace; color:#1e2b23;
    }
    .receipt-slip .r-header { text-align:center; border-bottom:2px dashed #d7dbd8; padding-bottom:12px; margin-bottom:12px; }
    .receipt-slip .r-header .r-name { font-weight:800; font-size:1.05rem; color:#0f5132; letter-spacing:.02em; }
    .receipt-slip .r-header .r-sub { font-size:.72rem; color:#6c776f; margin-top:2px; line-height:1.4; }
    .receipt-slip .r-header .r-invoice { font-size:.7rem; color:#8a938e; margin-top:6px; }
    .receipt-slip .r-status-chip { display:block; text-align:center; font-size:.68rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; padding:3px 0; margin-bottom:10px; border-radius:4px; background:#eef2f0; color:#1c3d2e; }
    .receipt-slip .r-row { display:flex; justify-content:space-between; font-size:.78rem; padding:2px 0; gap:10px; }
    .receipt-slip .r-row .r-k { color:#6c776f; }
    .receipt-slip .r-row .r-v { font-weight:600; text-align:right; }
    .receipt-slip .r-divider { border:none; border-top:1px dashed #d7dbd8; margin:10px 0; }
    .receipt-slip .r-section-title { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#6c776f; font-weight:700; margin:10px 0 4px; }
    .receipt-slip .r-item { display:flex; justify-content:space-between; align-items:baseline; font-size:.78rem; padding:3px 0; gap:8px; }
    .receipt-slip .r-item .r-item-name { flex:1 1 auto; }
    .receipt-slip .r-item .r-item-sub { display:block; font-size:.68rem; color:#9aa39d; }
    .receipt-slip .r-item .r-item-amt { flex:0 0 auto; font-weight:600; white-space:nowrap; }
    .receipt-slip .r-totals { border-top:2px dashed #d7dbd8; margin-top:12px; padding-top:10px; }
    .receipt-slip .r-totals .r-row { font-size:.8rem; }
    .receipt-slip .r-totals .r-grand { display:flex; justify-content:space-between; font-size:1.05rem; font-weight:800; color:#0f5132; border-top:1px solid #d7dbd8; margin-top:6px; padding-top:8px; }
    .receipt-slip .r-totals .r-paid-row { font-size:.78rem; color:#6c776f; padding:2px 0; display:flex; justify-content:space-between; }
    .receipt-slip .r-totals .r-due-row { display:flex; justify-content:space-between; font-size:.85rem; font-weight:700; margin-top:4px; padding-top:4px; border-top:1px dashed #d7dbd8; }
    .receipt-slip .r-payment-head, .receipt-slip .r-payment-row { display:grid; grid-template-columns:1.15fr 1fr 1fr .85fr; gap:8px; align-items:center; }
    .receipt-slip .r-payment-head { padding:7px 0; color:#8a938e; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.025em; border-bottom:1px solid #e8ebe9; }
    .receipt-slip .r-payment-row { padding:8px 0; font-size:.7rem; color:#4f5a54; border-bottom:1px dashed #e5e8e6; }
    .receipt-slip .r-payment-row strong { color:#0f5132; white-space:nowrap; }
    .receipt-slip .r-footer { text-align:center; font-size:.7rem; color:#9aa39d; margin-top:14px; border-top:2px dashed #d7dbd8; padding-top:10px; }
    .receipt-slip .r-footer .r-thanks { font-weight:700; color:#1c3d2e; font-size:.78rem; margin-bottom:2px; }

    @media (max-width: 991.98px) {
        .receipt-slip { max-width:100%; }
    }
</style>



<div class="row g-3">
    <!-- LEFT: on-screen POS-style receipt -->
    <div class="col-lg-7">
        <div class="receipt-wrap">
            <div class="receipt-slip">
                <div class="r-header">
                    <div class="r-name"><?= e($resort_name) ?></div>
                    <div class="r-sub">Restaurant</div>
                    <?php if ($resort_address): ?><div class="r-sub"><?= e($resort_address) ?></div><?php endif; ?>
                    <?php if ($resort_phone || $resort_website): ?>
                    <div class="r-sub"><?= e($resort_phone) ?><?= ($resort_phone && $resort_website) ? ' · ' : '' ?><?= e($resort_website) ?></div>
                    <?php endif; ?>
                    <div class="r-invoice">Order: <?= e($order['order_no']) ?><br><?= e(date('d M Y, h:i A')) ?></div>
                </div>

                <span class="r-status-chip"><?= e(ucwords(str_replace('_',' ',$order['status']))) ?></span>

                <div class="r-row"><span class="r-k">Table</span><span class="r-v"><?= e($order['table_no']) ?> (<?= e($order['table_type']) ?>)</span></div>
                <div class="r-row"><span class="r-k">Floor</span><span class="r-v"><?= e($order['floor'] ?: '—') ?></span></div>
                <div class="r-row"><span class="r-k">Order Time</span><span class="r-v"><?= e(date('d M Y, h:i A', strtotime($order['order_datetime']))) ?></span></div>
                <div class="r-row"><span class="r-k">Cashier</span><span class="r-v"><?= e($cashier_name) ?></span></div>

                <hr class="r-divider">

                <div class="r-section-title">Items</div>
                <?php foreach ($items as $it): ?>
                    <div class="r-item">
                        <span class="r-item-name">
                            <?= e($it['item_name']) ?>
                            <?php if ($it['size']): ?><span class="r-item-sub"><?= e($it['size']) ?> × <?= (int)$it['qty'] ?> @ ৳<?= number_format((float)$it['price'], 2) ?></span>
                            <?php else: ?><span class="r-item-sub">Qty: <?= (int)$it['qty'] ?> @ ৳<?= number_format((float)$it['price'], 2) ?></span><?php endif; ?>
                        </span>
                        <span class="r-item-amt">৳<?= number_format((float)$it['total'], 2) ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="r-totals">
                    <div class="r-row"><span class="r-k">Subtotal</span><span class="r-v">৳<?= number_format((float)$order['subtotal'], 2) ?></span></div>
                    <?php if ((float)$order['tax'] > 0): ?>
                    <div class="r-row"><span class="r-k">Tax</span><span class="r-v">৳<?= number_format((float)$order['tax'], 2) ?></span></div>
                    <?php endif; ?>
                    <?php if ((float)$order['discount'] > 0): ?>
                    <div class="r-row"><span class="r-k">Discount</span><span class="r-v">− ৳<?= number_format((float)$order['discount'], 2) ?></span></div>
                    <?php endif; ?>
                    <div class="r-grand"><span>Grand Total</span><span>৳<?= number_format((float)$order['total_amount'], 2) ?></span></div>
                    <div class="r-paid-row"><span>Total Paid</span><span>৳<?= number_format($total_paid, 2) ?></span></div>
                    <div class="r-due-row" style="color: <?= $due > 0 ? '#b3261e' : '#0f5132' ?>;">
                        <span>Due</span><span>৳<?= number_format($due, 2) ?></span>
                    </div>
                </div>

                <?php if (!empty($payments)): ?>
                <div class="r-section-title">Payment History</div>
                <div class="r-payment-head">
                    <span>Date &amp; Time</span><span>Payment Type</span><span>Payment Method</span><span class="text-end">Amount</span>
                </div>
                <?php foreach ($payments as $p): ?>
                    <div class="r-payment-row">
                        <span><?= e(date('d M, h:i A', strtotime($p['paid_at']))) ?></span>
                        <span><?= e(ucfirst(str_replace('_',' ',$p['payment_type']))) ?></span>
                        <span><?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></span>
                        <strong class="text-end">৳ <?= number_format((float)$p['amount'], 2) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <div class="r-footer">
                    <div class="r-thanks">Thank you for visiting us!</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="pos-panel">
            <h6><i class="bi bi-cash-stack me-1"></i>Financial Summary</h6>

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
                    <span class="fw-semibold">Grand Total</span>
                    <span class="grand">৳<?= number_format((float)$order['total_amount'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span>Total Paid</span><span>৳<?= number_format($total_paid, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small fw-semibold mt-1">
                    <span>Due</span><span class="<?= $due > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format($due, 2) ?></span>
                </div>
            </div>

            <?php if (!$is_paid): ?>
                <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Due: ৳<?= number_format($due, 2) ?> — will be settled later.</div>
            <?php else: ?>
                <div class="paid-note"><i class="bi bi-check-circle me-1"></i>Paid</div>
            <?php endif; ?>

            <div class="section-label">Actions</div>
            <div class="d-grid gap-2">
                <?php if ($is_active_order): ?>
                    <a href="restaurant_food_orders.php?order_id=<?= $order_id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil-square me-1"></i>Edit Order</a>
                    <a href="restaurant_payment.php?order_id=<?= $order_id ?>" class="btn btn-brand btn-sm"><i class="bi bi-cash-coin me-1"></i>Payment</a>
                <?php endif; ?>
                <button class="btn btn-outline-brand btn-sm" onclick="printReceipt('pos')"><i class="bi bi-printer me-1"></i>Print POS</button>
                <button class="btn btn-brand btn-sm" onclick="printReceipt('a4')"><i class="bi bi-file-earmark-text me-1"></i>Print A4</button>
            </div>
        </div>
    </div>
</div>

<div id="printReceipt">
    <div class="print-header">
        <h4><?= e($resort_name) ?></h4>
        <div class="muted">Restaurant</div>
        <div class="muted"><?= e($resort_address) ?></div>
        <div class="muted"><?= e($resort_phone) ?><?= $resort_website ? ' · ' . e($resort_website) : '' ?></div>
        <div class="muted mt-1">Order: <?= e($order['order_no']) ?> · <?= e(date('d M Y, h:i A')) ?></div>
    </div>

    <div class="print-info-row"><span>Table</span><span><?= e($order['table_no']) ?></span></div>
    <div class="print-info-row"><span>Floor</span><span><?= e($order['floor'] ?: '—') ?></span></div>
    <div class="print-info-row"><span>Order Time</span><span><?= e(date('d M Y, h:i A', strtotime($order['order_datetime']))) ?></span></div>
    <div class="print-info-row"><span>Cashier</span><span><?= e($cashier_name) ?></span></div>

    <table class="print-table">
        <thead><tr><th>Item</th><th>Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['item_name']) ?><?= $it['size'] ? ' (' . e($it['size']) . ')' : '' ?></td>
                <td><?= (int)$it['qty'] ?></td>
                <td class="text-end">৳<?= number_format((float)$it['price'], 2) ?></td>
                <td class="text-end">৳<?= number_format((float)$it['total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-totals">
        <div class="line"><span>Subtotal</span><span>৳<?= number_format((float)$order['subtotal'], 2) ?></span></div>
        <?php if ((float)$order['tax'] > 0): ?>
        <div class="line"><span>Tax</span><span>৳<?= number_format((float)$order['tax'], 2) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$order['discount'] > 0): ?>
        <div class="line"><span>Discount</span><span>− ৳<?= number_format((float)$order['discount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="line grand"><span>Grand Total</span><span>৳<?= number_format((float)$order['total_amount'], 2) ?></span></div>
        <div class="line"><span>Total Paid</span><span>৳<?= number_format($total_paid, 2) ?></span></div>
        <div class="line"><span>Due</span><span>৳<?= number_format($due, 2) ?></span></div>
    </div>

    <?php if ($due > 0.009): ?>
        <div class="print-due-note">Due will be settled later.</div>
    <?php else: ?>
        <div class="print-paid-note">Paid</div>
    <?php endif; ?>

    <div class="text-center text-muted mt-3" style="font-size:0.75rem;">Thank you for visiting us.</div>
</div>

<script>
function printReceipt(mode) {
    document.body.classList.remove('print-pos', 'print-a4');
    document.body.classList.add(mode === 'a4' ? 'print-a4' : 'print-pos');
    window.print();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
