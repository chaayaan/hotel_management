<?php
/**
 * Payroll > Salary Slip
 * Same layout as restaurant_receipt.php: on-screen slip + financial summary panel,
 * with separate POS (80mm) and A4 print layouts.
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'No salary record selected.');
    header('Location: payroll_list.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, e.name, d.name AS designation, d.department,
           pay.payment_date, pay.payment_method, pay.amount_paid, pay.status AS payment_status
    FROM payroll_payroll p
    JOIN payroll_employees e ON e.id = p.employee_id
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    LEFT JOIN payroll_payments pay ON pay.payroll_id = p.id
    WHERE p.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$slip = $stmt->get_result()->fetch_assoc();

if (!$slip) {
    flash_set('danger', 'Salary slip not found.');
    header('Location: payroll_list.php');
    exit;
}

/* ---------- Derived figures ---------- */
$perDay     = $slip['total_days'] > 0 ? $slip['basic_salary'] / $slip['total_days'] : 0;
$net        = (float) $slip['calculated_salary'];
$hasPayment = $slip['payment_date'] !== null;
$totalPaid  = (float) ($slip['amount_paid'] ?? 0);
$due        = max(0, $net - $totalPaid);
$isPaid     = $hasPayment && $due <= 0.009;
$noPayable  = $net <= 0.009;
$period     = monthName($slip['month']) . ' ' . (int) $slip['year'];
$slipNo     = 'SAL-' . sprintf('%04d%02d', (int) $slip['year'], (int) $slip['month']) . '-' . str_pad((string) $slip['id'], 4, '0', STR_PAD_LEFT);
$statusText = $slip['payment_status'] ?? 'Not Paid';
$generated  = date('d M Y', strtotime($slip['generated_at']));

/* ---------- Resort details (same settings table the restaurant receipt uses) ---------- */
$settings = [];
try {
    $res = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $ex) { /* settings table missing: fall back to defaults */ }
$resort_name    = $settings['resort_name'] ?? 'Resort';
$resort_address = $settings['resort_address'] ?? '';
$resort_phone   = $settings['resort_phone'] ?? '';
$resort_website = $settings['resort_website'] ?? '';

$page_title  = 'Salary Slip';
$active_menu = 'payroll_list';
require_once __DIR__ . '/navbar.php';
?>

