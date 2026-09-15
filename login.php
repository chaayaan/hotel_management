<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, username, password_hash, full_name, designation, is_active FROM users WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        if (!$user) {
            $error = 'Invalid username or password.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'Your account has been deactivated. Contact the administrator.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['username']    = $user['username'];
            $_SESSION['full_name']   = $user['full_name'];
            $_SESSION['designation'] = $user['designation'];

            $upd = mysqli_prepare($conn, "UPDATE users SET last_login_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'i', $user['id']);
            mysqli_stmt_execute($upd);

            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Resort Management System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    :root {
        --brand-primary: #0f5132;
        --brand-accent: #d4a537;
    }
    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0f5132 0%, #14532d 50%, #1c3d2e 100%);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }
    .login-card {
        width: 100%;
        max-width: 380px;
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.25);
        overflow: hidden;
    }
    .login-header {
        background: var(--brand-primary);
        color: #fff;
        padding: 28px 24px 22px;
        text-align: center;
    }
    .login-header .icon-circle {
        width: 56px;
        height: 56px;
        background: rgba(255,255,255,0.15);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
        font-size: 26px;
    }
    .login-header h4 { margin: 0; font-weight: 600; }
    .login-header small { opacity: 0.85; }
    .login-body { padding: 28px 28px 32px; }
    .btn-brand {
        background: var(--brand-primary);
        color: #fff;
        border: none;
    }
    .btn-brand:hover { background: #0c4128; color: #fff; }
    .form-control:focus {
        border-color: var(--brand-primary);
        box-shadow: 0 0 0 0.2rem rgba(15,81,50,0.15);
    }
</style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="icon-circle">🏨</div>
        <h4>Resort Management System</h4>
        <small>Hotel Module</small>
    </div>
    <div class="login-body">
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Username</label>
                <input type="text" name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-4">
                <label class="form-label small fw-semibold">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-brand w-100 fw-semibold py-2">Sign In</button>
        </form>
    </div>
</div>

</body>
</html>
