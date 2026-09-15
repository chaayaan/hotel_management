<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$booking_id = (int)($_GET['booking_id'] ?? 0);
$receipt_type = $_GET['type'] ?? 'checkout'; // 'checkin' or 'checkout'

if ($booking_id <= 0) {
    flash_set('danger', 'No booking selected for receipt.');
    header('Location: hotel_front_desk.php');
    exit;
}

$booking = get_booking_full($conn, $booking_id);
if (!$booking) {
    flash_set('danger', 'Booking not found.');
    header('Location: hotel_front_desk.php');
    exit;
}

// ---------- Load resort settings ----------
$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($row = mysqli_fetch_assoc($res)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$resort_name = $settings['resort_name'] ?? 'Resort';
$resort_address = $settings['resort_address'] ?? '';
$resort_phone = $settings['resort_phone'] ?? '';
$resort_website = $settings['resort_website'] ?? '';
$wifi_name = $settings['wifi_name'] ?? '';
$wifi_password = $settings['wifi_password'] ?? '';

// ---------- Financial data ----------
$service_total = get_booking_services_total($conn, $booking_id);
$room_charge_total = (float)$booking['room_charge_total'];
$extension_charge_total = (float)$booking['extension_charge_total'];
$discount = (float)$booking['discount'];
$tax = (float)$booking['tax'];
$subtotal = $room_charge_total + $extension_charge_total + $service_total;
$grand_total = max(0, $subtotal - $discount + $tax);
$payments_made = get_booking_payments_total($conn, $booking_id);
$due = max(0, $grand_total - $payments_made);

$services_result = mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$services = [];
while ($s = mysqli_fetch_assoc($services_result)) $services[] = $s;

$payments_result = mysqli_query($conn, "SELECT * FROM hotel_payments WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$payments = [];
while ($p = mysqli_fetch_assoc($payments_result)) $payments[] = $p;

$extensions_result = mysqli_query($conn, "SELECT * FROM hotel_booking_extensions WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$extensions = [];
while ($ex = mysqli_fetch_assoc($extensions_result)) $extensions[] = $ex;

// Wi-Fi QR payload (standard WIFI: format)
$wifi_qr_string = '';
if ($wifi_name !== '') {
    $wifi_qr_string = 'WIFI:T:WPA;S:' . $wifi_name . ';P:' . $wifi_password . ';;';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt - <?= e($booking['reservation_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<style>
    :root { --brand-primary: #0f5132; }
    body { background: #f4f6f5; font-family: 'Segoe UI', system-ui, sans-serif; }
    .toolbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; }
    .toolbar h6 { margin: 0; font-weight: 700; color: #1c3d2e; }

    .receipt-wrap { max-width: 850px; margin: 24px auto; padding: 0 16px; }

    .receipt-box { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); padding: 28px; }
    .receipt-header { text-align: center; border-bottom: 2px dashed #dcdfdd; padding-bottom: 14px; margin-bottom: 16px; }
    .receipt-header h4 { margin: 0; font-weight: 800; color: var(--brand-primary); }
    .receipt-header .muted { color: #8a938e; font-size: 0.82rem; }
    .section-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #8a938e; font-weight: 700; margin: 16px 0 6px; }
    .info-row { display: flex; justify-content: space-between; font-size: 0.86rem; padding: 3px 0; }
    .info-row .label { color: #8a938e; }
    .info-row .value { font-weight: 600; color: #1c3d2e; }
    table.receipt-table { width: 100%; font-size: 0.85rem; border-collapse: collapse; }
    table.receipt-table th { text-align: left; border-bottom: 1px solid #dcdfdd; padding: 6px 4px; color: #8a938e; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; }
    table.receipt-table td { padding: 6px 4px; border-bottom: 1px dashed #eef0ef; }
    .totals-box { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .totals-box .line { display: flex; justify-content: space-between; font-size: 0.88rem; padding: 3px 0; }
    .totals-box .grand { font-size: 1.3rem; font-weight: 800; color: var(--brand-primary); border-top: 1px solid #dcdfdd; margin-top: 6px; padding-top: 8px; }
    .due-note { background: #fff3cd; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .paid-note { background: #d1f5e0; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .wifi-box { display: flex; align-items: center; gap: 14px; justify-content: center; margin-top: 20px; padding-top: 16px; border-top: 2px dashed #dcdfdd; }
    .wifi-box canvas { border: 6px solid #fff; box-shadow: 0 0 0 1px #eee; }
    .wifi-info { font-size: 0.8rem; }
    .wifi-info .label { color: #8a938e; }
    .wifi-info .value { font-weight: 700; color: #1c3d2e; }
    .badge-receipt-type { font-size: 0.68rem; padding: 4px 10px; border-radius: 20px; font-weight: 700; text-transform: uppercase; }

    .receipt-box.pos-mode { max-width: 340px; margin: 0 auto; font-family: 'Courier New', monospace; }
    .receipt-box.pos-mode .receipt-header h4 { font-size: 1.1rem; }
    .receipt-box.pos-mode table.receipt-table th,
    .receipt-box.pos-mode table.receipt-table td { font-size: 0.78rem; }

    @media print {
        .toolbar, .no-print { display: none !important; }
        body { background: #fff; }
        .receipt-wrap { margin: 0; padding: 0; max-width: 100%; }
        .receipt-box { box-shadow: none; border-radius: 0; }
        .receipt-box.a4-mode { padding: 10mm; }
        .receipt-box.pos-mode { max-width: 80mm; padding: 4mm; }
    }
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="d-flex align-items-center gap-2">
        <a href="hotel_front_desk.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <h6>Receipt — <?= e($booking['reservation_no']) ?></h6>
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-brand active" id="btnPosView" onclick="setMode('pos')">POS</button>
            <button class="btn btn-outline-brand" id="btnA4View" onclick="setMode('a4')">A4</button>
        </div>
        <button class="btn btn-brand btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
</div>

<div class="receipt-wrap">
    <div class="receipt-box pos-mode" id="receiptBox">
        <div class="receipt-header">
            <h4><?= e($resort_name) ?></h4>
            <div class="muted"><?= e($resort_address) ?></div>
            <div class="muted"><?= e($resort_phone) ?><?= $resort_website ? ' · ' . e($resort_website) : '' ?></div>
            <div class="mt-2">
                <span class="badge-receipt-type <?= $receipt_type === 'checkin' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success' ?>">
                    <?= $receipt_type === 'checkin' ? 'Check-In Receipt' : 'Checkout / Final Receipt' ?>
                </span>
            </div>
            <div class="muted mt-1"><?= e(date('d M Y, h:i A')) ?></div>
        </div>

        <div class="section-title">Guest Information</div>
        <div class="info-row"><span class="label">Name</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
        <div class="info-row"><span class="label">Phone</span><span class="value"><?= e($booking['guest_phone']) ?></span></div>
        <?php if (!empty($booking['guest_email'])): ?>
        <div class="info-row"><span class="label">Email</span><span class="value"><?= e($booking['guest_email']) ?></span></div>
        <?php endif; ?>

        <div class="section-title">Room & Reservation</div>
        <div class="info-row"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>
        <div class="info-row"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
        <div class="info-row"><span class="label">Reserved</span><span class="value"><?= e(date('d M', strtotime($booking['reserved_from']))) ?> → <?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
        <div class="info-row"><span class="label">Nights</span><span class="value"><?= (int)$booking['reserved_nights'] ?></span></div>
        <div class="info-row"><span class="label">Adults / Children</span><span class="value"><?= (int)$booking['adults'] ?> / <?= (int)$booking['children'] ?></span></div>

        <?php if ($booking['checkin_at']): ?>
        <div class="section-title">Check-In</div>
        <div class="info-row"><span class="label">Checked In</span><span class="value"><?= e(date('d M Y, h:i A', strtotime($booking['checkin_at']))) ?></span></div>
        <?php endif; ?>

        <?php if ($receipt_type === 'checkout' && $booking['checkout_at']): ?>
        <div class="section-title">Checkout</div>
        <div class="info-row"><span class="label">Checked Out</span><span class="value"><?= e(date('d M Y, h:i A', strtotime($booking['checkout_at']))) ?></span></div>
        <?php endif; ?>

        <?php if ($receipt_type === 'checkout' && !empty($extensions)): ?>
        <div class="section-title">Stay Extensions</div>
        <table class="receipt-table">
            <thead><tr><th>New Until</th><th>Nights</th><th class="text-end">Charge</th></tr></thead>
            <tbody>
            <?php foreach ($extensions as $ex): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime($ex['new_reserved_until']))) ?></td>
                    <td><?= (int)$ex['added_nights'] ?></td>
                    <td class="text-end">৳<?= number_format((float)$ex['total_extended_charge'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($receipt_type === 'checkout' && !empty($services)): ?>
        <div class="section-title">Services</div>
        <table class="receipt-table">
            <thead><tr><th>Type</th><th class="text-end">Amount</th><th class="text-end">Discount</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            <?php foreach ($services as $s): ?>
                <tr>
                    <td><?= e(ucfirst($s['service_type'])) ?></td>
                    <td class="text-end">৳<?= number_format((float)$s['amount'], 2) ?></td>
                    <td class="text-end">৳<?= number_format((float)$s['discount'], 2) ?></td>
                    <td class="text-end">৳<?= number_format((float)$s['amount'] - (float)$s['discount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($payments)): ?>
        <div class="section-title">Payment History</div>
        <table class="receipt-table">
            <thead><tr><th>Type</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e(ucfirst($p['payment_type'])) ?></td>
                    <td><?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></td>
                    <td class="text-end">৳<?= number_format((float)$p['amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="totals-box">
            <div class="line"><span>Room Charge</span><span>৳<?= number_format($room_charge_total, 2) ?></span></div>
            <?php if ($extension_charge_total > 0): ?>
            <div class="line"><span>Extension Charge</span><span>৳<?= number_format($extension_charge_total, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($receipt_type === 'checkout' && $service_total > 0): ?>
            <div class="line"><span>Service Charge</span><span>৳<?= number_format($service_total, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($tax > 0): ?>
            <div class="line"><span>Tax</span><span>৳<?= number_format($tax, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($discount > 0): ?>
            <div class="line"><span>Discount</span><span>− ৳<?= number_format($discount, 2) ?></span></div>
            <?php endif; ?>
            <div class="line grand"><span>Total Amount</span><span>৳<?= number_format($grand_total, 2) ?></span></div>
            <div class="line"><span>Paid</span><span>৳<?= number_format($payments_made, 2) ?></span></div>
        </div>

        <?php if ($due > 0.009): ?>
            <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Due: ৳<?= number_format($due, 2) ?> — to be settled at checkout.</div>
        <?php else: ?>
            <div class="paid-note"><i class="bi bi-check-circle me-1"></i>Fully Paid — Thank you!</div>
        <?php endif; ?>

        <?php if ($wifi_qr_string): ?>
        <div class="wifi-box">
            <canvas id="wifiQr"></canvas>
            <div class="wifi-info">
                <div><span class="label">Wi-Fi:</span> <span class="value"><?= e($wifi_name) ?></span></div>
                <div><span class="label">Password:</span> <span class="value"><?= e($wifi_password) ?></span></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="text-center text-muted mt-3" style="font-size:0.75rem;">Thank you for staying with us!</div>
    </div>
</div>

<script>
function setMode(mode) {
    const box = document.getElementById('receiptBox');
    box.classList.remove('pos-mode', 'a4-mode');
    box.classList.add(mode === 'a4' ? 'a4-mode' : 'pos-mode');
    document.getElementById('btnPosView').classList.toggle('active', mode === 'pos');
    document.getElementById('btnA4View').classList.toggle('active', mode === 'a4');
}

<?php if ($wifi_qr_string): ?>
if (window.QRCode) {
    QRCode.toCanvas(document.getElementById('wifiQr'), <?= json_encode($wifi_qr_string) ?>, { width: 110, margin: 1 });
}
<?php endif; ?>
</script>

</body>
</html>