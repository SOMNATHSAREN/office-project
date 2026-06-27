<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Attendance — View / Edit';
$db = get_db();

// Available years
$years = $db->query('SELECT DISTINCT SUBSTR(yyyymm,1,4) AS yr FROM attendance ORDER BY yr DESC')->fetchAll(PDO::FETCH_COLUMN);
$sel_year = (int)($_GET['year'] ?? ($years[0] ?? date('Y')));

// Months within selected year
$stmt = $db->prepare('SELECT DISTINCT yyyymm FROM attendance WHERE yyyymm LIKE ? ORDER BY yyyymm ASC');
$stmt->execute([$sel_year . '%']);
$months = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sel_month = $_GET['month'] ?? ($months[0] ?? '');
if ($sel_month && substr($sel_month, 0, 4) != $sel_year) $sel_month = $months[0] ?? '';

// Search filters
$search      = trim($_GET['search']      ?? '');
$filter_dept = trim($_GET['department']  ?? '');

// Departments for dropdown
$depts = $db->query('SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> "" ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);

$year_locked  = is_year_locked($db, $sel_year);
$month_locked = $sel_month ? is_month_locked($db, $sel_month) : false;
$locked       = $year_locked || $month_locked;

$records = [];
if ($sel_month) {
    $sql  = 'SELECT a.*, e.name, e.department, e.unit_perno AS emp_unit_perno
             FROM attendance a
             LEFT JOIN employees e ON e.sail_perno = a.sail_perno
             WHERE a.yyyymm = ?';
    $args = [$sel_month];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (a.sail_perno LIKE ? OR a.unit_perno LIKE ? OR e.name LIKE ?)';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    if ($filter_dept !== '') {
        $sql .= ' AND e.department = ?';
        $args[] = $filter_dept;
    }

    $sql .= ' ORDER BY e.name, a.sail_perno';
    $s = $db->prepare($sql);
    $s->execute($args);
    $records = $s->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" id="filterForm" class="row g-2 align-items-end">

      <!-- Year -->
      <div class="col-auto">
        <label class="form-label mb-1 small fw-semibold">Year</label>
        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= h($y) ?>" <?= (int)$y===$sel_year?'selected':'' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Month -->
      <div class="col-auto">
        <label class="form-label mb-1 small fw-semibold">Month</label>
        <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>" <?= $m===$sel_month?'selected':'' ?>><?= month_label($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Search -->
      <div class="col-md-3">
        <label class="form-label mb-1 small fw-semibold">Search</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="SAIL PERNO / Unit PERNO / Name"
                 value="<?= h($search) ?>">
        </div>
      </div>

      <!-- Department -->
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-semibold">Department</label>
        <select name="department" class="form-select form-select-sm">
          <option value="">All Depts</option>
          <?php foreach ($depts as $d): ?>
            <option value="<?= h($d) ?>" <?= $filter_dept===$d?'selected':'' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
        <?php if ($search || $filter_dept): ?>
          <a href="?year=<?= $sel_year ?>&month=<?= h($sel_month) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle me-1"></i>Clear
          </a>
        <?php endif; ?>
      </div>

      <!-- Lock badge + actions pushed right -->
      <div class="col-auto ms-auto d-flex align-items-center gap-2">
        <?php if ($year_locked): ?>
          <span class="badge bg-danger fs-6"><i class="bi bi-lock-fill me-1"></i><?= $sel_year ?> Year Locked</span>
        <?php elseif ($month_locked): ?>
          <span class="badge bg-warning text-dark fs-6"><i class="bi bi-lock-fill me-1"></i><?= month_label($sel_month) ?> Locked</span>
        <?php else: ?>
          <span class="badge bg-success fs-6"><i class="bi bi-unlock me-1"></i>Open</span>
        <?php endif; ?>
        <?php if ($sel_month): ?>
          <a href="report.php?month=<?= h($sel_month) ?>&format=csv" class="btn btn-outline-success btn-sm">
            <i class="bi bi-filetype-csv me-1"></i>CSV
          </a>
        <?php endif; ?>
        <a href="year_lock.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-shield-lock me-1"></i>Year Locks
        </a>
      </div>

    </form>
  </div>
</div>

<?php if ($locked): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
    <i class="bi bi-lock-fill fs-5"></i>
    <span>
      <?php if ($year_locked): ?>
        Year <strong><?= $sel_year ?></strong> is locked.
      <?php else: ?>
        <strong><?= month_label($sel_month) ?></strong> is locked.
      <?php endif; ?>
      Records are read-only and cannot be edited.
      <a href="year_lock.php" class="alert-link ms-2">Manage Locks</a>
    </span>
  </div>
<?php endif; ?>

<!-- Result count when filtering -->
<?php if (($search || $filter_dept) && $sel_month): ?>
  <div class="mb-2 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    <?= count($records) ?> record<?= count($records)!=1?'s':'' ?> found
    in <?= month_label($sel_month) ?>
    <?= $search ? ' matching <strong>' . h($search) . '</strong>' : '' ?>
    <?= $filter_dept ? ' in dept <strong>' . h($filter_dept) . '</strong>' : '' ?>
  </div>
<?php endif; ?>

<?php if ($records): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center py-2">
    <span class="fw-semibold"><i class="bi bi-calendar3 me-2"></i><?= month_label($sel_month) ?></span>
    <span class="badge bg-secondary"><?= count($records) ?> employee<?= count($records)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-bordered table-sm mb-0 align-middle" style="font-size:.82rem;">
      <thead class="table-dark">
        <tr>
          <th>SAIL PERNO</th>
          <th>Unit PERNO</th>
          <th>Name</th>
          <th>Department</th>
          <th>Duty</th><th>W/Off</th>
          <th>CL</th><th>EL</th><th>HPL</th><th>FL/COFF</th>
          <th>ABS</th><th>COM</th><th>EOL</th><th>Others</th>
          <th>CL Bal</th><th>EL Bal</th><th>HPL Bal</th><th>FL Bal</th>
          <?php if (!$locked): ?><th>Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <td><code><?= h($r['sail_perno']) ?></code></td>
          <td><code class="text-muted"><?= h($r['unit_perno'] ?? '—') ?></code></td>
          <td><?= h($r['name'] ?? '—') ?></td>
          <td class="text-muted"><?= h($r['department'] ?? '—') ?></td>
          <td><?= h($r['duty']) ?></td>
          <td><?= h($r['w_off']) ?></td>
          <td><?= h($r['cl']) ?></td>
          <td><?= h($r['el']) ?></td>
          <td><?= h($r['hpl']) ?></td>
          <td><?= h($r['fl_coff']) ?></td>
          <td class="<?= $r['abs'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= h($r['abs']) ?></td>
          <td><?= h($r['com']) ?></td>
          <td><?= h($r['eol']) ?></td>
          <td><?= h($r['others']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['cl_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['el_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['hpl_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['fl_blns']) ?></td>
          <?php if (!$locked && can_do('att_write')): ?>
          <td>
            <a href="edit.php?id=<?= $r['id'] ?>&month=<?= h($sel_month) ?>"
               class="btn btn-outline-primary btn-sm" style="font-size:.75rem;padding:2px 8px;">
              <i class="bi bi-pencil"></i>
            </a>
          </td>
          <?php elseif (!$locked): ?>
          <td></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php elseif ($sel_month && ($search || $filter_dept)): ?>
  <div class="alert alert-warning">
    <i class="bi bi-search me-2"></i>No records match your search in <?= month_label($sel_month) ?>.
    <a href="?year=<?= $sel_year ?>&month=<?= h($sel_month) ?>" class="alert-link ms-2">Clear filters</a>
  </div>
<?php elseif ($sel_month): ?>
  <div class="alert alert-info">No records found for <?= month_label($sel_month) ?>.</div>
<?php elseif ($years): ?>
  <div class="alert alert-info">No attendance data for <?= $sel_year ?>.</div>
<?php else: ?>
  <div class="alert alert-warning">No attendance data uploaded yet. <a href="upload.php">Upload now</a>.</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
