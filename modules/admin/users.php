<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_perm('user_mgmt');

$page_title = 'User Management';
$db = get_db();
$users = $db->query('SELECT id, username, email, role, permissions, status, created_at FROM users ORDER BY (status="pending") DESC, username')->fetchAll();
$pending_count = 0; foreach ($users as $u) if (($u['status'] ?? '')==='pending') $pending_count++;

// Password-reset requests (guarded — column may not exist before migrate_password_reset.sql is run)
$reset_ids = [];
try {
    foreach ($db->query('SELECT id FROM users WHERE reset_requested_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $reset_ids[(int)$rid] = true;
    }
} catch (PDOException $e) { /* migration not run yet */ }
$reset_count = count($reset_ids);

$role_badges = [
    'admin'   => ['danger',  'Admin'],
    'manager' => ['primary', 'Manager'],
    'hr'      => ['success', 'HR'],
];

// Short module-level summary from a permission set
function perm_summary(array $perms): string {
    $mods = [];
    if (array_intersect($perms, ['emp_view','emp_write']))                $mods[] = 'Employees' . (in_array('emp_write',$perms)?'✏':'👁');
    if (array_intersect($perms, ['att_view','att_write','att_lock']))     $mods[] = 'Attendance' . (in_array('att_write',$perms)||in_array('att_lock',$perms)?'✏':'👁');
    if (array_intersect($perms, ['ccs_view','ccs_write','loan_approve']))  $mods[] = 'Society' . (in_array('ccs_write',$perms)||in_array('loan_approve',$perms)?'✏':'👁');
    if (array_intersect($perms, ['admin_panel','user_mgmt']))             $mods[] = 'Admin';
    return $mods ? implode(' · ', $mods) : '<span class="text-muted">No access</span>';
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <?php if ($pending_count): ?>
      <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i><?= $pending_count ?> pending approval</span>
    <?php endif; ?>
    <?php if ($reset_count): ?>
      <span class="badge bg-info text-dark"><i class="bi bi-key me-1"></i><?= $reset_count ?> password reset<?= $reset_count>1?'s':'' ?> requested</span>
    <?php endif; ?>
  </div>
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
          <th>Email</th>
          <th>Role</th>
          <th>Permissions</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $i => $u):
          $u_perms = parse_perms($u['permissions'] ?? '') ?: perms_for_role($u['role']);
          // Label by closest preset, else Custom
          $preset = 'custom';
          foreach (['admin','manager','hr'] as $r) {
              $rp = perms_for_role($r);
              if (!array_diff($rp, $u_perms) && !array_diff($u_perms, $rp)) { $preset = $r; break; }
          }
          [$bcol, $blabel] = $role_badges[$preset] ?? ['secondary', 'Custom'];
          $st = $u['status'] ?? 'approved';
          $st_badge = ['pending'=>'warning text-dark','approved'=>'success','disabled'=>'secondary'][$st] ?? 'secondary';
        ?>
        <?php $needs_reset = !empty($reset_ids[(int)$u['id']]); ?>
        <tr class="<?= $st==='pending'?'table-warning':($needs_reset?'table-info':'') ?>">
          <td class="text-muted"><?= $i+1 ?></td>
          <td class="fw-semibold"><i class="bi bi-person-circle me-1 text-muted"></i><?= h($u['username']) ?></td>
          <td class="text-muted small"><?= h($u['email'] ?? '—') ?></td>
          <td><span class="badge bg-<?= $bcol ?>"><?= $blabel ?></span></td>
          <td class="text-muted small"><?= perm_summary($u_perms) ?></td>
          <td>
            <span class="badge bg-<?= $st_badge ?>"><?= ucfirst($st) ?></span>
            <?php if ($needs_reset): ?><span class="badge bg-info text-dark" title="This user requested a password reset"><i class="bi bi-key"></i> reset</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($st === 'pending'): ?>
              <a href="user_approve.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Approve</a>
            <?php endif; ?>
            <?php if ($needs_reset): ?>
              <a href="user_edit.php?id=<?= $u['id'] ?>#setpw" class="btn btn-sm btn-info text-dark" title="Set a new password"><i class="bi bi-key me-1"></i>Reset</a>
            <?php endif; ?>
            <a href="user_edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit / permissions"><i class="bi bi-pencil"></i></a>
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
    <div class="row g-3 small text-muted">
      <div class="col-md-4"><span class="badge bg-success me-1">HR</span> Employees full + Attendance view</div>
      <div class="col-md-4"><span class="badge bg-primary me-1">Manager</span> Employees + Attendance full</div>
      <div class="col-md-4"><span class="badge bg-danger me-1">Admin</span> Full access (all modules)</div>
      <div class="col-12 mt-1"><span class="badge bg-secondary me-1">Custom</span> Any hand-picked mix of module permissions. 👁 = view · ✏ = edit</div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
