<?php
/**
 * Payroll > Salary Slip
 * Formal salary slip: on-screen preview + financial summary panel, with separate
 * POS (80mm) and A4 print layouts. Printing is done through a hidden iframe so the
 * page margins can be controlled per layout (POS default margin = 0.5cm).
 */
require_once __DIR__ . '/auth.php';
require_role(['admin', 'general_manager']);
require_once __DIR__ . '/payroll_functions.php';
require_once __DIR__ . '/payroll_attendance_calendar.php';

/* ---------- Print settings ---------- */
$posMargin  = '0.5cm';   // default page margin for the POS (80mm) print
$a4Margin   = '12mm';    // page margin for the A4 print
$weekStart  = 0;         // first column of the calendar: 0 = Sunday, 1 = Monday, 6 = Saturday

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'No salary record selected.');
    header('Location: payroll_list.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.*, e.name, d.name AS designation, dept.name AS department,
           latest.payment_date, latest.payment_method
    FROM payroll_payroll p
    JOIN payroll_employees e ON e.id = p.employee_id
    LEFT JOIN payroll_designations d ON d.id = e.designation_id
    LEFT JOIN payroll_departments dept ON dept.id = d.department_id
    LEFT JOIN payroll_payments latest
           ON latest.id = (
                SELECT pay2.id FROM payroll_payments pay2
                WHERE pay2.payroll_id = p.id
                ORDER BY pay2.created_at DESC, pay2.id DESC
                LIMIT 1
              )
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
$perDay        = $slip['total_days'] > 0 ? $slip['basic_salary'] / $slip['total_days'] : 0;
$net           = (float) $slip['calculated_salary'];
$hasPayment    = $slip['payment_date'] !== null;
// amount_paid / amount_due are the running totals kept on payroll_payroll by
// recalcPayrollTotals() every time a payment transaction is saved.
$totalPaid     = (float) ($slip['amount_paid'] ?? 0);
$due           = (float) ($slip['amount_due'] ?? max(0, $net - $totalPaid));
$isPaid        = $due <= 0.009;
$noPayable     = $net <= 0.009;
$paymentStatus = paymentStatusFromTotals($totalPaid, $due);
$period     = monthName($slip['month']) . ' ' . (int) $slip['year'];
$slipNo     = 'SAL-' . sprintf('%04d%02d', (int) $slip['year'], (int) $slip['month']) . '-' . str_pad((string) $slip['id'], 4, '0', STR_PAD_LEFT);
$generated  = date('d M Y', strtotime($slip['generated_at']));

/* ---------- Daily attendance ---------- */
$attAll  = loadMonthAttendance($conn, (int) $slip['month'], (int) $slip['year'], [(int) $slip['employee_id']]);
$attDays = $attAll[(int) $slip['employee_id']] ?? [];
$attCnt  = attendanceCounts($attDays);
// Prefer the daily records; fall back to the stored payroll totals if none found.
$sumP = $attDays ? $attCnt['P'] : (int) $slip['present_days'];
$sumA = $attDays ? $attCnt['A'] : (int) $slip['absent_days'];
$sumL = $attDays ? $attCnt['L'] : (int) $slip['leave_days'];

/**
 * Compact formal attendance calendar (one small cell per day: "12 P").
 * $days = [dayNumber => 'P'|'A'|'L'] (array values with a 'code' key are accepted too).
 */
function slipCalendarHtml(array $days, int $month, int $year, int $weekStart = 0): string
{
    $weekend = defined('ATT_WEEKEND_DOW') ? (array) ATT_WEEKEND_DOW : [5];
    $names   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $first   = mktime(0, 0, 0, $month, 1, $year);
    $dim     = (int) date('t', $first);
    $offset  = ((int) date('w', $first) - $weekStart + 7) % 7;

    $h = '<table class="sl-cal"><thead><tr>';
    for ($i = 0; $i < 7; $i++) {
        $dow = ($weekStart + $i) % 7;
        $h  .= '<th' . (in_array($dow, $weekend, true) ? ' class="sl-w"' : '') . '>' . $names[$dow] . '</th>';
    }
    $h .= '</tr></thead><tbody><tr>';

    $cell = 0;
    for ($i = 0; $i < $offset; $i++, $cell++) {
        $h .= '<td class="sl-e"></td>';
    }
    for ($d = 1; $d <= $dim; $d++, $cell++) {
        if ($cell > 0 && $cell % 7 === 0) {
            $h .= '</tr><tr>';
        }
        $dow  = ($weekStart + $cell) % 7;
        $code = $days[$d] ?? '';
        if (is_array($code)) {
            $code = $code['code'] ?? '';
        }
        $code = in_array($code, ['P', 'A', 'L'], true) ? $code : '';
        $cls  = $code !== '' ? 'sl-' . $code : (in_array($dow, $weekend, true) ? 'sl-w' : '');
        $h   .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . '><span class="d">' . $d . '</span>'
              . ($code !== '' ? '<b class="s">' . $code . '</b>' : '') . '</td>';
    }
    while ($cell % 7 !== 0) {
        $h .= '<td class="sl-e"></td>';
        $cell++;
    }
    $h .= '</tr></tbody></table>';

    $h .= '<div class="sl-leg">'
        . '<span><b>P</b> Present</span><span><b>A</b> Absent</span><span><b>L</b> Leave</span>'
        . '<span><i class="sw"></i> Weekly off</span></div>';
    return $h;
}

function slipSummaryHtml(int $total, int $p, int $a, int $l): string
{
    return '<table class="sl-t sl-sum"><thead><tr><th>Attendance</th><th class="n">Days</th></tr></thead><tbody>'
         . '<tr><td>Total days in month</td><td class="n">' . $total . '</td></tr>'
         . '<tr><td>Present</td><td class="n">' . $p . '</td></tr>'
         . '<tr><td>Absent</td><td class="n">' . $a . '</td></tr>'
         . '<tr><td>Leave</td><td class="n">' . $l . '</td></tr>'
         . '</tbody></table>';
}

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
$contactLine    = implode(' · ', array_filter([$resort_phone, $resort_website]));

/* ---------- Slip markup (used for both the on-screen preview and the print) ---------- */
ob_start();
?>
<div class="sl-head">
    <div class="sl-org"><?= e($resort_name) ?></div>
    <?php if ($resort_address): ?><div class="sl-addr"><?= e($resort_address) ?></div><?php endif; ?>
    <?php if ($contactLine): ?><div class="sl-addr"><?= e($contactLine) ?></div><?php endif; ?>
    <div class="sl-title">SALARY SLIP</div>
</div>

<div class="sl-kv">
    <div class="col">
        <div class="kv"><span class="k">Employee</span><span class="v"><?= e($slip['name']) ?></span></div>
        <div class="kv"><span class="k">Designation</span><span class="v"><?= e($slip['designation'] ?: '—') ?></span></div>
        <div class="kv"><span class="k">Department</span><span class="v"><?= e($slip['department'] ?: '—') ?></span></div>
    </div>
    <div class="col">
        <div class="kv"><span class="k">Slip No.</span><span class="v"><?= e($slipNo) ?></span></div>
        <div class="kv"><span class="k">Pay period</span><span class="v"><?= e($period) ?></span></div>
        <div class="kv"><span class="k">Generated</span><span class="v"><?= e($generated) ?></span></div>
    </div>
</div>

<div class="sl-h">Attendance &mdash; <?= e($period) ?></div>
<div class="sl-att">
    <div><?= slipCalendarHtml($attDays, (int) $slip['month'], (int) $slip['year'], $weekStart) ?></div>
    <div><?= slipSummaryHtml((int) $slip['total_days'], $sumP, $sumA, $sumL) ?></div>
</div>

<div class="sl-h">Salary</div>
<table class="sl-t">
    <thead><tr><th>Description</th><th class="n">Amount</th></tr></thead>
    <tbody>
        <tr><td>Basic monthly salary</td><td class="n"><?= money($slip['basic_salary']) ?></td></tr>
        <tr><td>Per day rate <span class="sm">(basic &divide; <?= (int) $slip['total_days'] ?> days)</span></td><td class="n"><?= money($perDay) ?></td></tr>
        <tr><td>Present days paid</td><td class="n"><?= (int) $slip['present_days'] ?> day(s)</td></tr>
    </tbody>
    <tfoot><tr class="tot"><td>Net payable</td><td class="n"><?= money($net) ?></td></tr></tfoot>
</table>

<div class="sl-h">Payment</div>
<table class="sl-t">
    <tbody>
        <tr><td>Net payable</td><td class="n"><?= money($net) ?></td></tr>
        <tr><td>Total paid</td><td class="n"><?= money($totalPaid) ?></td></tr>
        <tr class="tot"><td>Balance due</td><td class="n"><?= money($due) ?></td></tr>
    </tbody>
</table>

<?php if ($hasPayment): ?>
<div class="sl-kv sl-pay">
    <div class="col">
        <div class="kv"><span class="k">Payment date</span><span class="v"><?= e(date('d M Y', strtotime($slip['payment_date']))) ?></span></div>
        <div class="kv"><span class="k">Method</span><span class="v"><?= e($slip['payment_method']) ?></span></div>
    </div>
    <div class="col">
        <div class="kv"><span class="k">Status</span><span class="v"><?= e($paymentStatus ?? 'Pending') ?></span></div>
    </div>
</div>
<?php endif; ?>

<div class="sl-stamp">
    <?php if ($noPayable): ?>No salary payable this month
    <?php elseif ($isPaid): ?>Paid in full
    <?php elseif ($hasPayment): ?>Partly paid &mdash; balance <?= money($due) ?>
    <?php else: ?>Not paid &mdash; balance <?= money($due) ?>
    <?php endif; ?>
</div>

<div class="sl-sign">
    <div>Employee signature</div>
    <div>Authorised by</div>
</div>

<div class="sl-foot">This is a computer-generated salary slip.</div>
<?php
$slipHtml = ob_get_clean();

/* ---------- Slip stylesheet (shared by the on-screen preview and the print iframe) ---------- */
$slipCss = <<<'CSS'
.sl { --ink:#111; --mute:#555; --line:#222; --hair:#bdbdbd; --tint:#eee;
      font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; color: var(--ink);
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sl, .sl * { box-sizing: border-box; }

/* Letterhead */
.sl-head { text-align: center; padding-bottom: 8px; border-bottom: 3px double var(--line); }
.sl-org  { font-size: 1.5em; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
.sl-addr { font-size: .85em; color: var(--mute); margin-top: 1px; }
.sl-title{ display: inline-block; margin-top: 8px; padding: 2px 16px; border: 1.5px solid var(--line);
           font-size: .95em; font-weight: 700; letter-spacing: .2em; }

/* Key / value blocks */
.sl-kv   { display: grid; grid-template-columns: 1fr 1fr; column-gap: 22px; margin-top: 10px; }
.sl-kv .kv { display: flex; justify-content: space-between; gap: 8px; padding: 3px 0; border-bottom: 1px solid var(--hair); }
.sl-kv .k  { color: var(--mute); }
.sl-kv .v  { font-weight: 700; text-align: right; }
.sl-pay    { margin-top: 6px; }

/* Section headings */
.sl-h { margin: 13px 0 6px; padding: 2px 0; font-size: .8em; font-weight: 700; letter-spacing: .1em;
        text-transform: uppercase; border-bottom: 1.5px solid var(--line); }

/* Attendance: calendar + summary side by side */
.sl-att { display: grid; grid-template-columns: auto 1fr; gap: 14px; align-items: start; break-inside: avoid; }

/* Compact calendar */
.sl-cal { width: 74mm; border-collapse: collapse; table-layout: fixed; }
.sl-cal th { padding: 1px 0; border: 1px solid var(--line); background: var(--tint); font-size: .7em;
             font-weight: 700; text-align: center; text-transform: uppercase; letter-spacing: .02em; }
.sl-cal td { height: 5.4mm; padding: 0 1px; border: 1px solid var(--hair); text-align: center;
             vertical-align: middle; font-size: .78em; white-space: nowrap; }
.sl-cal .d { color: var(--mute); margin-right: 3px; font-size: .92em; }
.sl-cal .s { font-weight: 700; }
.sl-cal td.sl-e { background: #fafafa; }
.sl-cal td.sl-w { background: repeating-linear-gradient(45deg, #e4e4e4 0, #e4e4e4 2px, #fff 2px, #fff 4px); }
.sl-cal td.sl-P { background: #fff; }
.sl-cal td.sl-A { background: #cfcfcf; }
.sl-cal td.sl-A .d, .sl-cal td.sl-A .s { color: #000; }
.sl-cal td.sl-L { background: #ececec; }
.sl-cal td.sl-L .s { font-style: italic; text-decoration: underline; }
.sl-leg { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 4px; font-size: .72em; color: var(--mute); }
.sl-leg b { display: inline-block; min-width: 1.1em; padding: 0 2px; border: 1px solid var(--hair); text-align: center; color: var(--ink); }
.sl-leg .sw { display: inline-block; width: 1.3em; height: .8em; margin-right: 3px; vertical-align: -1px; border: 1px solid var(--hair);
              background: repeating-linear-gradient(45deg, #e4e4e4 0, #e4e4e4 2px, #fff 2px, #fff 4px); }

/* Tables */
.sl-t { width: 100%; border-collapse: collapse; break-inside: avoid; }
.sl-t th, .sl-t td { padding: 3px 7px; border: 1px solid var(--line); }
.sl-t th { background: var(--tint); font-size: .78em; font-weight: 700; letter-spacing: .05em; text-align: left; text-transform: uppercase; }
.sl-t .n  { text-align: right; white-space: nowrap; }
.sl-t .sm { font-size: .82em; color: var(--mute); }
.sl-t tr.tot td, .sl-t tfoot td { background: var(--tint); font-weight: 700; border-top: 2px solid var(--line); }
.sl-sum td { padding-top: 2px; padding-bottom: 2px; }

/* Status, signatures, footer */
.sl-stamp { margin-top: 12px; padding: 5px 8px; border: 2px solid var(--line); text-align: center; font-weight: 700;
            font-size: .88em; letter-spacing: .1em; text-transform: uppercase; break-inside: avoid; }
.sl-sign  { display: flex; gap: 28px; margin-top: 36px; break-inside: avoid; }
.sl-sign div { flex: 1; padding-top: 3px; border-top: 1px solid var(--line); text-align: center; font-size: .8em; }
.sl-foot  { margin-top: 12px; text-align: center; font-size: .75em; color: var(--mute); }

/* POS (80mm) layout: everything stacked, calendar fills the width */
.sl.pos { font-size: 8.5pt; width: 100%; }
.sl.pos .sl-org { font-size: 1.3em; }
.sl.pos .sl-kv  { grid-template-columns: 1fr; margin-top: 6px; }
.sl.pos .sl-kv .col + .col { margin-top: 0; }
.sl.pos .sl-att { grid-template-columns: 1fr; gap: 8px; }
.sl.pos .sl-cal { width: 100%; }
.sl.pos .sl-cal td { height: 5mm; }
.sl.pos .sl-sign { gap: 14px; margin-top: 24px; }

/* On-screen preview only: tinted status colours */
.sl.scr .sl-cal td.sl-P { background: #e6f4ea; }
.sl.scr .sl-cal td.sl-A { background: #fbe4e2; }
.sl.scr .sl-cal td.sl-L { background: #fff1cf; }
CSS;

$page_title  = 'Salary Slip';
$active_menu = 'payroll_list';
require_once __DIR__ . '/navbar.php';
echo attendanceCalendarCss();
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

    .slip-paper { max-width: 780px; margin: 0 auto; padding: 26px 28px; background: #fff; border: 1px solid #d5d9d7; box-shadow: 0 2px 10px rgba(20, 40, 30, .06); }
    @media (max-width: 575.98px) { .slip-paper { padding: 18px 14px; } .sl .sl-att { grid-template-columns: 1fr; } .sl .sl-cal { width: 100%; } }

<?= $slipCss ?>
</style>

<div class="row g-3">
    <!-- LEFT: on-screen formal salary slip -->
    <div class="col-lg-7">
        <?= attendanceDiagnostic() ?>
        <div class="slip-paper">
            <div class="sl a4 scr"><?= $slipHtml ?></div>
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
                <button type="button" class="btn btn-outline-brand btn-sm" onclick="printReceipt('pos')"><i class="bi bi-printer me-1"></i>Print POS</button>
                <button type="button" class="btn btn-brand btn-sm" onclick="printReceipt('a4')"><i class="bi bi-file-earmark-text me-1"></i>Print A4</button>
                <a href="payroll_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Payroll</a>
            </div>
        </div>
    </div>
</div>

<script>
/* Prints only the slip via a hidden iframe, so the navbar/panels are left out and the
   @page margin can be set per layout: POS (80mm) uses POS_MARGIN, A4 uses A4_MARGIN. */
var SLIP_HTML   = <?= json_encode($slipHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
var SLIP_CSS    = <?= json_encode($slipCss, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
var SLIP_TITLE  = <?= json_encode(e('Salary Slip - ' . $slip['name'] . ' - ' . $period), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
var POS_MARGIN  = <?= json_encode($posMargin) ?>;   // default POS margin: 0.5cm
var A4_MARGIN   = <?= json_encode($a4Margin) ?>;

function printReceipt(mode) {
  var pos  = mode !== 'a4';
  var page = pos
    ? '@page{size:80mm auto;margin:' + POS_MARGIN + ';}'
    : '@page{size:A4 portrait;margin:' + A4_MARGIN + ';}';

  var doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + SLIP_TITLE + '</title>'
          + '<style>' + SLIP_CSS + page + 'html,body{margin:0;padding:0;background:#fff;}</style></head>'
          + '<body><div class="sl ' + (pos ? 'pos' : 'a4') + '">' + SLIP_HTML + '</div></body></html>';

  var f = document.createElement('iframe');
  f.setAttribute('aria-hidden', 'true');
  f.style.cssText = 'position:fixed;left:-9999px;top:0;width:' + (pos ? '320px' : '900px') + ';height:1200px;border:0;';
  document.body.appendChild(f);
  var w = f.contentWindow, d = w.document;
  d.open(); d.write(doc); d.close();
  var cleanup = function () { if (f.parentNode) f.parentNode.removeChild(f); };
  w.onafterprint = cleanup;
  setTimeout(function () { w.focus(); w.print(); }, 300);
  setTimeout(cleanup, 120000);   // safety net if afterprint never fires
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>