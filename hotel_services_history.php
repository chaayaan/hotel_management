<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_functions.php';
require_login();

$user = current_user();
$booking_id_filter = (int)($_GET['booking_id'] ?? 0);
$errors = [];

// ---------- Handle POST: edit/delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_services_history.php' . ($booking_id_filter ? '?booking_id=' . $booking_id_filter : ''));
        exit;
    }

    $action = $_POST['action'] ?? '';
    $service_id = (int)($_POST['service_id'] ?? 0);

    if ($action === 'update_service') {
        $discount = (float)($_POST['discount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $item_ids = $_POST['item_id'] ?? [];
        $item_names = $_POST['item_name'] ?? [];
        $item_qtys = $_POST['item_qty'] ?? [];
        $item_prices = $_POST['item_price'] ?? [];

        $service = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE id = {$service_id} LIMIT 1"));

        if (!$service) {
            flash_set('danger', 'Service record not found.');
        } else {
            $new_amount = 0;
            $updates = [];
            for ($i = 0; $i < count($item_ids); $i++) {
                $iid = (int)$item_ids[$i];
                $name = trim($item_names[$i] ?? '');
                $qty = max(1, (int)($item_qtys[$i] ?? 1));
                $price = max(0, (float)($item_prices[$i] ?? 0));
                if ($name === '') continue;
                $total = $qty * $price;
                $new_amount += $total;
                $updates[] = compact('iid', 'name', 'qty', 'price', 'total');
            }

            if ($discount < 0 || $discount > $new_amount) {
                flash_set('danger', 'Discount cannot be negative or exceed the new service amount.');
            } else {
                mysqli_begin_transaction($conn);
                $ok = true;

                foreach ($updates as $u) {
                    $stmt = mysqli_prepare($conn, "UPDATE hotel_booking_service_items SET item_name=?, quantity=?, price=?, total_price=? WHERE id=?");
                    mysqli_stmt_bind_param($stmt, 'siddi', $u['name'], $u['qty'], $u['price'], $u['total'], $u['iid']);
                    $ok = mysqli_stmt_execute($stmt) && $ok;
                }

                if ($ok) {
                    $stmt = mysqli_prepare($conn, "UPDATE hotel_booking_services SET amount = ?, discount = ?, notes = ? WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'ddsi', $new_amount, $discount, $notes, $service_id);
                    $ok = mysqli_stmt_execute($stmt) && $ok;
                }

                if ($ok) {
                    $ok = recalc_booking_total($conn, $service['booking_id']);
                }

                if ($ok) {
                    mysqli_commit($conn);
                    flash_set('success', 'Service updated and booking totals recalculated.');
                } else {
                    mysqli_rollback($conn);
                    flash_set('danger', 'Failed to update service: ' . mysqli_error($conn));
                }
            }
        }
    } elseif ($action === 'delete_service') {
        $service = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hotel_booking_services WHERE id = {$service_id} LIMIT 1"));
        if ($service) {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn, "DELETE FROM hotel_booking_service_items WHERE booking_service_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $service_id);
            $ok = mysqli_stmt_execute($stmt) && $ok;

            if ($ok) {
                $stmt = mysqli_prepare($conn, "DELETE FROM hotel_booking_services WHERE id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $service_id);
                $ok = mysqli_stmt_execute($stmt) && $ok;
            }

            if ($ok) {
                $ok = recalc_booking_total($conn, $service['booking_id']);
            }

            if ($ok) {
                mysqli_commit($conn);
                flash_set('success', 'Service deleted and booking totals recalculated.');
            } else {
                mysqli_rollback($conn);
                flash_set('danger', 'Failed to delete service: ' . mysqli_error($conn));
            }
        } else {
            flash_set('danger', 'Service record not found.');
        }
    }

    header('Location: hotel_services_history.php' . ($booking_id_filter ? '?booking_id=' . $booking_id_filter : ''));
    exit;
}

// ---------- Fetch list ----------
$search = trim($_GET['q'] ?? '');
$sql = "SELECT s.*, b.reservation_no, g.full_name AS guest_name, r.room_number
        FROM hotel_booking_services s
        JOIN hotel_bookings b ON b.id = s.booking_id
        JOIN guests g ON g.id = b.guest_id
        JOIN rooms r ON r.id = b.room_id
        WHERE 1=1";
if ($booking_id_filter > 0) {
    $sql .= " AND s.booking_id = {$booking_id_filter}";
}
if ($search !== '') {
    $qEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (b.reservation_no LIKE '%{$qEsc}%' OR g.full_name LIKE '%{$qEsc}%' OR r.room_number LIKE '%{$qEsc}%')";
}
$sql .= " ORDER BY s.created_at DESC LIMIT 200";
$services_result = mysqli_query($conn, $sql);

$services_list = [];
while ($row = mysqli_fetch_assoc($services_result)) {
    $items_res = mysqli_query($conn, "SELECT * FROM hotel_booking_service_items WHERE booking_service_id = {$row['id']} ORDER BY id ASC");
    $row['items'] = [];
    while ($it = mysqli_fetch_assoc($items_res)) $row['items'][] = $it;
    $services_list[] = $row;
}

$page_title = 'Service History';
$active_menu = 'services_history';
require __DIR__ . '/navbar.php';
?>

<style>
    .item-edit-row { display: grid; grid-template-columns: 1fr 70px 90px 90px; gap: 8px; margin-bottom: 6px; }
</style>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1"></i> Service History <?= $booking_id_filter ? '(filtered by booking)' : '' ?></span>
        <form method="GET" class="d-flex gap-2">
            <?php if ($booking_id_filter): ?><input type="hidden" name="booking_id" value="<?= $booking_id_filter ?>"><?php endif; ?>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search reservation, guest, room..." value="<?= e($search) ?>" style="width:240px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Reservation</th>
                        <th>Guest / Room</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Discount</th>
                        <th>Net</th>
                        <th>Date</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($services_list)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No service records found.</td></tr>
                <?php else: foreach ($services_list as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($s['reservation_no']) ?></td>
                        <td><?= e($s['guest_name']) ?><div class="text-muted small"><?= e($s['room_number']) ?></div></td>
                        <td><?= e(ucfirst($s['service_type'])) ?></td>
                        <td>৳<?= number_format((float)$s['amount'], 2) ?></td>
                        <td>৳<?= number_format((float)$s['discount'], 2) ?></td>
                        <td class="fw-semibold">৳<?= number_format((float)$s['amount'] - (float)$s['discount'], 2) ?></td>
                        <td class="text-muted small"><?= e(date('d M Y', strtotime($s['created_at']))) ?></td>
                        <td class="text-end pe-3">
                            <a href="hotel_service_receipt.php?service_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-dark" title="Receipt"><i class="bi bi-printer"></i></a>
                            <button class="btn btn-sm btn-outline-brand" onclick='openEditModal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-outline-danger" onclick="openDeleteModal(<?= (int)$s['id'] ?>)"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_service">
            <input type="hidden" name="service_id" id="edit_service_id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Notes</label>
                    <input type="text" name="notes" id="edit_notes" class="form-control" maxlength="255">
                </div>
                <label class="form-label small fw-semibold">Items</label>
                <div id="editItemsContainer"></div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Discount</label>
                    <input type="number" step="0.01" min="0" name="discount" id="edit_discount" class="form-control">
                </div>
                <div class="alert alert-info small mb-0">Editing items will recalculate this service's total and update the booking's total amount automatically.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand btn-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_service">
            <input type="hidden" name="service_id" id="delete_service_id">
            <div class="modal-header">
                <h5 class="modal-title">Delete Service Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure? This will remove the service and its items, and recalculate the booking's total amount.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(s) {
    document.getElementById('edit_service_id').value = s.id;
    document.getElementById('edit_notes').value = s.notes || '';
    document.getElementById('edit_discount').value = s.discount;

    const container = document.getElementById('editItemsContainer');
    container.innerHTML = '';
    s.items.forEach(it => {
        const row = document.createElement('div');
        row.className = 'item-edit-row';
        row.innerHTML = `
            <input type="hidden" name="item_id[]" value="${it.id}">
            <input type="text" name="item_name[]" class="form-control form-control-sm" value="${it.item_name}">
            <input type="number" name="item_qty[]" class="form-control form-control-sm" value="${it.quantity}" min="1">
            <input type="number" name="item_price[]" class="form-control form-control-sm" value="${it.price}" step="0.01" min="0">
        `;
        container.appendChild(row);
    });

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openDeleteModal(id) {
    document.getElementById('delete_service_id').value = id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>