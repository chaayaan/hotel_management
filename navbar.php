<?php
/**
 * Shared Sidebar / Topbar
 * Include after auth.php (require_login must already have run).
 * Set $page_title and $active_menu before including this file.
 */
$user = current_user();
$active_menu = $active_menu ?? '';
$page_title = $page_title ?? 'Dashboard';

function nav_active($key, $active) {
    return $key === $active ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?> - Resort Management System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    :root {
        --brand-primary: #0f5132;
        --brand-primary-dark: #0c4128;
        --brand-accent: #d4a537;
        --sidebar-width: 240px;
        --bg-soft: #f4f6f5;
    }
    * { box-sizing: border-box; }
    body {
        background: var(--bg-soft);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }
    .sidebar {
        position: fixed;
        top: 0; left: 0; bottom: 0;
        width: var(--sidebar-width);
        background: var(--brand-primary);
        color: #fff;
        overflow-y: auto;
        z-index: 1030;
        transition: transform 0.25s ease;
    }
    .sidebar-brand {
        padding: 18px 20px;
        font-weight: 700;
        font-size: 1.05rem;
        border-bottom: 1px solid rgba(255,255,255,0.12);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sidebar-brand .badge-accent {
        background: var(--brand-accent);
        color: #1c1c1c;
        font-size: 0.65rem;
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 600;
    }
    .sidebar .nav-section-title {
        text-transform: uppercase;
        font-size: 0.68rem;
        letter-spacing: 0.06em;
        color: rgba(255,255,255,0.5);
        padding: 14px 20px 6px;
    }

    /* Overview links (dashboards) */
    .sidebar .overview-link {
        color: rgba(255,255,255,0.85);
        padding: 9px 14px;
        margin: 3px 12px;
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 8px;
        text-decoration: none;
    }
    .sidebar .overview-link i { font-size: 1rem; width: 18px; text-align: center; }
    .sidebar .overview-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .sidebar .overview-link.active {
        background: transparent;
        border: 1px solid var(--brand-accent);
        color: var(--brand-accent);
        font-weight: 700;
    }

    /* Collapsible group headers (Hotel / Restaurant / Expenses / Management) */
    .sidebar .nav-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 20px 8px;
        color: #fff;
        font-weight: 700;
        font-size: 0.92rem;
        cursor: pointer;
        text-decoration: none;
        user-select: none;
    }
    .sidebar .nav-group-header .label { display: flex; align-items: center; gap: 10px; }
    .sidebar .nav-group-header .label i { font-size: 1.05rem; width: 18px; text-align: center; }
    .sidebar .nav-group-header .chevron { transition: transform 0.2s ease; font-size: 0.8rem; opacity: 0.7; }
    .sidebar .nav-group-header[aria-expanded="true"] .chevron { transform: rotate(180deg); }
    .sidebar .nav-group-header:hover { background: rgba(255,255,255,0.06); }

    .sidebar .nav-link {
        color: rgba(255,255,255,0.85);
        padding: 9px 20px 9px 48px;
        font-size: 0.87rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-left: 3px solid transparent;
    }
    .sidebar .nav-link i { font-size: 0.95rem; width: 18px; text-align: center; }
    .sidebar .nav-link:hover {
        background: rgba(255,255,255,0.08);
        color: #fff;
    }
    .sidebar .nav-link.active {
        background: rgba(255,255,255,0.12);
        color: #fff;
        border-left-color: var(--brand-accent);
        font-weight: 600;
    }
    .main-wrapper {
        margin-left: var(--sidebar-width);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .topbar {
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        padding: 12px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 1020;
    }
    .topbar h5 { margin: 0; font-weight: 600; color: #1c3d2e; }
    .user-chip {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .user-avatar {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: var(--brand-primary);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .content-area { padding: 24px; flex: 1; }
    .sidebar-toggle-btn { display: none; }

    @media (max-width: 991px) {
        .sidebar { transform: translateX(-100%); }
        .sidebar.show { transform: translateX(0); }
        .main-wrapper { margin-left: 0; }
        .sidebar-toggle-btn { display: inline-flex; }
    }

    /* Common card/table styling used across pages */
    .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .card-header { background: #fff; border-bottom: 1px solid #eef0ef; border-radius: 12px 12px 0 0 !important; font-weight: 600; color: #1c3d2e; }
    .btn-brand { background: var(--brand-primary); border-color: var(--brand-primary); color: #fff; }
    .btn-brand:hover { background: var(--brand-primary-dark); border-color: var(--brand-primary-dark); color: #fff; }
    .btn-outline-brand { border-color: var(--brand-primary); color: var(--brand-primary); }
    .btn-outline-brand:hover { background: var(--brand-primary); color: #fff; }
    .table thead th {
        background: #f4f6f5;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #4b5f56;
        font-weight: 600;
        border-bottom: none;
    }
    .badge-status-available { background: #198754; }
    .badge-status-occupied { background: #dc3545; }
    .badge-status-maintenance { background: #fd7e14; }
    .badge-status-out_of_service { background: #6c757d; }
</style>
</head>
<body>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <i class="bi bi-building"></i>
        <span>Resort MS <span class="badge-accent">HOTEL &bull; RESTAURANT</span></span>
    </div>

    <?php
        $is_admin_role = in_array($user['designation'], ['admin', 'general_manager']);
        // Which top-level groups should be open by default: whichever one contains the active page.
        $hotel_pages       = ['front_desk', 'reservations', 'booking_list', 'services_history'];
        $restaurant_pages  = ['restaurant_front_desk', 'restaurant_order_list', 'restaurant_tables_status'];
        $expenses_pages    = ['expense_add', 'expense_history'];
        $management_pages  = ['rooms', 'room_types', 'restaurant_tables', 'restaurant_food_categories', 'restaurant_food_items', 'users', 'expense_categories'];

        $group_open = function($pages) use ($active_menu) {
            return in_array($active_menu, $pages) ? 'show' : '';
        };
        $group_expanded = function($pages) use ($active_menu) {
            return in_array($active_menu, $pages) ? 'true' : 'false';
        };
    ?>

    <div class="nav-section-title">Overview</div>
    <?php if ($is_admin_role): ?>
        <a href="index.php" class="overview-link <?= nav_active('dashboard', $active_menu) ?>">
            <i class="bi bi-grid-1x2-fill"></i> Admin Dashboard
        </a>
    <?php endif; ?>
    <a href="hotel_dashboard.php" class="overview-link <?= nav_active('hotel_dashboard', $active_menu) ?>">
        <i class="bi bi-building"></i> Hotel Dashboard
    </a>
    <a href="restaurant_dashboard.php" class="overview-link <?= nav_active('restaurant_dashboard', $active_menu) ?>">
        <i class="bi bi-cup-hot-fill"></i> Restaurant Dashboard
    </a>

    <!-- Hotel -->
    <a href="#navHotel" class="nav-group-header" data-bs-toggle="collapse" aria-expanded="<?= $group_expanded($hotel_pages) ?>">
        <span class="label"><i class="bi bi-building"></i> Hotel</span>
        <i class="bi bi-chevron-down chevron"></i>
    </a>
    <div class="collapse <?= $group_open($hotel_pages) ?>" id="navHotel">
        <a href="hotel_front_desk.php" class="nav-link <?= nav_active('front_desk', $active_menu) ?>">
            <i class="bi bi-door-open"></i> Front Desk
        </a>
        <a href="hotel_reservations.php" class="nav-link <?= nav_active('reservations', $active_menu) ?>">
            <i class="bi bi-calendar-check"></i> Reservations
        </a>
        <a href="hotel_booking_list.php" class="nav-link <?= nav_active('booking_list', $active_menu) ?>">
            <i class="bi bi-journal-text"></i> Booking List
        </a>
        <a href="hotel_services_history.php" class="nav-link <?= nav_active('services_history', $active_menu) ?>">
            <i class="bi bi-clock-history"></i> Service History
        </a>
    </div>

    <?php if ($is_admin_role): ?>
    <!-- Restaurant -->
    <a href="#navRestaurant" class="nav-group-header" data-bs-toggle="collapse" aria-expanded="<?= $group_expanded($restaurant_pages) ?>">
        <span class="label"><i class="bi bi-cup-hot"></i> Restaurant</span>
        <i class="bi bi-chevron-down chevron"></i>
    </a>
    <div class="collapse <?= $group_open($restaurant_pages) ?>" id="navRestaurant">
        <a href="restaurant_front_desk.php" class="nav-link <?= nav_active('restaurant_front_desk', $active_menu) ?>">
            <i class="bi bi-shop"></i> Restaurant Front Desk
        </a>
        <a href="restaurant_order_list.php" class="nav-link <?= nav_active('restaurant_order_list', $active_menu) ?>">
            <i class="bi bi-journal-text"></i> Order List
        </a>
    </div>

    <!-- Expenses -->
    <a href="#navExpenses" class="nav-group-header" data-bs-toggle="collapse" aria-expanded="<?= $group_expanded($expenses_pages) ?>">
        <span class="label"><i class="bi bi-wallet2"></i> Expenses</span>
        <i class="bi bi-chevron-down chevron"></i>
    </a>
    <div class="collapse <?= $group_open($expenses_pages) ?>" id="navExpenses">
        <a href="expense_add.php" class="nav-link <?= nav_active('expense_add', $active_menu) ?>">
            <i class="bi bi-plus-circle"></i> Add Expense
        </a>
        <a href="expense_history.php" class="nav-link <?= nav_active('expense_history', $active_menu) ?>">
            <i class="bi bi-journal-text"></i> Expense History
        </a>
    </div>

    <!-- Management -->
    <a href="#navManagement" class="nav-group-header" data-bs-toggle="collapse" aria-expanded="<?= $group_expanded($management_pages) ?>">
        <span class="label"><i class="bi bi-gear-fill"></i> Management</span>
        <i class="bi bi-chevron-down chevron"></i>
    </a>
    <div class="collapse <?= $group_open($management_pages) ?>" id="navManagement">
        <a href="hotel_rooms.php" class="nav-link <?= nav_active('rooms', $active_menu) ?>">
            <i class="bi bi-door-closed"></i> Rooms
        </a>
        <a href="hotel_room_type.php" class="nav-link <?= nav_active('room_types', $active_menu) ?>">
            <i class="bi bi-grid-3x3-gap"></i> Room Types
        </a>
        <a href="restaurant_tables.php" class="nav-link <?= nav_active('restaurant_tables', $active_menu) ?>">
            <i class="bi bi-table"></i> Restaurant Tables
        </a>
        <a href="restaurant_food_categories.php" class="nav-link <?= nav_active('restaurant_food_categories', $active_menu) ?>">
            <i class="bi bi-tags"></i> Food Categories
        </a>
        <a href="restaurant_food_items.php" class="nav-link <?= nav_active('restaurant_food_items', $active_menu) ?>">
            <i class="bi bi-egg-fried"></i> Food Items
        </a>
        <a href="expense_categories.php" class="nav-link <?= nav_active('expense_categories', $active_menu) ?>">
            <i class="bi bi-wallet2"></i> Expense Categories
        </a>
        <a href="users.php" class="nav-link <?= nav_active('users', $active_menu) ?>">
            <i class="bi bi-people"></i> Users
        </a>
    </div>
    <?php endif; ?>

    <div class="nav-section-title">&nbsp;</div>
</nav>

<div class="main-wrapper">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary sidebar-toggle-btn" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="bi bi-list"></i>
            </button>
            <h5><?= e($page_title) ?></h5>
        </div>
        <div class="dropdown user-chip">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none text-dark dropdown-toggle" data-bs-toggle="dropdown">
                <div class="user-avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
                <div class="d-none d-sm-block">
                    <div class="fw-semibold small"><?= e($user['full_name']) ?></div>
                    <div class="text-muted" style="font-size:0.72rem;"><?= e(ucwords(str_replace('_', ' ', $user['designation']))) ?></div>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="content-area">
    <?php $__flash = flash_get(); if ($__flash): ?>
        <div class="alert alert-<?= e($__flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($__flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>