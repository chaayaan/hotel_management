<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$service_id = (int)($_GET['service_id'] ?? 0);
if ($service_id <= 0) {
    flash_set('danger', 'No service selected for receipt.');
    header('Location: hotel_front_desk.php');
    exit;
}

$service = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE id = {$service_id} LIMIT 1"));
if (!$service) {
    flash_set('danger', 'Service record not found.');
    header('Location: hotel_front_desk.php');
    exit;
}

$booking = get_booking_full($conn, $service['booking_id']);

$items = [];
$res = mysqli_query($conn, "SELECT * FROM hotel_booking_service_items WHERE booking_service_id = {$service_id} ORDER BY id ASC");
while ($row = mysqli_fetch_assoc($res)) $items[] = $row;

// Payments tied to this service (by reference note convention)
$service_ref = 'Service #' . $service_id;
$refEsc = mysqli_real_escape_string($conn, $service_ref);
$paid_for_service = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) t FROM hotel_payments WHERE booking_id = {$service['booking_id']} AND reference_note = '{$refEsc}'"))['t'];
$paid_for_service = (float)$paid_for_service;

$net_amount = (float)$service['amount'] - (float)$service['discount'];
$due = max(0, $net_amount - $paid_for_service);

// Settings
$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($row = mysqli_fetch_assoc($res)) $settings[$row['setting_key']] = $row['setting_value'];
$resort_name = $settings['resort_name'] ?? 'Resort';
$resort_address = $settings['resort_address'] ?? '';
$resort_phone = $settings['resort_phone'] ?? '';
$resort_website = $settings['resort_website'] ?? '';

$page_title = 'Service Receipt';
$active_menu = 'front_desk';
require __DIR__ . '/navbar.php';
?>

