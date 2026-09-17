<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? 'all';

$sql = "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, r.room_number
        FROM hotel_bookings b
        JOIN guests g ON g.id = b.guest_id
        JOIN rooms r ON r.id = b.room_id
        WHERE 1=1";

if ($search !== '') {
    $qEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (b.reservation_no LIKE '%{$qEsc}%' OR g.full_name LIKE '%{$qEsc}%' OR g.phone LIKE '%{$qEsc}%' OR r.room_number LIKE '%{$qEsc}%')";
}
if ($filter_status !== 'all' && in_array($filter_status, ['reserved','checked_in','checked_out','cancelled','no_show'])) {
    $sql .= " AND b.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}
$sql .= " ORDER BY b.created_at DESC LIMIT 200";
$bookings_result = mysqli_query($conn, $sql);

$bookings = [];
$booking_ids = [];
while ($b = mysqli_fetch_assoc($bookings_result)) {
    $bookings[(int)$b['id']] = $b;
    $booking_ids[] = (int)$b['id'];
}

// ---------- Batch-fetch aggregates for all bookings on this page in 2 queries (avoids N+1) ----------
$service_totals = [];
$payment_totals = [];
if (!empty($booking_ids)) {
    $idsCsv = implode(',', $booking_ids);

    $svc_res = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount - discount),0) t
                                     FROM hotel_booking_services
                                     WHERE booking_id IN ({$idsCsv})
                                     GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($svc_res)) {
        $service_totals[(int)$row['booking_id']] = (float)$row['t'];
    }

    $pay_res = mysqli_query($conn, "SELECT booking_id, COALESCE(SUM(amount),0) t
                                     FROM hotel_payments
                                     WHERE booking_id IN ({$idsCsv})
                                     GROUP BY booking_id");
    while ($row = mysqli_fetch_assoc($pay_res)) {
        $payment_totals[(int)$row['booking_id']] = (float)$row['t'];
    }
}

// Build full financial rows
foreach ($bookings as $bid => &$b) {
    $service_total = $service_totals[$bid] ?? 0.0;
    $room_total = (float)$b['room_charge_total'];
    $extension_total = (float)$b['extension_charge_total'];
    $discount = (float)$b['discount'];
    $tax = (float)$b['tax'];
    $total_payable = max(0, $room_total + $extension_total + $service_total - $discount + $tax);
    $total_paid = $payment_totals[$bid] ?? 0.0;
    $balance_due = max(0, $total_payable - $total_paid);

    $b['service_total'] = $service_total;
    $b['extension_total'] = $extension_total;
    $b['total_payable'] = $total_payable;
    $b['total_paid'] = $total_paid;
    $b['balance_due'] = $balance_due;
}
unset($b);
$bookings = array_values($bookings);

$page_title = 'Booking List';
$active_menu = 'booking_list';
require __DIR__ . '/navbar.php';
?>

<style>
    .table-financial th, .table-financial td { white-space: nowrap; font-size: 0.82rem; }
    .payment-status-paid { background: #d1f5e0; color: #0f5132; }
    .payment-status-due { background: #fddede; color: #a91d2c; }
</style>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-journal-text me-1"></i> Booking List</span>
        <form method="GET" class="d-flex flex-wrap gap-2">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search reservation, guest, phone, room..." value="<?= e($search) ?>" style="width:260px;">
            <select name="status" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
                <?php foreach (['all'=>'All Status','reserved'=>'Reserved','checked_in'=>'Checked In','checked_out'=>'Checked Out','cancelled'=>'Cancelled','no_show'=>'No Show'] as $val=>$label): ?>
                    <option value="<?= $val ?>" <?= $filter_status === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-financial mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Booking #</th>
                        <th>Guest</th>
                        <th>Phone</th>
                        <th>Room</th>
                        <th>From → Until</th>
                        <th>Status</th>
                        <th class="text-end">Total Payable</th>
                        <th class="text-end">Total Paid</th>
                        <th class="text-end">Balance Due</th>
                        <th>Payment</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No bookings found.</td></tr>
                <?php else: foreach ($bookings as $b):
                    $bid = (int)$b['id'];
                    $is_paid = $b['balance_due'] <= 0.009;
                    $is_cancelled = in_array($b['status'], ['cancelled', 'no_show']);
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['reservation_no']) ?></td>
                        <td><?= e($b['guest_name']) ?></td>
                        <td><?= e($b['guest_phone']) ?></td>
                        <td><?= e($b['room_number']) ?></td>
                        <td>
                            <div><?= e(date('d M Y', strtotime($b['reserved_from']))) ?> → <?= e(date('d M Y', strtotime($b['reserved_until']))) ?></div>
                            <div class="text-muted" style="font-size:0.74rem;">
                                <?= $b['checkin_at'] ? e(date('h:i a d/n/Y', strtotime($b['checkin_at']))) : '—' ?>
                                →
                                <?= $b['checkout_at'] ? e(date('h:i a d/n/Y', strtotime($b['checkout_at']))) : '—' ?>
                            </div>
                        </td>
                        <td><span class="badge <?= booking_status_badge($b['status']) ?>"><?= e(ucwords(str_replace('_',' ',$b['status']))) ?></span></td>
                        <?php if ($is_cancelled): ?>
                        <td class="text-end text-muted" colspan="3">—</td>
                        <td>
                            <span class="badge bg-dark-subtle text-dark">Cancelled</span>
                            <?php if (!empty($b['cancelled_at'])): ?>
                            <div class="text-muted" style="font-size:0.74rem;">at <?= e(date('h:i a d/n/Y', strtotime($b['cancelled_at']))) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php else: ?>
                        <td class="text-end fw-semibold">৳<?= number_format($b['total_payable'], 2) ?></td>
                        <td class="text-end">৳<?= number_format($b['total_paid'], 2) ?></td>
                        <td class="text-end fw-semibold <?= $b['balance_due'] > 0 ? 'text-danger' : 'text-success' ?>">৳<?= number_format($b['balance_due'], 2) ?></td>
                        <td><span class="badge <?= $is_paid ? 'payment-status-paid' : 'payment-status-due' ?>"><?= $is_paid ? 'Paid' : 'Due' ?></span></td>
                        <?php endif; ?>
                        <td class="text-end pe-3">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                            <?php if ($b['status'] === 'reserved'): ?>
                                <a href="hotel_reservations_checkin.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-brand"><i class="bi bi-box-arrow-in-right"></i> Check In</a>
                                <a href="hotel_reservations_cancelled.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</a>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a>
                            <?php elseif ($b['status'] === 'checked_in'): ?>
                                <a href="hotel_extend_stay.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-plus"></i> Extend</a>
                                <a href="hotel_services.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-brand"><i class="bi bi-cup-hot"></i> Service</a>
                                <a href="hotel_reservations_checkout.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-right"></i> Check Out</a>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-receipt"></i></a>
                            <?php elseif ($b['status'] === 'checked_out'): ?>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Receipt</a>
                            <?php elseif (in_array($b['status'], ['cancelled','no_show'])): ?>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>