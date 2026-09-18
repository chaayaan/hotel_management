<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$booking_id = (int)($_GET['booking_id'] ?? 0);
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

$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($row = mysqli_fetch_assoc($res)) $settings[$row['setting_key']] = $row['setting_value'];
$resort_name = $settings['resort_name'] ?? 'Resort';
$resort_address = $settings['resort_address'] ?? '';
$resort_phone = $settings['resort_phone'] ?? '';
$resort_website = $settings['resort_website'] ?? '';
$wifi_name = $settings['wifi_name'] ?? '';
$wifi_password = $settings['wifi_password'] ?? '';

$service_total = get_booking_services_total($conn, $booking_id);
$room_charge_total = (float)$booking['room_charge_total'];
$extension_charge_total = (float)$booking['extension_charge_total'];
$discount = (float)$booking['discount'];
$tax = (float)$booking['tax'];
$grand_total = max(0, $room_charge_total + $extension_charge_total + $service_total - $discount + $tax);
$payments_made = get_booking_payments_total($conn, $booking_id);
$balance_due = max(0, $grand_total - $payments_made);
$is_paid = $balance_due <= 0.009;

$services_result = mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$services = [];
while ($s = mysqli_fetch_assoc($services_result)) $services[] = $s;

$payments_result = mysqli_query($conn, "SELECT * FROM hotel_payments WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$payments = [];
while ($p = mysqli_fetch_assoc($payments_result)) $payments[] = $p;

$extensions_result = mysqli_query($conn, "SELECT * FROM hotel_booking_extensions WHERE booking_id = {$booking_id} ORDER BY created_at ASC");
$extensions = [];
while ($ex = mysqli_fetch_assoc($extensions_result)) $extensions[] = $ex;

$wifi_qr_string = '';
if ($wifi_name !== '') {
    $wifi_qr_string = 'WIFI:T:WPA;S:' . $wifi_name . ';P:' . $wifi_password . ';;';
}

$is_active_booking = $booking['status'] === 'checked_in';

$page_title = 'Booking Receipt';
$active_menu = 'booking_list';
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

    /* ---------- On-screen POS-style receipt (mirrors the printed #printReceipt look) ---------- */
    .receipt-wrap { display: flex; justify-content: center; }
    .receipt-slip {
        width: 100%; max-width: 360px; background: #fff;
        border: 1px solid #e2e5e3; border-radius: 6px;
        box-shadow: 0 2px 10px rgba(20,40,30,0.06);
        padding: 20px 18px 16px;
        font-family: 'Courier New', Courier, monospace;
        color: #1e2b23;
    }
    .receipt-slip .r-header { text-align: center; border-bottom: 2px dashed #d7dbd8; padding-bottom: 12px; margin-bottom: 12px; }
    .receipt-slip .r-header .r-name { font-weight: 800; font-size: 1.05rem; color: #0f5132; letter-spacing: 0.02em; }
    .receipt-slip .r-header .r-sub { font-size: 0.72rem; color: #6c776f; margin-top: 2px; line-height: 1.4; }
    .receipt-slip .r-header .r-invoice { font-size: 0.7rem; color: #8a938e; margin-top: 6px; }

    .receipt-slip .r-status-chip {
        display: block; text-align: center; font-size: 0.68rem; font-weight: 700;
        letter-spacing: 0.04em; text-transform: uppercase; padding: 3px 0; margin-bottom: 10px;
        border-radius: 4px;
    }

    .receipt-slip .r-row { display: flex; justify-content: space-between; font-size: 0.78rem; padding: 2px 0; gap: 10px; }
    .receipt-slip .r-row .r-k { color: #6c776f; }
    .receipt-slip .r-row .r-v { font-weight: 600; text-align: right; }

    .receipt-slip .r-divider { border: none; border-top: 1px dashed #d7dbd8; margin: 10px 0; }
    .receipt-slip .r-section-title {
        font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;
        color: #6c776f; font-weight: 700; margin: 10px 0 4px;
    }

    .receipt-slip .r-item {
        display: flex; justify-content: space-between; align-items: baseline;
        font-size: 0.78rem; padding: 3px 0; gap: 8px;
    }
    .receipt-slip .r-item .r-item-name { flex: 1 1 auto; }
    .receipt-slip .r-item .r-item-sub { display: block; font-size: 0.68rem; color: #9aa39d; }
    .receipt-slip .r-item .r-item-amt { flex: 0 0 auto; font-weight: 600; white-space: nowrap; }

    .receipt-slip .r-totals { border-top: 2px dashed #d7dbd8; margin-top: 12px; padding-top: 10px; }
    .receipt-slip .r-totals .r-row { font-size: 0.8rem; }
    .receipt-slip .r-totals .r-grand {
        display: flex; justify-content: space-between; font-size: 1.05rem; font-weight: 800;
        color: #0f5132; border-top: 1px solid #d7dbd8; margin-top: 6px; padding-top: 8px;
    }
    .receipt-slip .r-totals .r-paid-row { font-size: 0.78rem; color: #6c776f; padding: 2px 0; display: flex; justify-content: space-between; }
    .receipt-slip .r-totals .r-due-row {
        display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 700;
        margin-top: 4px; padding-top: 4px; border-top: 1px dashed #d7dbd8;
    }

    .receipt-slip .r-footer { text-align: center; font-size: 0.7rem; color: #9aa39d; margin-top: 14px; border-top: 2px dashed #d7dbd8; padding-top: 10px; }
    .receipt-slip .r-footer .r-thanks { font-weight: 700; color: #1c3d2e; font-size: 0.78rem; margin-bottom: 2px; }
    .screen-wifi { display:flex; flex-direction:column; align-items:center; gap:5px; text-align:center; margin-top:14px; padding-top:12px; border-top:2px dashed #d7dbd8; font-size:0.68rem; color:#6c776f; }
    .screen-wifi img { display:block; width:110px; height:110px; image-rendering:auto; }
    .screen-wifi .wifi-name { color:#9aa39d; }

    #printReceipt { display: none; font-family: Arial, Helvetica, sans-serif; }

    @media print {
        body * { visibility: hidden; }
        #printReceipt, #printReceipt * { visibility: visible; }
        #printReceipt { display: block !important; position: absolute; top: 0; left: 0; width: 100%; }

        .print-pos #printReceipt { max-width: 80mm; margin: 0 auto; }
        .print-a4 #printReceipt { max-width: 100%; padding: 10mm; }
    }

    /* Minimal page margin for POS/thermal printing (default browser margin is much larger) */
    @page { margin: 0.2cm; }

    .print-header { text-align: center; border-bottom: 2px dashed #dcdfdd; padding-bottom: 12px; margin-bottom: 14px; }
    .print-header h4 { margin: 0; font-weight: 800; color: #0f5132; }
    .print-header .muted { color: #666; font-size: 0.82rem; }
    .print-info-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-section-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #666; font-weight: 700; margin: 14px 0 6px; }
    table.print-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; }
    table.print-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 5px 3px; font-size: 0.72rem; text-transform: uppercase; }
    table.print-table td { padding: 5px 3px; border-bottom: 1px dashed #eee; }
    .print-totals { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .print-totals .line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-totals .grand { font-size: 1.2rem; font-weight: 800; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 8px; }
    .print-wifi { display: flex; flex-direction: column; align-items: center; gap: 8px; justify-content: center; text-align: center; margin-top: 18px; padding-top: 14px; border-top: 2px dashed #dcdfdd; font-size: 0.8rem; }
</style>

<div class="row g-3">
    <!-- LEFT: on-screen POS-style receipt slip -->
    <div class="col-lg-7">
        <div class="receipt-wrap">
            <div class="receipt-slip">
                <div class="r-header">
                    <div class="r-name"><?= e($resort_name) ?></div>
                    <?php if ($resort_address): ?><div class="r-sub"><?= e($resort_address) ?></div><?php endif; ?>
                    <?php if ($resort_phone || $resort_website): ?>
                    <div class="r-sub"><?= e($resort_phone) ?><?= ($resort_phone && $resort_website) ? ' · ' : '' ?><?= e($resort_website) ?></div>
                    <?php endif; ?>
                    <div class="r-invoice">Invoice: <?= e($booking['reservation_no']) ?><br><?= e(date('d M Y, h:i A')) ?></div>
                </div>

                <span class="r-status-chip" style="background: <?= booking_status_badge($booking['status']) ? '' : '' ?>#eef2f0; color:#1c3d2e;">
                    <?= e(ucwords(str_replace('_',' ',$booking['status']))) ?>
                </span>

                <div class="r-row"><span class="r-k">Guest</span><span class="r-v"><?= e($booking['guest_name']) ?></span></div>
                <div class="r-row"><span class="r-k">Phone</span><span class="r-v"><?= e($booking['guest_phone']) ?></span></div>
                <?php if (!empty($booking['guest_email'])): ?>
                <div class="r-row"><span class="r-k">Email</span><span class="r-v"><?= e($booking['guest_email']) ?></span></div>
                <?php endif; ?>

                <hr class="r-divider">

                <div class="r-row"><span class="r-k">Room</span><span class="r-v"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
                <div class="r-row"><span class="r-k">From</span><span class="r-v"><?= e(date('d M Y', strtotime($booking['reserved_from']))) ?></span></div>
                <div class="r-row"><span class="r-k">Until</span><span class="r-v"><?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
                <div class="r-row"><span class="r-k">Nights</span><span class="r-v"><?= (int)$booking['reserved_nights'] ?></span></div>
                <?php if ($booking['checkin_at']): ?>
                <div class="r-row"><span class="r-k">Check-In</span><span class="r-v"><?= e(date('d M Y, h:i A', strtotime($booking['checkin_at']))) ?></span></div>
                <?php endif; ?>
                <?php if ($booking['checkout_at']): ?>
                <div class="r-row"><span class="r-k">Checkout</span><span class="r-v"><?= e(date('d M Y, h:i A', strtotime($booking['checkout_at']))) ?></span></div>
                <?php endif; ?>

                <div class="r-row r-item"><span class="r-item-name">Room Charge</span><span class="r-item-amt">৳<?= number_format($room_charge_total, 2) ?></span></div>

                <?php if (!empty($extensions)): ?>
                <div class="r-section-title">Extended Stay</div>
                <?php foreach ($extensions as $ex): ?>
                    <div class="r-item">
                        <span class="r-item-name">Extension to <?= e(date('d M Y', strtotime($ex['new_reserved_until']))) ?>
                            <span class="r-item-sub"><?= (int)$ex['added_nights'] ?> night(s)</span>
                        </span>
                        <span class="r-item-amt">৳<?= number_format((float)$ex['total_extended_charge'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($services)): ?>
                <div class="r-section-title">Services</div>
                <?php foreach ($services as $s): ?>
                    <div class="r-item">
                        <span class="r-item-name">
                            <?= e(ucfirst($s['service_type'])) ?>
                            <?php if ((float)$s['discount'] > 0): ?>
                            <span class="r-item-sub">৳<?= number_format((float)$s['amount'], 2) ?> − ৳<?= number_format((float)$s['discount'], 2) ?> disc.</span>
                            <?php endif; ?>
                        </span>
                        <span class="r-item-amt">৳<?= number_format((float)$s['amount'] - (float)$s['discount'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <div class="r-totals">
                    <?php if ($extension_charge_total > 0): ?>
                    <div class="r-row"><span class="r-k">Extension Total</span><span class="r-v">৳<?= number_format($extension_charge_total, 2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($service_total > 0): ?>
                    <div class="r-row"><span class="r-k">Service Total</span><span class="r-v">৳<?= number_format($service_total, 2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($discount > 0): ?>
                    <div class="r-row"><span class="r-k">Discount</span><span class="r-v">− ৳<?= number_format($discount, 2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($tax > 0): ?>
                    <div class="r-row"><span class="r-k">Tax</span><span class="r-v">৳<?= number_format($tax, 2) ?></span></div>
                    <?php endif; ?>
                    <div class="r-grand"><span>Grand Total</span><span>৳<?= number_format($grand_total, 2) ?></span></div>
                    <div class="r-paid-row"><span>Total Paid</span><span>৳<?= number_format($payments_made, 2) ?></span></div>
                    <div class="r-due-row" style="color: <?= $balance_due > 0 ? '#b3261e' : '#0f5132' ?>;">
                        <span>Balance Due</span><span>৳<?= number_format($balance_due, 2) ?></span>
                    </div>
                </div>

                <?php if (!empty($payments)): ?>
                <div class="r-section-title">Payment History</div>
                <?php foreach ($payments as $p): ?>
                    <div class="r-item">
                        <span class="r-item-name">
                            <?= e(ucfirst($p['payment_type'])) ?> · <?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?>
                            <span class="r-item-sub"><?= e(date('d M, h:i A', strtotime($p['created_at']))) ?></span>
                        </span>
                        <span class="r-item-amt">৳<?= number_format((float)$p['amount'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($wifi_qr_string): ?>
                <div class="screen-wifi">
                    <img id="screenWifiQr" alt="Wi-Fi QR Code" width="110" height="110">
                    <div>Scan to connect to Wi-Fi</div>
                    <?php if ($wifi_name): ?><div class="wifi-name">Wi-Fi: <?= e($wifi_name) ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="r-footer">
                    <div class="r-thanks">Thank you for staying with us!</div>
                    <?php if ($wifi_name): ?>
                    <div>Wi-Fi: <?= e($wifi_name) ?></div>
                    <?php endif; ?>
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
                    <span>Room Total</span><span>৳<?= number_format($room_charge_total, 2) ?></span>
                </div>
                <?php if ($extension_charge_total > 0): ?>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Extension Total</span><span>৳<?= number_format($extension_charge_total, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Service Total</span><span>৳<?= number_format($service_total, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Discount (−)</span><span>৳<?= number_format($discount, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Tax (+)</span><span>৳<?= number_format($tax, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="fw-semibold">Grand Total</span>
                    <span class="grand">৳<?= number_format($grand_total, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span>Total Paid</span><span>৳<?= number_format($payments_made, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small fw-semibold mt-1">
                    <span>Balance Due</span><span class="<?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format($balance_due, 2) ?></span>
                </div>
            </div>

            <?php if (!$is_paid): ?>
                <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Balance Due: ৳<?= number_format($balance_due, 2) ?> — checkout requires full payment.</div>
            <?php else: ?>
                <div class="paid-note"><i class="bi bi-check-circle me-1"></i>Fully Paid</div>
            <?php endif; ?>

            <div class="section-label">Actions</div>
            <div class="d-grid gap-2">
                <?php if ($is_active_booking): ?>
                    <a href="hotel_reservations_checkout.php?booking_id=<?= $booking_id ?>" class="btn btn-danger btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Checkout</a>
                    <a href="hotel_extend_stay.php?booking_id=<?= $booking_id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Extend Stay</a>
                    <a href="hotel_services.php?booking_id=<?= $booking_id ?>" class="btn btn-outline-brand btn-sm"><i class="bi bi-cup-hot me-1"></i>Service</a>
                <?php endif; ?>
                <button class="btn btn-brand btn-sm" onclick="printReceipt('pos')"><i class="bi bi-printer me-1"></i>Print POS</button>
                <button class="btn btn-outline-brand btn-sm" onclick="printReceipt('a4')"><i class="bi bi-file-earmark-text me-1"></i>Print A4</button>
            </div>
        </div>
    </div>
</div>

<div id="printReceipt">
    <div class="print-header">
        <h4><?= e($resort_name) ?></h4>
        <div class="muted"><?= e($resort_address) ?></div>
        <div class="muted"><?= e($resort_phone) ?><?= $resort_website ? ' · ' . e($resort_website) : '' ?></div>
        <div class="muted mt-1">Invoice: <?= e($booking['reservation_no']) ?> · <?= e(date('d M Y, h:i A')) ?></div>
    </div>

    <div class="print-section-title">Guest & Room</div>
    <div class="print-info-row"><span>Guest</span><span><?= e($booking['guest_name']) ?></span></div>
    <div class="print-info-row"><span>Phone</span><span><?= e($booking['guest_phone']) ?></span></div>
    <div class="print-info-row"><span>Room</span><span><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
    <div class="print-info-row"><span>Status</span><span><?= e(ucwords(str_replace('_',' ',$booking['status']))) ?></span></div>
    <div class="print-info-row"><span>Reserved From</span><span><?= e(date('d M Y', strtotime($booking['reserved_from']))) ?></span></div>
    <div class="print-info-row"><span>Reserved Until</span><span><?= e(date('d M Y', strtotime($booking['reserved_until']))) ?></span></div>
    <?php if ($booking['checkin_at']): ?>
    <div class="print-info-row"><span>Check-In</span><span><?= e(date('d M Y, h:i A', strtotime($booking['checkin_at']))) ?></span></div>
    <?php endif; ?>
    <?php if ($booking['checkout_at']): ?>
    <div class="print-info-row"><span>Checkout</span><span><?= e(date('d M Y, h:i A', strtotime($booking['checkout_at']))) ?></span></div>
    <?php endif; ?>

    <?php if (!empty($extensions)): ?>
    <div class="print-section-title">Extended Stay</div>
    <table class="print-table">
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

    <?php if (!empty($services)): ?>
    <div class="print-section-title">Services</div>
    <table class="print-table">
        <thead><tr><th>Type</th><th class="text-end">Amount</th><th class="text-end">Disc.</th><th class="text-end">Net</th></tr></thead>
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
    <div class="print-section-title">Payment History</div>
    <table class="print-table">
        <thead><tr><th>Date &amp; Time</th><th>Payment Type</th><th>Payment Method</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= e(date('d M, h:i A', strtotime($p['created_at']))) ?></td>
                <td><?= e(ucfirst(str_replace('_',' ',$p['payment_type']))) ?></td>
                <td><?= e(ucfirst(str_replace('_',' ',$p['payment_method']))) ?></td>
                <td class="text-end"><strong>৳<?= number_format((float)$p['amount'], 2) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="print-totals">
        <div class="line"><span>Room Charge</span><span>৳<?= number_format($room_charge_total, 2) ?></span></div>
        <?php if ($extension_charge_total > 0): ?>
        <div class="line"><span>Extension Charge</span><span>৳<?= number_format($extension_charge_total, 2) ?></span></div>
        <?php endif; ?>
        <?php if ($service_total > 0): ?>
        <div class="line"><span>Service Charge</span><span>৳<?= number_format($service_total, 2) ?></span></div>
        <?php endif; ?>
        <?php if ($discount > 0): ?>
        <div class="line"><span>Discount</span><span>− ৳<?= number_format($discount, 2) ?></span></div>
        <?php endif; ?>
        <?php if ($tax > 0): ?>
        <div class="line"><span>Tax</span><span>৳<?= number_format($tax, 2) ?></span></div>
        <?php endif; ?>
        <div class="line grand"><span>Grand Total</span><span>৳<?= number_format($grand_total, 2) ?></span></div>
        <div class="line"><span>Total Paid</span><span>৳<?= number_format($payments_made, 2) ?></span></div>
        <div class="line"><span>Balance Due</span><span>৳<?= number_format($balance_due, 2) ?></span></div>
    </div>

    <?php if ($wifi_qr_string): ?>
    <div class="print-wifi">
        <img id="printWifiQr" alt="Wi-Fi QR Code" width="110" height="110">
        <div class="text-muted">Scan to connect to network</div>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted mt-3" style="font-size:0.75rem;">Thank you for staying with us!</div>
</div>

<script>
<?php if ($wifi_qr_string): ?>
(function() {
    var qrData = <?= json_encode($wifi_qr_string) ?>;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' + encodeURIComponent(qrData);
    var printQr = document.getElementById('printWifiQr');
    var screenQr = document.getElementById('screenWifiQr');
    if (printQr) printQr.src = qrUrl;
    if (screenQr) screenQr.src = qrUrl;
})();
<?php endif; ?>

function printReceipt(mode) {
    document.body.classList.remove('print-pos', 'print-a4');
    document.body.classList.add(mode === 'a4' ? 'print-a4' : 'print-pos');
    window.print();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>