<style>
    .info-line { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eef0ef; font-size: 0.85rem; }
    .info-line .label { color: #8a938e; }
    .info-line .value { font-weight: 600; color: #1c3d2e; }
    .section-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8a938e; font-weight: 700; margin: 14px 0 6px; }
    .mini-table { font-size: 0.8rem; }
    .total-box { background: #f4f6f5; border-radius: 10px; padding: 14px 16px; margin-top: 10px; }
    .total-box .grand { font-size: 1.4rem; font-weight: 700; color: #0f5132; }
    .due-note { background: #fff3cd; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }
    .paid-note { background: #d1f5e0; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; text-align: center; font-weight: 600; }

    /* Print-only receipt (hidden on screen), Arial font */
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
    table.print-table { width: 100%; font-size: 0.82rem; border-collapse: collapse; margin-top: 10px; }
    table.print-table th { text-align: left; border-bottom: 1px solid #ccc; padding: 5px 3px; font-size: 0.72rem; text-transform: uppercase; }
    table.print-table td { padding: 5px 3px; border-bottom: 1px dashed #eee; }
    .print-totals { margin-top: 12px; border-top: 2px dashed #dcdfdd; padding-top: 10px; }
    .print-totals .line { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 3px 0; }
    .print-totals .grand { font-size: 1.2rem; font-weight: 800; color: #0f5132; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 8px; }
    .print-due-note { background: #fff3cd; color: #664d03; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }
    .print-paid-note { background: #d1f5e0; color: #0f5132; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; margin-top: 10px; text-align: center; font-weight: 700; }
</style>

<div class="row g-3">
    <!-- LEFT: Service details -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-cup-hot me-1"></i> Service Details
            </div>
            <div class="card-body">

            <div class="section-label">Guest & Room</div>
            <div class="info-line"><span class="label">Guest</span><span class="value"><?= e($booking['guest_name']) ?></span></div>
            <div class="info-line"><span class="label">Room</span><span class="value"><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
            <div class="info-line"><span class="label">Reservation No</span><span class="value"><?= e($booking['reservation_no']) ?></span></div>

            <div class="section-label">Service Information</div>
            <div class="info-line"><span class="label">Service Type</span><span class="value"><?= e(ucfirst($service['service_type'])) ?></span></div>
            <div class="info-line"><span class="label">Date</span><span class="value"><?= e(date('d M Y, h:i A', strtotime($service['created_at']))) ?></span></div>
            <?php if (!empty($service['notes'])): ?>
            <div class="info-line"><span class="label">Notes</span><span class="value"><?= e($service['notes']) ?></span></div>
            <?php endif; ?>

            <div class="section-label">Items</div>
            <table class="table table-sm mini-table mb-0">
                <thead><tr><th>Item</th><th>Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['item_name']) ?></td>
                        <td><?= (int)$it['quantity'] ?></td>
                        <td class="text-end">৳<?= number_format((float)$it['price'], 2) ?></td>
                        <td class="text-end">৳<?= number_format((float)$it['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Financial summary + actions -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-cash-stack me-1"></i> Financial Summary
            </div>
            <div class="card-body">

            <div class="total-box">
                <div class="d-flex justify-content-between small text-muted">
                    <span>Subtotal</span><span>৳<?= number_format((float)$service['amount'], 2) ?></span>
                </div>
                <?php if ((float)$service['discount'] > 0): ?>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Discount (−)</span><span>৳<?= number_format((float)$service['discount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between mt-2">
                    <span class="fw-semibold">Net Amount</span>
                    <span class="grand">৳<?= number_format($net_amount, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span>Paid</span><span>৳<?= number_format($paid_for_service, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between small fw-semibold mt-1">
                    <span>Due</span><span class="<?= $due > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format($due, 2) ?></span>
                </div>
            </div>

            <?php if ($due > 0.009): ?>
                <div class="due-note"><i class="bi bi-exclamation-triangle me-1"></i>Due: ৳<?= number_format($due, 2) ?> — to be settled at checkout.</div>
            <?php else: ?>
                <div class="paid-note"><i class="bi bi-check-circle me-1"></i>Fully Paid</div>
            <?php endif; ?>

            <div class="section-label">Actions</div>
            <div class="d-grid gap-2">
                <a href="hotel_services.php?booking_id=<?= (int)$service['booking_id'] ?>" class="btn btn-outline-brand btn-sm"><i class="bi bi-cup-hot me-1"></i>Add Another Service</a>
                <button class="btn btn-brand btn-sm" onclick="printReceipt('pos')"><i class="bi bi-printer me-1"></i>Print POS</button>
                <button class="btn btn-outline-brand btn-sm" onclick="printReceipt('a4')"><i class="bi bi-file-earmark-text me-1"></i>Print A4</button>
            </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden print-only receipt content (Arial) -->
<div id="printReceipt">
    <div class="print-header">
        <h4><?= e($resort_name) ?></h4>
        <div class="muted"><?= e($resort_address) ?></div>
        <div class="muted"><?= e($resort_phone) ?><?= $resort_website ? ' · ' . e($resort_website) : '' ?></div>
        <div class="muted mt-1">Service Receipt · <?= e(date('d M Y, h:i A', strtotime($service['created_at']))) ?></div>
    </div>

    <div class="print-info-row"><span>Guest</span><span><?= e($booking['guest_name']) ?></span></div>
    <div class="print-info-row"><span>Room</span><span><?= e($booking['room_number']) ?> (<?= e($booking['room_type_name']) ?>)</span></div>
    <div class="print-info-row"><span>Reservation No</span><span><?= e($booking['reservation_no']) ?></span></div>
    <div class="print-info-row"><span>Service Type</span><span><?= e(ucfirst($service['service_type'])) ?></span></div>
    <?php if (!empty($service['notes'])): ?>
    <div class="print-info-row"><span>Notes</span><span><?= e($service['notes']) ?></span></div>
    <?php endif; ?>

    <table class="print-table">
        <thead><tr><th>Item</th><th>Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['item_name']) ?></td>
                <td><?= (int)$it['quantity'] ?></td>
                <td class="text-end">৳<?= number_format((float)$it['price'], 2) ?></td>
                <td class="text-end">৳<?= number_format((float)$it['total_price'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-totals">
        <div class="line"><span>Subtotal</span><span>৳<?= number_format((float)$service['amount'], 2) ?></span></div>
        <?php if ((float)$service['discount'] > 0): ?>
        <div class="line"><span>Discount</span><span>− ৳<?= number_format((float)$service['discount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="line grand"><span>Net Amount</span><span>৳<?= number_format($net_amount, 2) ?></span></div>
        <div class="line"><span>Paid</span><span>৳<?= number_format($paid_for_service, 2) ?></span></div>
        <div class="line"><span>Due</span><span>৳<?= number_format($due, 2) ?></span></div>
    </div>

    <?php if ($due > 0.009): ?>
        <div class="print-due-note">Due: ৳<?= number_format($due, 2) ?> — to be settled at checkout.</div>
    <?php else: ?>
        <div class="print-paid-note">Fully Paid</div>
    <?php endif; ?>

    <div class="text-center text-muted mt-3" style="font-size:0.75rem;">Thank you!</div>
</div>

<script>
function printReceipt(mode) {
    document.body.classList.remove('print-pos', 'print-a4');
    document.body.classList.add(mode === 'a4' ? 'print-a4' : 'print-pos');
    window.print();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>