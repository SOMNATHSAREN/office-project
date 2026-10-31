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
$form = ['username' => $user['username'], 'first_name'=>$user['first_name']??'', 'middle_name'=>$user['middle_name']??'', 'last_name'=>$user['last_name']??'', 'email' => $user['email'] ?? '', 'role' => $user['role'], 'status' => $user['status'] ?? 'approved', 'password' => '', 'password2' => ''];
$sel_perms = parse_perms($user['permissions'] ?? '') ?: perms_for_role($user['role']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    $sel_perms = array_values(array_intersect((array)($_POST['perms'] ?? []), all_perms()));
    if (!$form['username']) $errors[] = 'Username is required.';
    if (!empty($form['email']) && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (!in_array($form['status'] ?? '', ['pending','approved','disabled'])) $form['status'] = 'approved';
    if (!in_array($form['role'], ['admin','manager','hr','custom'])) $errors[] = 'Invalid role.';
    if ($form['password'] && strlen($form['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($form['password'] !== $form['password2']) $errors[] = 'Passwords do not match.';
    if (!$sel_perms) $errors[] = 'Select at least one permission.';

    // Prevent removing the last user that can manage users
    $had_user_mgmt = in_array('user_mgmt', parse_perms($user['permissions'] ?? '') ?: perms_for_role($user['role']), true);
    if (!$errors && $had_user_mgmt && !in_array('user_mgmt', $sel_perms, true)) {
        $mgmt_count = 0;
        foreach ($db->query('SELECT role, permissions FROM users')->fetchAll() as $u) {
            $p = parse_perms($u['permissions'] ?? '') ?: perms_for_role($u['role']);
            if (in_array('user_mgmt', $p, true)) $mgmt_count++;
        }
        if ($mgmt_count <= 1) $errors[] = 'Cannot remove User Management from the last user who has it.';
    }

    if (!$errors) {
        try {
            $old_perms = parse_perms($user['permissions'] ?? '') ?: perms_for_role($user['role']);
            $diffs = [];
            if ($user['username'] !== $form['username']) $diffs[] = 'username: "' . $user['username'] . '" → "' . $form['username'] . '"';
            if (array_diff($old_perms, $sel_perms) || array_diff($sel_perms, $old_perms)) {
                $diffs[] = 'permissions: "' . implode(',', $old_perms) . '" → "' . implode(',', $sel_perms) . '"';
            }
            if ($form['password']) $diffs[] = 'password changed';

            $role_store  = $form['role'] === 'custom' ? $user['role'] : $form['role'];
            $email_store = $form['email'] !== '' ? $form['email'] : null;
            $fn = $form['first_name'] ?: null; $mn = $form['middle_name'] ?: null; $ln = $form['last_name'] ?: null;
            if ($form['password']) {
                $db->prepare('UPDATE users SET username=?, first_name=?, middle_name=?, last_name=?, email=?, role=?, permissions=?, status=?, password=? WHERE id=?')
                   ->execute([$form['username'], $fn, $mn, $ln, $email_store, $role_store, implode(',', $sel_perms), $form['status'],
                              password_hash($form['password'], PASSWORD_DEFAULT), $id]);
            } else {
                $db->prepare('UPDATE users SET username=?, first_name=?, middle_name=?, last_name=?, email=?, role=?, permissions=?, status=? WHERE id=?')
                   ->execute([$form['username'], $fn, $mn, $ln, $email_store, $role_store, implode(',', $sel_perms), $form['status'], $id]);
            }
            // Refresh session if editing self
            if ($id == $_SESSION['user_id']) {
                $_SESSION['username'] = $form['username'];
                $_SESSION['role']     = $role_store;
                $_SESSION['perms']    = $sel_perms;
            }
            // Clear any pending password-reset request once a new password is set (guarded for pre-migration DBs)
            if ($form['password']) {
                try { $db->prepare('UPDATE users SET reset_requested_at=NULL WHERE id=?')->execute([$id]); } catch (PDOException $e) {}
            }
            audit_log($db, 'user_edit', 'User updated: ' . $form['username'] . ($diffs ? ' | ' . implode('; ', $diffs) : ''));
            flash('success', 'User updated.' . ($form['password'] && !empty($user['reset_requested_at']) ? ' Password-reset request cleared.' : ''));
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
    <?php if (!empty($user['reset_requested_at'])): ?>
      <div class="alert alert-info py-2"><i class="bi bi-key me-1"></i>This user requested a password reset
        on <?= h(date('d M Y, H:i', strtotime($user['reset_requested_at']))) ?>.
        Set a new password below and share it with them; saving will clear the request.</div>
    <?php endif; ?>
    <form method="post" novalidate>
      <div class="row g-2 mb-3">
        <div class="col-4">
          <label class="form-label fw-semibold small">First Name</label>
          <input type="text" name="first_name" class="form-control" value="<?= h($form['first_name']) ?>">
        </div>
        <div class="col-4">
          <label class="form-label fw-semibold small">Middle</label>
          <input type="text" name="middle_name" class="form-control" value="<?= h($form['middle_name']) ?>">
        </div>
        <div class="col-4">
          <label class="form-label fw-semibold small">Last Name</label>
          <input type="text" name="last_name" class="form-control" value="<?= h($form['last_name']) ?>">
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Username (full name) <span class="text-danger">*</span></label>
          <input type="text" name="username" class="form-control" value="<?= h($form['username']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($form['email']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Account Status</label>
          <select name="status" class="form-select">
            <?php foreach (['approved'=>'Approved','pending'=>'Pending','disabled'=>'Disabled'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($form['status']??'approved')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Role Preset</label>
        <select name="role" id="rolePreset" class="form-select" onchange="applyPreset(this.value)">
          <option value="custom"  <?= !in_array($form['role'],['hr','manager','admin'])?'selected':'' ?>>Custom — choose below</option>
          <option value="hr"      <?= $form['role']==='hr'      ?'selected':'' ?>>HR — Employee full + Attendance view</option>
          <option value="manager" <?= $form['role']==='manager' ?'selected':'' ?>>Manager — Employee + Attendance full</option>
          <option value="admin"   <?= $form['role']==='admin'   ?'selected':'' ?>>Admin — Full access</option>
        </select>
        <div class="form-text">Pick a preset to tick boxes, or fine-tune permissions individually.</div>
        <?php if ($id == $_SESSION['user_id']): ?>
          <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>You are editing your own account.</div>
        <?php endif; ?>
      </div>

      <?php include __DIR__ . '/_perm_grid.php'; ?>
      <div class="mb-3" id="setpw">
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
