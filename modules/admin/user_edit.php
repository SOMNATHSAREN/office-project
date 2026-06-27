<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_perm('user_mgmt');

$page_title = 'Edit User';
$db  = get_db();
$id  = (int)($_GET['id'] ?? 0);
$row = $db->prepare('SELECT * FROM users WHERE id=?');
$row->execute([$id]);
$user = $row->fetch();
if (!$user) { flash('error','User not found.'); redirect(BASE_URL.'/modules/admin/users.php'); }

$errors = [];
$form = ['username' => $user['username'], 'role' => $user['role'], 'password' => '', 'password2' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_map('trim', $_POST);
    if (!$form['username']) $errors[] = 'Username is required.';
    if (!in_array($form['role'], ['admin','manager','hr'])) $errors[] = 'Invalid role.';
    if ($form['password'] && strlen($form['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($form['password'] !== $form['password2']) $errors[] = 'Passwords do not match.';

    // Prevent removing last admin
    if ($user['role'] === 'admin' && $form['role'] !== 'admin') {
        $admin_count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($admin_count <= 1) $errors[] = 'Cannot remove the last Admin user.';
    }

    if (!$errors) {
        try {
            $diffs = [];
            if ($user['username'] !== $form['username']) $diffs[] = 'username: "' . $user['username'] . '" → "' . $form['username'] . '"';
            if ($user['role']     !== $form['role'])     $diffs[] = 'role: "' . $user['role'] . '" → "' . $form['role'] . '"';
            if ($form['password']) $diffs[] = 'password changed';

            if ($form['password']) {
                $db->prepare('UPDATE users SET username=?, role=?, password=? WHERE id=?')
                   ->execute([$form['username'], $form['role'], password_hash($form['password'], PASSWORD_DEFAULT), $id]);
            } else {
                $db->prepare('UPDATE users SET username=?, role=? WHERE id=?')
                   ->execute([$form['username'], $form['role'], $id]);
            }
            // Refresh session role if editing self
            if ($id == $_SESSION['user_id']) {
                $_SESSION['username'] = $form['username'];
                $_SESSION['role']     = $form['role'];
            }
            audit_log($db, 'user_edit', 'User updated: ' . $form['username'] . ($diffs ? ' | ' . implode('; ', $diffs) : ''));
            flash('success', 'User updated.');
            redirect(BASE_URL . '/modules/admin/users.php');
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Username already exists.' : 'Database error.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="card" style="max-width:480px;">
  <div class="card-header fw-semibold"><i class="bi bi-person-gear me-2"></i>Edit User: <?= h($user['username']) ?></div>
  <div class="card-body">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger py-2"><?= h($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" value="<?= h($form['username']) ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
        <select name="role" class="form-select">
          <option value="hr"      <?= $form['role']==='hr'      ?'selected':'' ?>>HR — Employee full + Attendance view only</option>
          <option value="manager" <?= $form['role']==='manager' ?'selected':'' ?>>Manager — Employee + Attendance full edit</option>
          <option value="admin"   <?= $form['role']==='admin'   ?'selected':'' ?>>Admin — Full access (all modules)</option>
        </select>
        <?php if ($id == $_SESSION['user_id']): ?>
          <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>You are editing your own account.</div>
        <?php endif; ?>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">New Password</label>
        <input type="password" name="password" class="form-control">
        <div class="form-text">Leave blank to keep current password. Minimum 6 characters if changing.</div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Confirm New Password</label>
        <input type="password" name="password2" class="form-control">
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">Update User</button>
        <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
