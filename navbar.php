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
        --rail-width: 70px;
        --panel-width: 170px;
        --sidebar-width: calc(var(--rail-width) + var(--panel-width));
        --header-height: 61px;
        --bg-soft: #f4f6f5;
    }
    /* When collapsed, the sidebar shell shrinks to just the icon rail width */
    body.sidebar-collapsed .sidebar,
    body.sidebar-collapsed .sidebar-header { width: var(--rail-width); }
    body.sidebar-collapsed .main-wrapper { margin-left: var(--rail-width); }
    body.sidebar-collapsed .nav-panel { display: none; }
    body.sidebar-collapsed .sidebar-header .brand-text { display: none; }
    body.sidebar-collapsed .sidebar-header { padding: 0; justify-content: center; }
    * { box-sizing: border-box; }
    body {
        background: var(--bg-soft);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    /* ===== Sidebar shell (rail + panel side by side) ===== */
    .sidebar {
        position: fixed;
        top: 0; left: 0; bottom: 0;
        width: var(--sidebar-width);
        display: flex;
        z-index: 1030;
        transition: transform 0.25s ease, width 0.25s ease;
    }

    /* ===== Icon rail (thin strip: Dashboards / Hotel / Restaurant / Expenses / Management) ===== */
    .nav-rail {
        width: var(--rail-width);
        background: var(--brand-primary-dark);
        display: flex;
        flex-direction: column;
        align-items: stretch;
        overflow-y: auto;
        flex-shrink: 0;
        margin-top: var(--header-height);
    }
    /* ===== Single combined brand header spanning rail + panel, same height as topbar ===== */
    .sidebar-header {
        position: fixed;
        top: 0; left: 0;
        width: var(--sidebar-width);
        height: var(--header-height);
        background: var(--brand-primary-dark);
        border-bottom: 1px solid rgba(255,255,255,0.12);
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 0 10px;
        z-index: 1031;
        flex-shrink: 0;
    }
    .sidebar-header i { color: #fff; font-size: 1.3rem; flex-shrink: 0; }
    .sidebar-header .brand-text {
        color: #fff;
        font-weight: 700;
        font-size: 0.78rem;
        line-height: 1.15;
        white-space: normal;
        overflow-wrap: break-word;
    }
    .rail-item {
        border: none;
        background: transparent;
        color: rgba(255,255,255,0.75);
        padding: 12px 5px 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        text-decoration: none;
        cursor: pointer;
        border-left: 3px solid transparent;
        font-size: 0;
        width: 100%;
    }
    .rail-item i { font-size: 1.4rem; }
    .rail-item .rail-label {
        font-size: 0.68rem;
        letter-spacing: 0.01em;
        font-weight: 600;
        text-align: center;
        line-height: 1.1;
    }
    .rail-item:hover {
        background: rgba(255,255,255,0.08);
        color: #fff;
    }
    .rail-item.active {
        background: var(--brand-primary);
        color: #fff;
        border-left-color: var(--brand-accent);
    }
    .rail-item.active i { color: var(--brand-accent); }

    /* ===== Flyout panel (the actual links for the selected group) ===== */
    .nav-panel {
        width: var(--panel-width);
        background: var(--brand-primary);
        color: #fff;
        overflow-y: auto;
        flex-shrink: 0;
        margin-top: var(--header-height);
        padding-top: 10px;
    }
    .nav-panel-group { display: none; }
    .nav-panel-group.active { display: block; }

    /* Overview links (dashboards) reuse same link style inside panel too */
    .nav-panel .overview-link,
    .nav-panel .nav-link {
        color: rgba(255,255,255,0.85);
        padding: 9px 8px;
        margin: 3px 4px;
        font-size: 0.83rem;
        display: flex;
        align-items: center;
        gap: 7px;
        border-radius: 8px;
        text-decoration: none;
        border-left: 3px solid transparent;
        line-height: 1.25;
    }
    .nav-panel .overview-link i,
    .nav-panel .nav-link i { font-size: 0.98rem; width: 16px; text-align: center; flex-shrink: 0; }
    .nav-panel .overview-link:hover,
    .nav-panel .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .nav-panel .overview-link.active,
    .nav-panel .nav-link.active {
        background: rgba(255,255,255,0.12);
        color: #fff;
        border-left-color: var(--brand-accent);
        font-weight: 700;
    }

    .main-wrapper {
        margin-left: var(--sidebar-width);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        transition: margin-left 0.25s ease;
    }
    .sidebar-header { transition: width 0.25s ease; }

    /* ===== Collapse/expand toggle button, sits at bottom of the rail ===== */
    .rail-collapse-btn {
        margin-top: auto;
        border: none;
        background: transparent;
        color: rgba(255,255,255,0.6);
        padding: 14px 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-top: 1px solid rgba(255,255,255,0.1);
    }
    .rail-collapse-btn:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .rail-collapse-btn i { font-size: 1.1rem; transition: transform 0.25s ease; }
    body.sidebar-collapsed .rail-collapse-btn i { transform: rotate(180deg); }
    .topbar {
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        height: var(--header-height);
        padding: 0 24px;
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
        /* Ignore collapsed state on mobile: sidebar is off-canvas full width when shown */
        body.sidebar-collapsed .sidebar,
        body.sidebar-collapsed .sidebar-header { width: var(--sidebar-width); }
        body.sidebar-collapsed .main-wrapper { margin-left: 0; }
        body.sidebar-collapsed .nav-panel { display: block; }
        body.sidebar-collapsed .sidebar-header .brand-text { display: block; }
        body.sidebar-collapsed .sidebar-header { padding: 0 20px; justify-content: flex-start; }
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
    /* Stat cards (payroll dashboard / history) */
    .stat-card { display: flex; flex-direction: row; align-items: center; gap: 14px; padding: 16px 18px; }
    .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
    .stat-icon.green { background: #e3f1ea; color: var(--brand-primary); }
    .stat-icon.red   { background: #fbe6e8; color: #b02a37; }
    .stat-icon.amber { background: #fbf0d3; color: #8a6a10; }
    .stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.15; color: #1c3d2e; }
    .stat-label { font-size: 0.78rem; color: #6b7c74; }
    .badge-status-available { background: #198754; }
    .badge-status-occupied { background: #dc3545; }
    .badge-status-maintenance { background: #fd7e14; }
    .badge-status-out_of_service { background: #6c757d; }
</style>
</head>
<body>

<?php
    $is_admin_role = in_array($user['designation'], ['admin', 'general_manager']);

    // Which pages belong to which group (drives both "which rail icon is active"
    // and "which panel opens by default").
    $dashboard_pages   = ['dashboard', 'hotel_dashboard', 'restaurant_dashboard', 'payroll_dashboard'];
    $hotel_pages       = ['front_desk', 'reservations', 'booking_list', 'services_history'];
    $restaurant_pages  = ['restaurant_front_desk', 'restaurant_order_list', 'restaurant_tables_status'];
    $expenses_pages    = ['expense_add', 'expense_history'];
    $payroll_pages     = ['payroll_employees', 'payroll_designations', 'payroll_departments', 'payroll_attendance', 'attendance_history', 'payroll_generate', 'payroll_list', 'payroll_history'];
    $management_pages  = ['rooms', 'room_types', 'restaurant_tables', 'restaurant_food_categories', 'restaurant_food_items', 'users', 'expense_categories', 'settings'];

    $in_group = function($pages) use ($active_menu) {
        return in_array($active_menu, $pages);
    };

    // Figure out which group is active so we know which rail icon + panel to open.
    $active_group = 'dashboard';
    if ($in_group($hotel_pages)) $active_group = 'hotel';
    elseif ($in_group($restaurant_pages)) $active_group = 'restaurant';
    elseif ($in_group($expenses_pages)) $active_group = 'expenses';
    elseif ($in_group($payroll_pages)) $active_group = 'payroll';
    elseif ($in_group($management_pages)) $active_group = 'management';
    elseif ($in_group($dashboard_pages)) $active_group = 'dashboard';
?>

<!-- ===== Single combined header: rail width + panel width, matches topbar height ===== -->
<div class="sidebar-header">
    <i class="bi bi-building"></i>
    <span class="brand-text">Resort Management System</span>
</div>

<nav class="sidebar" id="sidebar">

    <!-- ===== Thin icon strip ===== -->
    <div class="nav-rail">
        <button type="button" class="rail-item <?= $active_group === 'dashboard' ? 'active' : '' ?>" data-group="dashboard">
            <i class="bi bi-grid-1x2-fill"></i>
            <span class="rail-label">Dashboards</span>
        </button>

        <button type="button" class="rail-item <?= $active_group === 'hotel' ? 'active' : '' ?>" data-group="hotel">
            <i class="bi bi-building"></i>
            <span class="rail-label">Hotel</span>
        </button>

        <?php if ($is_admin_role): ?>
        <button type="button" class="rail-item <?= $active_group === 'restaurant' ? 'active' : '' ?>" data-group="restaurant">
            <i class="bi bi-cup-hot"></i>
            <span class="rail-label">Restaurant</span>
        </button>

        <button type="button" class="rail-item <?= $active_group === 'expenses' ? 'active' : '' ?>" data-group="expenses">
            <i class="bi bi-wallet2"></i>
            <span class="rail-label">Expenses</span>
        </button>

        <button type="button" class="rail-item <?= $active_group === 'payroll' ? 'active' : '' ?>" data-group="payroll">
            <i class="bi bi-cash-stack"></i>
            <span class="rail-label">Payroll</span>
        </button>

        <button type="button" class="rail-item <?= $active_group === 'management' ? 'active' : '' ?>" data-group="management">
            <i class="bi bi-gear-fill"></i>
            <span class="rail-label">Management</span>
        </button>
        <?php endif; ?>

        <button type="button" class="rail-collapse-btn" id="sidebarCollapseBtn" title="Expand / collapse sidebar">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>

    <!-- ===== Flyout panel: shows only the links for the selected group ===== -->
    <div class="nav-panel">

        <!-- Dashboards -->
        <div class="nav-panel-group <?= $active_group === 'dashboard' ? 'active' : '' ?>" data-panel="dashboard">
            <?php if ($is_admin_role): ?>
            <a href="index.php" class="overview-link <?= nav_active('index', $active_menu) ?>">
                <i class="bi bi-speedometer2"></i> Admin Dashboard
            </a>
            <?php endif; ?>
            <?php if ($is_admin_role): ?>
            <a href="payroll_dashboard.php" class="overview-link <?= nav_active('payroll_dashboard', $active_menu) ?>">
                <i class="bi bi-cash-stack"></i> Payroll Dashboard
            </a>
            <?php endif; ?>
            <a href="hotel_dashboard.php" class="overview-link <?= nav_active('hotel_dashboard', $active_menu) ?>">
                <i class="bi bi-building"></i> Hotel Dashboard
            </a>
            <a href="restaurant_dashboard.php" class="overview-link <?= nav_active('restaurant_dashboard', $active_menu) ?>">
                <i class="bi bi-cup-hot-fill"></i> Restaurant Dashboard
            </a>
        </div>

        <!-- Hotel -->
        <div class="nav-panel-group <?= $active_group === 'hotel' ? 'active' : '' ?>" data-panel="hotel">
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
        <div class="nav-panel-group <?= $active_group === 'restaurant' ? 'active' : '' ?>" data-panel="restaurant">
            <a href="restaurant_front_desk.php" class="nav-link <?= nav_active('restaurant_front_desk', $active_menu) ?>">
                <i class="bi bi-shop"></i> Restaurant Front Desk
            </a>
            <a href="restaurant_order_list.php" class="nav-link <?= nav_active('restaurant_order_list', $active_menu) ?>">
                <i class="bi bi-journal-text"></i> Order List
            </a>
        </div>

        <!-- Expenses -->
        <div class="nav-panel-group <?= $active_group === 'expenses' ? 'active' : '' ?>" data-panel="expenses">
            <a href="expense_add.php" class="nav-link <?= nav_active('expense_add', $active_menu) ?>">
                <i class="bi bi-plus-circle"></i> Add Expense
            </a>
            <a href="expense_history.php" class="nav-link <?= nav_active('expense_history', $active_menu) ?>">
                <i class="bi bi-journal-text"></i> Expense History
            </a>
        </div>

        <!-- Payroll -->
        <div class="nav-panel-group <?= $active_group === 'payroll' ? 'active' : '' ?>" data-panel="payroll">
            <a href="payroll_employees.php" class="nav-link <?= nav_active('payroll_employees', $active_menu) ?>">
                <i class="bi bi-people"></i> Employees
            </a>
            <a href="payroll_designations.php" class="nav-link <?= nav_active('payroll_designations', $active_menu) ?>">
                <i class="bi bi-award"></i> Designations
            </a>
            <a href="payroll_departments.php" class="nav-link <?= nav_active('payroll_departments', $active_menu) ?>">
                <i class="bi bi-diagram-3"></i> Departments
            </a>
            <a href="payroll_attendance.php" class="nav-link <?= nav_active('payroll_attendance', $active_menu) ?>">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
            <a href="attendance_history.php" class="nav-link <?= nav_active('attendance_history', $active_menu) ?>">
                <i class="bi bi-calendar-week"></i> Attendance History
            </a>
            <a href="payroll_generate.php" class="nav-link <?= nav_active('payroll_generate', $active_menu) ?>">
                <i class="bi bi-calculator"></i> Generate Salary
            </a>
            <a href="payroll_list.php" class="nav-link <?= nav_active('payroll_list', $active_menu) ?>">
                <i class="bi bi-journal-text"></i> Payroll List
            </a>
            <a href="payroll_salary_history.php" class="nav-link <?= nav_active('payroll_history', $active_menu) ?>">
                <i class="bi bi-clock-history"></i> Salary History
            </a>
        </div>

        <!-- Management -->
        <div class="nav-panel-group <?= $active_group === 'management' ? 'active' : '' ?>" data-panel="management">
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
            <a href="hotel_settings.php" class="nav-link <?= nav_active('settings', $active_menu) ?>">
                <i class="bi bi-gear"></i> Resort Settings
            </a>
        </div>
        <?php endif; ?>

    </div>
</nav>

<script>
document.querySelectorAll('.rail-item').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var group = btn.getAttribute('data-group');

        document.querySelectorAll('.rail-item').forEach(function (b) {
            b.classList.toggle('active', b === btn);
        });
        document.querySelectorAll('.nav-panel-group').forEach(function (panel) {
            panel.classList.toggle('active', panel.getAttribute('data-panel') === group);
        });

        // If sidebar is collapsed and user clicks a rail item, auto-expand
        // so they can see the panel for the group they just selected.
        if (document.body.classList.contains('sidebar-collapsed')) {
            setSidebarCollapsed(false);
        }
    });
});

function setSidebarCollapsed(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    try { localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0'); } catch (e) {}
}

(function initSidebarCollapse() {
    var saved = null;
    try { saved = localStorage.getItem('sidebarCollapsed'); } catch (e) {}
    if (saved === '1') {
        document.body.classList.add('sidebar-collapsed');
    }
    var btn = document.getElementById('sidebarCollapseBtn');
    if (btn) {
        btn.addEventListener('click', function () {
            setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        });
    }
})();
</script>

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