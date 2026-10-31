<?php
define('BASE_URL', rtrim(str_replace('/modules/account','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Change Password';
$db = get_db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur   = $_POST['current'] ?? '';
    $new   = $_POST['new'] ?? '';
    $new2  = $_POST['new2'] ?? '';

    $row = $db->prepare('SELECT password FROM users WHERE id=?');
    $row->execute([$_SESSION['user_id']]);
    $hash = $row->fetchColumn();

    if (!$hash || !password_verify($cur, $hash)) $errors[] = 'Current password is incorrect.';
    if (strlen($new) < 6)  $errors[] = 'New password must be at least 6 characters.';
    if ($new !== $new2)    $errors[] = 'New passwords do not match.';

    if (!$errors) {
        $db->prepare('UPDATE users SET password=? WHERE id=?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        // Clear any pending reset request (guarded for pre-migration DBs)
        try { $db->prepare('UPDATE users SET reset_requested_at=NULL WHERE id=?')->execute([$_SESSION['user_id']]); } catch (PDOException $e) {}
        audit_log($db, 'user_pwd', 'User changed own password: ' . ($_SESSION['username'] ?? ''));
        flash('success', 'Your password has been updated.');
        redirect(BASE_URL . '/modules/account/change_password.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?>
<div class="card" style="max-width:460px;">
  <div class="card-header fw-semibold"><i class="bi bi-key me-2"></i>Change My Password</div>
  <div class="card-body">
    <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label fw-semibold">Current Password</label>
        <input type="password" name="current" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">New Password</label>
        <input type="password" name="new" class="form-control" required>
        <div class="form-text">Minimum 6 characters.</div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Confirm New Password</label>
        <input type="password" name="new2" class="form-control" required>
      </div>
      <button class="btn btn-primary">Update Password</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
