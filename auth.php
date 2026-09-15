<?php
/**
 * Auth Guard
 * Include this at the top of every protected page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user() {
    if (!is_logged_in()) return null;
    return [
        'id'          => $_SESSION['user_id'],
        'username'    => $_SESSION['username'],
        'full_name'   => $_SESSION['full_name'],
        'designation' => $_SESSION['designation'],
    ];
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// Roles allowed to access a page. Pass an array like ['admin','general_manager']
function require_role($allowed_roles = []) {
    require_login();
    if (!empty($allowed_roles) && !in_array($_SESSION['designation'], $allowed_roles)) {
        http_response_code(403);
        die('
        <div style="font-family:sans-serif;max-width:500px;margin:80px auto;text-align:center;">
            <h2 style="color:#dc3545;">Access Denied</h2>
            <p>You do not have permission to view this page.</p>
            <a href="index.php" style="color:#0d6efd;">Go back to dashboard</a>
        </div>');
    }
}

function flash_set($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
