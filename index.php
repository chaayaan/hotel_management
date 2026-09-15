<?php
require_once __DIR__ . '/auth.php';
require_login();

$total_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM rooms WHERE is_active = 1"))['c'];
$available_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM rooms WHERE status = 'available' AND is_active = 1"))['c'];
$occupied_rooms = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM rooms WHERE status = 'occupied' AND is_active = 1"))['c'];
$total_room_types = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM room_types"))['c'];

$page_title = 'Dashboard';
$active_menu = 'dashboard';
require __DIR__ . '/navbar.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="text-muted small mb-1">Total Rooms</div>
            <div class="fs-3 fw-bold text-dark"><?= (int)$total_rooms ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="text-muted small mb-1">Available</div>
            <div class="fs-3 fw-bold text-success"><?= (int)$available_rooms ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="text-muted small mb-1">Occupied</div>
            <div class="fs-3 fw-bold text-danger"><?= (int)$occupied_rooms ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100">
            <div class="text-muted small mb-1">Room Types</div>
            <div class="fs-3 fw-bold text-dark"><?= (int)$total_room_types ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h6 class="fw-semibold mb-2">Welcome, <?= e(current_user()['full_name']) ?> 👋</h6>
        <p class="text-muted mb-0">Use the sidebar to manage rooms, room types, and users. Reservation, check-in/out, and front desk modules are coming next.</p>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
