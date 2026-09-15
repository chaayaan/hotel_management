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
$bookings = mysqli_query($conn, $sql);

$page_title = 'Booking List';
$active_menu = 'booking_list';
require __DIR__ . '/navbar.php';
?>

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
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Reservation #</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>From → Until</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($bookings) === 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No bookings found.</td></tr>
                <?php else: while ($b = mysqli_fetch_assoc($bookings)):
                    $bid = (int)$b['id'];
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['reservation_no']) ?></td>
                        <td><?= e($b['guest_name']) ?><div class="text-muted small"><?= e($b['guest_phone']) ?></div></td>
                        <td><?= e($b['room_number']) ?></td>
                        <td class="small"><?= e(date('d M Y', strtotime($b['reserved_from']))) ?> → <?= e(date('d M Y', strtotime($b['reserved_until']))) ?></td>
                        <td>৳<?= number_format((float)$b['total_amount'], 2) ?></td>
                        <td><span class="badge <?= booking_status_badge($b['status']) ?>"><?= e(ucwords(str_replace('_',' ',$b['status']))) ?></span></td>
                        <td class="text-end pe-3">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                            <?php if ($b['status'] === 'reserved'): ?>
                                <a href="hotel_reservations_checkin.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-brand"><i class="bi bi-box-arrow-in-right"></i> Check In</a>
                                <a href="hotel_reservations_cancelled.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</a>
                            <?php elseif ($b['status'] === 'checked_in'): ?>
                                <a href="hotel_front_desk.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-door-open"></i> Front Desk</a>
                                <a href="hotel_extend_stay.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-plus"></i> Extend</a>
                                <a href="hotel_services.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-outline-brand"><i class="bi bi-cup-hot"></i> Service</a>
                                <a href="hotel_reservations_checkout.php?booking_id=<?= $bid ?>" class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-right"></i> Check Out</a>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>&type=checkin" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i></a>
                            <?php elseif ($b['status'] === 'checked_out'): ?>
                                <a href="hotel_receipt.php?booking_id=<?= $bid ?>&type=checkout" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Receipt</a>
                            <?php elseif (in_array($b['status'], ['cancelled','no_show'])): ?>
                                <span class="text-muted small fst-italic">No actions available</span>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>