<style>
    .pos-panel { border-radius: 14px; background: #fff; border: 1px solid #e8ebe9; padding: 20px; height: 100%; }
    .pos-panel h6 { font-weight: 700; color: #1c3d2e; margin-bottom: 14px; }
    .section-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8a938e; font-weight: 700; margin: 14px 0 6px; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
    .due-note { background: #fff3cd; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .paid-note { background: #d1f5e0; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .neutral-note { background: #eef2f0; color: #1c3d2e; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }

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
    .print-info-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; gap: 10px; }
    .print-section { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: #555; margin: 14px 0 4px; }
    table.print-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; margin-top: 6px; }
    table.print-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 5px 3px; font-size: 0.72rem; text-transform: uppercase; }
    table.print-table td { padding: 5px 3px; border-bottom: 1px dashed #eee; }
    .print-totals { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .print-totals .line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-totals .grand { font-size: 1.2rem; font-weight: 800; color: #0f5132; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 8px; }
    .print-due-note { background: #fff3cd; color: #664d03; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }
    .print-paid-note { background: #d1f5e0; color: #0f5132; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }
    .print-sign { display: flex; justify-content: space-between; gap: 24px; margin-top: 36px; font-size: 0.75rem; color: #555; }
    .print-sign div { flex: 1; border-top: 1px solid #999; padding-top: 4px; text-align: center; }

    /* On-screen POS-style slip */
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
    .receipt-slip .r-att { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; text-align:center; margin:4px 0 2px; }
    .receipt-slip .r-att div { background:#f4f6f5; border-radius:4px; padding:6px 2px; }
    .receipt-slip .r-att .n { display:block; font-weight:800; font-size:.95rem; color:#1c3d2e; }
    .receipt-slip .r-att .l { display:block; font-size:.6rem; text-transform:uppercase; letter-spacing:.03em; color:#8a938e; }
    .receipt-slip .r-totals { border-top:2px dashed #d7dbd8; margin-top:12px; padding-top:10px; }
    .receipt-slip .r-totals .r-row { font-size:.8rem; }
    .receipt-slip .r-totals .r-grand { display:flex; justify-content:space-between; font-size:1.05rem; font-weight:800; color:#0f5132; border-top:1px solid #d7dbd8; margin-top:6px; padding-top:8px; }
    .receipt-slip .r-totals .r-paid-row { font-size:.78rem; color:#6c776f; padding:2px 0; display:flex; justify-content:space-between; }
    .receipt-slip .r-totals .r-due-row { display:flex; justify-content:space-between; font-size:.85rem; font-weight:700; margin-top:4px; padding-top:4px; border-top:1px dashed #d7dbd8; }
    .receipt-slip .r-footer { text-align:center; font-size:.7rem; color:#9aa39d; margin-top:14px; border-top:2px dashed #d7dbd8; padding-top:10px; }
    .receipt-slip .r-footer .r-thanks { font-weight:700; color:#1c3d2e; font-size:.78rem; margin-bottom:2px; }

    @media (max-width: 991.98px) {
        .receipt-slip { max-width:100%; }
    }
</style>

<div class="row g-3">
    <!-- LEFT: on-screen POS-style salary slip -->
    <div class="col-lg-7">
        <div class="receipt-wrap">
            <div class="receipt-slip">
                <div class="r-header">
                    <div class="r-name"><?= e($resort_name) ?></div>
                    <div class="r-sub">Salary Slip</div>
                    <?php if ($resort_address): ?><div class="r-sub"><?= e($resort_address) ?></div><?php endif; ?>
                    <?php if ($resort_phone || $resort_website): ?>
                    <div class="r-sub"><?= e($resort_phone) ?><?= ($resort_phone && $resort_website) ? ' · ' : '' ?><?= e($resort_website) ?></div>
                    <?php endif; ?>
                    <div class="r-invoice">Slip: <?= e($slipNo) ?><br>Generated <?= e($generated) ?></div>
                </div>

                <span class="r-status-chip"><?= e($statusText) ?></span>

                <div class="r-row"><span class="r-k">Employee</span><span class="r-v"><?= e($slip['name']) ?></span></div>
                <div class="r-row"><span class="r-k">Designation</span><span class="r-v"><?= e($slip['designation'] ?: '—') ?></span></div>
                <div class="r-row"><span class="r-k">Department</span><span class="r-v"><?= e($slip['department'] ?: '—') ?></span></div>
                <div class="r-row"><span class="r-k">Pay Period</span><span class="r-v"><?= e($period) ?></span></div>

                <hr class="r-divider">

                <div class="r-section-title">Attendance</div>
                <div class="r-att">
                    <div><span class="n"><?= (int) $slip['total_days'] ?></span><span class="l">Total</span></div>
                    <div><span class="n"><?= (int) $slip['present_days'] ?></span><span class="l">Present</span></div>
                    <div><span class="n"><?= (int) $slip['absent_days'] ?></span><span class="l">Absent</span></div>
                    <div><span class="n"><?= (int) $slip['leave_days'] ?></span><span class="l">Leave</span></div>
                </div>

                <hr class="r-divider">

                <div class="r-section-title">Salary</div>
                <div class="r-row"><span class="r-k">Basic Monthly Salary</span><span class="r-v"><?= money($slip['basic_salary']) ?></span></div>
                <div class="r-row"><span class="r-k">Per Day Rate</span><span class="r-v"><?= money($perDay) ?></span></div>
                <div class="r-row"><span class="r-k">Present Days Paid</span><span class="r-v"><?= (int) $slip['present_days'] ?> day(s)</span></div>

                <div class="r-totals">
                    <div class="r-grand"><span>Net Payable</span><span><?= money($net) ?></span></div>
                    <div class="r-paid-row"><span>Total Paid</span><span><?= money($totalPaid) ?></span></div>
                    <div class="r-due-row" style="color: <?= $due > 0 ? '#b3261e' : '#0f5132' ?>;">
                        <span>Balance Due</span><span><?= money($due) ?></span>
                    </div>
                </div>

                <div class="r-section-title">Payment</div>
                <?php if ($hasPayment): ?>
                    <div class="r-row"><span class="r-k">Date</span><span class="r-v"><?= e(date('d M Y', strtotime($slip['payment_date']))) ?></span></div>
                    <div class="r-row"><span class="r-k">Method</span><span class="r-v"><?= e($slip['payment_method']) ?></span></div>
                    <div class="r-row"><span class="r-k">Amount</span><span class="r-v"><?= money($slip['amount_paid']) ?></span></div>
                    <div class="r-row"><span class="r-k">Status</span><span class="r-v"><?= e($slip['payment_status']) ?></span></div>
                <?php else: ?>
                    <div class="r-row"><span class="r-k">Payment not recorded yet.</span></div>
                <?php endif; ?>

                <div class="r-footer">
                    <div class="r-thanks">Thank you for your service!</div>
                    This is a computer-generated salary slip.
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT: financial summary + actions -->
    <div class="col-lg-5">
        <div class="pos-panel">
            <h6><i class="bi bi-cash-stack me-1"></i>Financial Summary</h6>

            <div class="total-box">
                <div class="d-flex justify-content-between small text-muted">
                    <span>Basic monthly salary</span><span><?= money($slip['basic_salary']) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Per day rate</span><span><?= money($perDay) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Present days (×)</span><span><?= (int) $slip['present_days'] ?> of <?= (int) $slip['total_days'] ?></span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="fw-semibold">Net Payable</span>
                    <span class="grand"><?= money($net) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span>Total Paid</span><span><?= money($totalPaid) ?></span>
                </div>
                <div class="d-flex justify-content-between small fw-semibold mt-1">
                    <span>Balance Due</span><span class="<?= $due > 0 ? 'text-danger' : 'text-success' ?>"><?= money($due) ?></span>
                </div>
            </div>

            <?php if ($noPayable): ?>
                <div class="neutral-note"><i class="bi bi-info-circle me-1"></i>No salary payable: no present days this month.</div>
            <?php elseif ($isPaid): ?>
                <div class="paid-note"><i class="bi bi-check-circle me-1"></i>Paid in full</div>
            <?php elseif ($hasPayment): ?>
                <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Partly paid. <?= money($due) ?> still to pay.</div>
            <?php else: ?>
                <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Not paid yet. <?= money($due) ?> to pay.</div>
            <?php endif; ?>

            <div class="section-label">Actions</div>
            <div class="d-grid gap-2">
                <a href="payroll_payment.php?payroll_id=<?= (int) $slip['id'] ?>" class="btn btn-brand btn-sm">
                    <i class="bi bi-cash-coin me-1"></i><?= $hasPayment ? 'Edit Payment' : 'Record Payment' ?>
                </a>
                <button class="btn btn-outline-brand btn-sm" onclick="printReceipt('pos')"><i class="bi bi-printer me-1"></i>Print POS</button>
                <button class="btn btn-brand btn-sm" onclick="printReceipt('a4')"><i class="bi bi-file-earmark-text me-1"></i>Print A4</button>
                <a href="payroll_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Payroll</a>
            </div>
        </div>
    </div>
</div>

<!-- Print layout (hidden on screen; shown only when printing) -->
<div id="printReceipt">
    <div class="print-header">
        <h4><?= e($resort_name) ?></h4>
        <div class="muted">Salary Slip</div>
        <?php if ($resort_address): ?><div class="muted"><?= e($resort_address) ?></div><?php endif; ?>
        <?php if ($resort_phone || $resort_website): ?>
        <div class="muted"><?= e($resort_phone) ?><?= ($resort_phone && $resort_website) ? ' · ' : '' ?><?= e($resort_website) ?></div>
        <?php endif; ?>
        <div class="muted mt-1">Slip: <?= e($slipNo) ?> · <?= e($period) ?></div>
    </div>

    <div class="print-info-row"><span>Employee</span><span><?= e($slip['name']) ?></span></div>
    <div class="print-info-row"><span>Designation</span><span><?= e($slip['designation'] ?: '—') ?></span></div>
    <div class="print-info-row"><span>Department</span><span><?= e($slip['department'] ?: '—') ?></span></div>
    <div class="print-info-row"><span>Pay Period</span><span><?= e($period) ?></span></div>
    <div class="print-info-row"><span>Generated</span><span><?= e($generated) ?></span></div>

    <div class="print-section">Attendance</div>
    <table class="print-table">
        <thead><tr><th>Total</th><th>Present</th><th>Absent</th><th>Leave</th></tr></thead>
        <tbody>
            <tr>
                <td><?= (int) $slip['total_days'] ?></td>
                <td><?= (int) $slip['present_days'] ?></td>
                <td><?= (int) $slip['absent_days'] ?></td>
                <td><?= (int) $slip['leave_days'] ?></td>
            </tr>
        </tbody>
    </table>

    <div class="print-section">Salary</div>
    <table class="print-table">
        <tbody>
            <tr><td>Basic monthly salary</td><td class="text-end"><?= money($slip['basic_salary']) ?></td></tr>
            <tr><td>Per day rate</td><td class="text-end"><?= money($perDay) ?></td></tr>
            <tr><td>Present days paid</td><td class="text-end"><?= (int) $slip['present_days'] ?> day(s)</td></tr>
        </tbody>
    </table>

    <div class="print-totals">
        <div class="line grand"><span>Net Payable</span><span><?= money($net) ?></span></div>
        <div class="line"><span>Total Paid</span><span><?= money($totalPaid) ?></span></div>
        <div class="line"><span>Balance Due</span><span><?= money($due) ?></span></div>
    </div>

    <?php if ($hasPayment): ?>
        <div class="print-section">Payment</div>
        <div class="print-info-row"><span>Date</span><span><?= e(date('d M Y', strtotime($slip['payment_date']))) ?></span></div>
        <div class="print-info-row"><span>Method</span><span><?= e($slip['payment_method']) ?></span></div>
        <div class="print-info-row"><span>Status</span><span><?= e($slip['payment_status']) ?></span></div>
    <?php endif; ?>

    <?php if ($noPayable): ?>
        <div class="print-due-note">No salary payable this month.</div>
    <?php elseif ($isPaid): ?>
        <div class="print-paid-note">Paid in full</div>
    <?php else: ?>
        <div class="print-due-note"><?= $hasPayment ? 'Partly paid. ' : 'Not paid yet. ' ?>Balance <?= money($due) ?></div>
    <?php endif; ?>

    <div class="print-sign">
        <div>Employee signature</div>
        <div>Authorised by</div>
    </div>

    <div class="text-center text-muted mt-3" style="font-size:0.75rem;">This is a computer-generated salary slip.</div>
</div>

<script>
function printReceipt(mode) {
    document.body.classList.remove('print-pos', 'print-a4');
    document.body.classList.add(mode === 'a4' ? 'print-a4' : 'print-pos');
    window.print();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>