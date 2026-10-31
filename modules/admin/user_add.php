<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_perm('user_mgmt');

$page_title = 'Add User';
$errors = [];
$form = ['username' => '', 'role' => 'hr', 'password' => '', 'password2' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_map('trim', $_POST);
    if (!$form['username'])  $errors[] = 'Username is required.';
    if (!$form['password'])  $errors[] = 'Password is required.';
    if (strlen($form['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($form['password'] !== $form['password2']) $errors[] = 'Passwords do not match.';
    if (!in_array($form['role'], ['admin','manager','hr'])) $errors[] = 'Invalid role.';

    if (!$errors) {
        try {
            $db = get_db();
            $db->prepare('INSERT INTO users (username, password, role) VALUES (?,?,?)')
               ->execute([$form['username'], password_hash($form['password'], PASSWORD_DEFAULT), $form['role']]);
            audit_log($db, 'user_add', 'User added: ' . $form['username'] . ' | Role: ' . $form['role']);
            flash('success', 'User "' . $form['username'] . '" created successfully.');
            redirect(BASE_URL . '/modules/admin/users.php');
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Username already exists.' : 'Database error.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="card" style="max-width:480px;">
  <div class="card-header fw-semibold"><i class="bi bi-person-plus-fill me-2"></i>Add New User</div>
  <div class="card-body">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger py-2"><?= h($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" value="<?= h($form['username']) ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
        <select name="role" class="form-select">
          <option value="hr"      <?= $form['role']==='hr'      ?'selected':'' ?>>HR — Employee full + Attendance view only</option>
          <option value="manager" <?= $form['role']==='manager' ?'selected':'' ?>>Manager — Employee + Attendance full edit</option>
          <option value="admin"   <?= $form['role']==='admin'   ?'selected':'' ?>>Admin — Full access (all modules)</option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
        <input type="password" name="password" class="form-control" required>
        <div class="form-text">Minimum 6 characters.</div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
        <input type="password" name="password2" class="form-control" required>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">Create User</button>
        <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
