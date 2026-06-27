<?php
define('BASE_URL', rtrim(str_replace('/modules/employees','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$format = $_GET['format'] ?? 'html';
$employees = get_db()->query('SELECT * FROM employees ORDER BY name')->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="employees_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SAIL PERNO','Unit PERNO','Unit Code','Name','Department','Designation','Date of Joining','Status']);
    foreach ($employees as $e) {
        fputcsv($out, [$e['sail_perno'],$e['unit_perno'],$e['unit_cd'],$e['name'],
                       $e['department'],$e['designation'],$e['date_of_joining'],$e['status']]);
    }
    fclose($out);
    exit;
}

$page_title = 'Employee Report';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="mb-0 text-muted">Total: <?= count($employees) ?> employees</p>
  <a href="report.php?format=csv" class="btn btn-success btn-sm"><i class="bi bi-filetype-csv me-1"></i>Download CSV</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-bordered table-sm mb-0">
      <thead class="table-dark">
        <tr>
          <th>#</th><th>SAIL PERNO</th><th>Unit PERNO</th><th>Unit Cd</th>
          <th>Name</th><th>Department</th><th>Designation</th><th>DOJ</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $i => $e): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= h($e['sail_perno']) ?></td>
          <td><?= h($e['unit_perno']) ?></td>
          <td><?= h($e['unit_cd']) ?></td>
          <td><?= h($e['name']) ?></td>
          <td><?= h($e['department']) ?></td>
          <td><?= h($e['designation']) ?></td>
          <td><?= $e['date_of_joining'] ? date('d M Y',strtotime($e['date_of_joining'])) : '—' ?></td>
          <td><?= h($e['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
