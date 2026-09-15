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
    .sidebar .nav-link {
        color: rgba(255,255,255,0.85);
        padding: 10px 20px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-left: 3px solid transparent;
    }
    .sidebar .nav-link i { font-size: 1rem; width: 18px; text-align: center; }
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
        <span>Resort MS <span class="badge-accent">HOTEL</span></span>
    </div>

    <div class="nav-section-title">Main</div>
    <a href="index.php" class="nav-link <?= nav_active('dashboard', $active_menu) ?>">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <div class="nav-section-title">Front Desk</div>
    <a href="hotel_front_desk.php" class="nav-link <?= nav_active('front_desk', $active_menu) ?>">
        <i class="bi bi-door-open"></i> Front Desk
    </a>
    <a href="hotel_reservations.php" class="nav-link <?= nav_active('reservations', $active_menu) ?>">
        <i class="bi bi-calendar-check"></i> Reservations
    </a>
    <a href="hotel_checkin.php" class="nav-link <?= nav_active('checkin', $active_menu) ?>">
        <i class="bi bi-box-arrow-in-right"></i> Check-In
    </a>
    <a href="hotel_checkout.php" class="nav-link <?= nav_active('checkout', $active_menu) ?>">
        <i class="bi bi-box-arrow-right"></i> Check-Out
    </a>

    <div class="nav-section-title">Property Setup</div>
    <a href="hotel_rooms.php" class="nav-link <?= nav_active('rooms', $active_menu) ?>">
        <i class="bi bi-door-closed"></i> Rooms
    </a>
    <a href="hotel_room_type.php" class="nav-link <?= nav_active('room_types', $active_menu) ?>">
        <i class="bi bi-grid-3x3-gap"></i> Room Types
    </a>

    <?php if (in_array($user['designation'], ['admin', 'general_manager'])): ?>
    <div class="nav-section-title">Administration</div>
    <a href="users.php" class="nav-link <?= nav_active('users', $active_menu) ?>">
        <i class="bi bi-people"></i> Users
    </a>
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
