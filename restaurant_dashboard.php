<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();

$page_title = 'Restaurant Dashboard';
$active_menu = 'restaurant_dashboard';
require __DIR__ . '/navbar.php';
?>

<div class="dev-wrap">
    <div class="dev-icon"><i class="bi bi-cup-hot-fill"></i></div>
    <h3>Restaurant Dashboard</h3>
    <p class="dev-sub">This page is currently under development.</p>
    <p class="dev-msg">We're working hard to bring you a complete overview of your restaurant operations — orders, tables, revenue, and more, all in one place.</p>
    <div class="dev-badge"><i class="bi bi-hourglass-split me-1"></i> Coming Soon</div>
    <p class="dev-thanks">Please stay with us!</p>
</div>

<style>
    .dev-wrap {
        max-width: 520px;
        margin: 60px auto;
        text-align: center;
        background: #fff;
        border: 1px solid #eef0ef;
        border-radius: 16px;
        padding: 48px 32px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .dev-icon {
        width: 76px; height: 76px; border-radius: 50%;
        background: #e7f3ec; color: #0f5132;
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem; margin: 0 auto 20px;
    }
    .dev-wrap h3 { color: #1c3d2e; font-weight: 800; margin-bottom: 8px; }
    .dev-sub { color: #495a52; font-weight: 600; font-size: 0.95rem; margin-bottom: 14px; }
    .dev-msg { color: #8a938e; font-size: 0.9rem; line-height: 1.6; margin-bottom: 20px; }
    .dev-badge {
        display: inline-flex; align-items: center;
        background: #fff8e8; color: #b8860b;
        border: 1px solid #f0e0b0;
        border-radius: 999px;
        padding: 6px 16px;
        font-size: 0.82rem; font-weight: 700;
        margin-bottom: 16px;
    }
    .dev-thanks { color: #1c3d2e; font-weight: 600; font-size: 0.9rem; margin: 0; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
