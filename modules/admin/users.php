<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_perm('user_mgmt');

$page_title = 'User Management';
$db = get_db();
$users = $db->query('SELECT id, username, role, created_at FROM users ORDER BY username')->fetchAll();

$role_badges = [
    'admin'   => ['danger',  'Admin'],
    'manager' => ['primary', 'Manager'],
    'hr'      => ['success', 'HR'],
];

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <a href="user_add.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus-fill me-1"></i>Add User</a>
</div>

<div class="card">
  <div class="card-header fw-semibold py-2"><i class="bi bi-people me-2"></i>System Users</div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th>Username</th>
          <th>Role</th>
          <th>Permissions</th>
          <th>Created</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $i => $u):
          [$bcol, $blabel] = $role_badges[$u['role']] ?? ['secondary', ucfirst($u['role'])];
          $perm_map = [
              'admin'   => 'All modules + Admin panel + User management',
              'manager' => 'Employees (full) + Attendance (full) — no admin panel',
              'hr'      => 'Employees (full) + Attendance (view only)',
          ];
        ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td class="fw-semibold"><i class="bi bi-person-circle me-1 text-muted"></i><?= h($u['username']) ?></td>
          <td><span class="badge bg-<?= $bcol ?>"><?= $blabel ?></span></td>
          <td class="text-muted small"><?= $perm_map[$u['role']] ?? '—' ?></td>
          <td class="text-muted small"><?= $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '—' ?></td>
          <td class="text-end">
            <a href="user_edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <a href="user_delete.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('Delete user <?= h($u['username']) ?>?')"><i class="bi bi-trash"></i></a>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete yourself"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-3 border-0 bg-light">
  <div class="card-body py-2 px-3">
    <div class="row g-3">
      <?php foreach ($role_badges as $role => [$bc, $bl]): ?>
      <div class="col-md-4">
        <span class="badge bg-<?= $bc ?> me-1"><?= $bl ?></span>
        <span class="small text-muted"><?= $perm_map[$role] ?? '' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
