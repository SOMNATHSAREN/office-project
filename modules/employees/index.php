<?php
define('BASE_URL', rtrim(str_replace('/modules/employees','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Employees';
$search = trim($_GET['q'] ?? '');
$dept   = trim($_GET['dept'] ?? '');

$where = ['1=1'];
$params = [];
if ($search) { $where[] = '(name LIKE ? OR sail_perno LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($dept)   { $where[] = 'department = ?'; $params[] = $dept; }

$sql = 'SELECT * FROM employees WHERE ' . implode(' AND ', $where) . ' ORDER BY name';
$stmt = get_db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$depts = get_db()->query('SELECT DISTINCT department FROM employees WHERE department IS NOT NULL ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <div class="d-flex gap-2">
    <a href="report.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Download Report</a>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Employee</a>
  </div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name or PERNO…" value="<?= h($search) ?>">
      </div>
      <div class="col-md-4">
        <select name="dept" class="form-select form-select-sm">
          <option value="">All Departments</option>
          <?php foreach ($depts as $d): ?>
            <option value="<?= h($d) ?>" <?= $dept===$d?'selected':'' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">Filter</button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">Clear</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>SAIL PERNO</th>
          <th>Name</th>
          <th>Department</th>
          <th>Designation</th>
          <th>DOJ</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$employees): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No employees found.</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $i => $e): ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td><code><?= h($e['sail_perno']) ?></code></td>
          <td class="fw-semibold"><?= h($e['name']) ?></td>
          <td><?= h($e['department']) ?></td>
          <td><?= h($e['designation']) ?></td>
          <td><?= $e['date_of_joining'] ? date('d M Y', strtotime($e['date_of_joining'])) : '—' ?></td>
          <td>
            <?php if ($e['status'] === 'active'): ?>
              <span class="badge bg-success-subtle text-success">Active</span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="edit.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <a href="delete.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger"
               onclick="return confirm('Delete <?= h($e['name']) ?>?')"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-2"><?= count($employees) ?> employee(s) found.</p>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
