<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('admin_panel');
$page_title = 'Audit Log';
$db = get_db();

$employees = $db->query('SELECT sail_perno, name FROM employees ORDER BY name')->fetchAll();

$filter_user   = trim($_GET['username']  ?? '');
$filter_action = trim($_GET['action']    ?? '');
$filter_perno  = trim($_GET['perno']     ?? '');
$filter_date   = trim($_GET['date']      ?? '');

$actions = ['upload','edit','supp_add','supp_apply','supp_cancel','rectify','ledger_credit','lock','unlock','emp_add','emp_edit','emp_delete','user_add','user_edit','user_delete'];

$sql  = 'SELECT a.*, e.name AS emp_name FROM audit_log a LEFT JOIN employees e ON e.sail_perno=a.sail_perno WHERE 1=1';
$args = [];
if ($filter_user)   { $sql .= ' AND a.username=?';    $args[] = $filter_user; }
if ($filter_action) { $sql .= ' AND a.action=?';      $args[] = $filter_action; }
if ($filter_perno)  { $sql .= ' AND a.sail_perno=?';  $args[] = $filter_perno; }
if ($filter_date)   { $sql .= ' AND DATE(a.created_at)=?'; $args[] = $filter_date; }
$sql .= ' ORDER BY a.created_at DESC LIMIT 500';

$stmt = $db->prepare($sql); $stmt->execute($args);
$logs = $stmt->fetchAll();

// Distinct users for filter dropdown
$users = $db->query('SELECT DISTINCT username FROM audit_log ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','Date/Time','Action','SAIL PERNO','Employee','Month','User','IP','Description']);
    foreach ($logs as $i => $l) {
        fputcsv($out, [
            $i+1,
            date('d-m-Y H:i:s', strtotime($l['created_at'])),
            $l['action'],
            $l['sail_perno'],
            $l['emp_name'] ?? '',
            $l['yyyymm'] ? month_label($l['yyyymm']) : '',
            $l['username'],
            $l['ip_address'],
            $l['description'],
        ]);
    }
    fclose($out); exit;
}

$action_badges = [
    'upload'        => ['primary',  'Upload'],
    'edit'          => ['warning',  'Att Edit'],
    'supp_add'      => ['info',     'Supp Add'],
    'supp_apply'    => ['success',  'Supp Apply'],
    'supp_cancel'   => ['secondary','Supp Cancel'],
    'rectify'       => ['warning',  'Rectify'],
    'ledger_credit' => ['info',     'Ledger Credit'],
    'lock'          => ['danger',   'Lock'],
    'unlock'        => ['success',  'Unlock'],
    'emp_add'       => ['success',  'Emp Add'],
    'emp_edit'      => ['warning',  'Emp Edit'],
    'emp_delete'    => ['danger',   'Emp Delete'],
    'user_add'      => ['success',  'User Add'],
    'user_edit'     => ['warning',  'User Edit'],
    'user_delete'   => ['danger',   'User Delete'],
];

include __DIR__ . '/../../includes/header.php';
?>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-semibold">User</label>
        <select name="username" class="form-select form-select-sm">
          <option value="">All Users</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= h($u) ?>" <?= $filter_user===$u?'selected':'' ?>><?= h($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-semibold">Action</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">All Actions</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= $a ?>" <?= $filter_action===$a?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$a)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 small fw-semibold">Employee</label>
        <select name="perno" class="form-select form-select-sm">
          <option value="">All Employees</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= h($emp['sail_perno']) ?>" <?= $filter_perno===$emp['sail_perno']?'selected':'' ?>>
              <?= h($emp['name']) ?> (<?= h($emp['sail_perno']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-semibold">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= h($filter_date) ?>">
      </div>
      <div class="col-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
        <?php if ($filter_user || $filter_action || $filter_perno || $filter_date): ?>
          <a href="audit_log.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i>Clear</a>
        <?php endif; ?>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>1])) ?>" class="btn btn-outline-success btn-sm">
          <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between py-2">
    <span class="fw-semibold"><i class="bi bi-shield-check me-2"></i>Audit Log</span>
    <span class="badge bg-secondary"><?= count($logs) ?> record<?= count($logs)!=1?'s':'' ?> (last 500)</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th>Date / Time</th>
          <th>Action</th>
          <th>SAIL PERNO</th>
          <th>Employee</th>
          <th>Month</th>
          <th>User</th>
          <th>IP Address</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No audit records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $i => $l):
          [$bcol, $blabel] = $action_badges[$l['action']] ?? ['secondary', $l['action']];
        ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td class="text-nowrap"><?= date('d M Y H:i:s', strtotime($l['created_at'])) ?></td>
          <td><span class="badge bg-<?= $bcol ?>"><?= $blabel ?></span></td>
          <td><?= $l['sail_perno'] ? '<code>' . h($l['sail_perno']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= h($l['emp_name'] ?? '—') ?></td>
          <td><?= $l['yyyymm'] ? month_label($l['yyyymm']) : '—' ?></td>
          <td><span class="badge bg-dark"><?= h($l['username']) ?></span></td>
          <td class="text-muted small"><?= h($l['ip_address'] ?: '—') ?></td>
          <td class="text-muted"><?= h($l['description']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
