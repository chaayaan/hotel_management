<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hotel_helpers.php';
require_role(['admin', 'general_manager']);

$fields = [
    'resort_name'    => 'Resort Name',
    'resort_address' => 'Resort Address',
    'resort_phone'   => 'Resort Phone',
    'resort_email'   => 'Resort Email',
    'resort_website' => 'Resort Website',
    'wifi_name'      => 'Wi-Fi Name',
    'wifi_password'  => 'Wi-Fi Password',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: hotel_settings.php');
        exit;
    }

    foreach ($fields as $key => $label) {
        $value = trim($_POST[$key] ?? '');
        $stmt = mysqli_prepare($conn, "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        mysqli_stmt_execute($stmt);
    }

    flash_set('success', 'Settings updated successfully.');
    header('Location: hotel_settings.php');
    exit;
}

$values = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($row = mysqli_fetch_assoc($res)) $values[$row['setting_key']] = $row['setting_value'];

$page_title = 'Hotel Settings';
$active_menu = 'settings';
require __DIR__ . '/navbar.php';
?>

<div class="card" style="max-width:640px;">
    <div class="card-header"><i class="bi bi-gear me-1"></i> Resort & Wi-Fi Settings</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="row g-3">
                <?php foreach ($fields as $key => $label): ?>
                <div class="col-12">
                    <label class="form-label small fw-semibold"><?= e($label) ?></label>
                    <input type="text" name="<?= e($key) ?>" class="form-control" value="<?= e($values[$key] ?? '') ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-brand btn-sm px-4">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>