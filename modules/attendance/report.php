<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$month  = $_GET['month']  ?? '';
$format = $_GET['format'] ?? 'html';

if (!$month) { flash('error','Month not specified.'); redirect(BASE_URL.'/modules/attendance/index.php'); }

$stmt = get_db()->prepare(
    'SELECT a.*, e.name, e.department, e.designation FROM attendance a
     LEFT JOIN employees e ON e.sail_perno = a.sail_perno
     WHERE a.yyyymm = ? ORDER BY e.name, a.sail_perno');
$stmt->execute([$month]);
$records = $stmt->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $month . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['SAIL_PERNO','Name','Department','Designation','Duty','W_Off','CL','EL','HPL','FL_COFF',
                  'ABS','COM','EOL','Others','Night','Injury','CL_Bal','EL_Bal','HPL_Bal','FL_Bal']);
    foreach ($records as $r) {
        fputcsv($out,[$r['sail_perno'],$r['name'],$r['department'],$r['designation'],
                      $r['duty'],$r['w_off'],$r['cl'],$r['el'],$r['hpl'],$r['fl_coff'],
                      $r['abs'],$r['com'],$r['eol'],$r['others'],$r['night_shift'],$r['injury_lv'],
                      $r['cl_blns'],$r['el_blns'],$r['hpl_blns'],$r['fl_blns']]);
    }
    fclose($out); exit;
}

$page_title = 'Attendance Report — ' . month_label($month);
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between mb-3">
  <p class="mb-0 text-muted"><?= count($records) ?> records for <?= month_label($month) ?></p>
  <a href="report.php?month=<?= h($month) ?>&format=csv" class="btn btn-success btn-sm">
    <i class="bi bi-filetype-csv me-1"></i>Download CSV
  </a>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-bordered table-sm mb-0" style="font-size:.8rem;">
      <thead class="table-dark">
        <tr>
          <th>PERNO</th><th>Name</th><th>Dept</th>
          <th>Duty</th><th>W/Off</th><th>CL</th><th>EL</th><th>HPL</th><th>FL</th>
          <th>ABS</th><th>COM</th><th>EOL</th>
          <th>CL Bal</th><th>EL Bal</th><th>HPL Bal</th><th>FL Bal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <td><?= h($r['sail_perno']) ?></td>
          <td><?= h($r['name']??'—') ?></td>
          <td><?= h($r['department']??'—') ?></td>
          <td><?= h($r['duty']) ?></td><td><?= h($r['w_off']) ?></td>
          <td><?= h($r['cl']) ?></td><td><?= h($r['el']) ?></td>
          <td><?= h($r['hpl']) ?></td><td><?= h($r['fl_coff']) ?></td>
          <td><?= h($r['abs']) ?></td><td><?= h($r['com']) ?></td>
          <td><?= h($r['eol']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['cl_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['el_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['hpl_blns']) ?></td>
          <td class="fw-semibold text-success"><?= h($r['fl_blns']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
