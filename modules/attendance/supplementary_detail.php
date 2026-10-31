<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Supplementary Attendance Details';
$db = get_db();

$employees = $db->query('SELECT sail_perno, name FROM employees WHERE status="active" ORDER BY name')->fetchAll();

// Years from both tables
$yr_supp = $db->query('SELECT DISTINCT LEFT(absence_yyyymm,4) AS yr FROM supplementary_attendance')->fetchAll(PDO::FETCH_COLUMN);
$yr_rect = $db->query('SELECT DISTINCT LEFT(rectify_yyyymm,4) AS yr FROM supplementary_rectify_log')->fetchAll(PDO::FETCH_COLUMN);
$years   = array_values(array_unique(array_merge($yr_supp, $yr_rect)));
rsort($years);
if (!$years) $years = [date('Y')];

$filter_perno = $_GET['perno'] ?? '';
$filter_year  = $_GET['year']  ?? $years[0];
$filter_tab   = $_GET['tab']   ?? 'all';   // all | sanctions | rectifications

// ── Leave sanctions ────────────────────────────────────────────────────────────
$s_sql  = 'SELECT s.*, e.name AS emp_name FROM supplementary_attendance s
            LEFT JOIN employees e ON e.sail_perno=s.sail_perno
            WHERE LEFT(s.absence_yyyymm,4)=?';
$s_args = [$filter_year];
if ($filter_perno) { $s_sql .= ' AND s.sail_perno=?'; $s_args[] = $filter_perno; }
$s_sql .= ' ORDER BY s.absence_yyyymm ASC, e.name ASC';
$s_stmt = $db->prepare($s_sql); $s_stmt->execute($s_args);
$sanctions = $s_stmt->fetchAll();

// ── Rectifications ─────────────────────────────────────────────────────────────
$r_sql  = 'SELECT r.*, e.name AS emp_name FROM supplementary_rectify_log r
            LEFT JOIN employees e ON e.sail_perno=r.sail_perno
            WHERE LEFT(r.rectify_yyyymm,4)=?';
$r_args = [$filter_year];
if ($filter_perno) { $r_sql .= ' AND r.sail_perno=?'; $r_args[] = $filter_perno; }
$r_sql .= ' ORDER BY r.rectify_yyyymm ASC, e.name ASC';
$r_stmt = $db->prepare($r_sql); $r_stmt->execute($r_args);
$rectifications = $r_stmt->fetchAll();

// ── CSV export ─────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="supp_' . $type . '_' . $filter_year . '.csv"');
    $out = fopen('php://output', 'w');

    if ($type === 'sanctions') {
        fputcsv($out, ['#','SAIL PERNO','Employee','Absence Month','Sanction Month','Leave Type','Days','Remarks','Status','Applied At']);
        foreach ($sanctions as $i => $r) {
            fputcsv($out, [$i+1, $r['sail_perno'], $r['emp_name'], month_label($r['absence_yyyymm']),
                month_label($r['sanction_yyyymm']), $r['leave_type'], $r['days'], $r['remarks'],
                strtoupper($r['status']), $r['applied_at'] ? date('d-m-Y H:i', strtotime($r['applied_at'])) : '']);
        }
    } else {
        $att_fields = ['duty','w_off','c_nhday','c_off','cl','fl_coff','el','hpl','com','eol','abs','others',
                       'night_shift','injury_lv','ex_grat','unaccounted','exwage','local_trg','scl_bd','scl','ccl',
                       'short_hrs','el_blns','hpl_blns','cl_blns','fl_blns'];
        $ldg_fields = ['cl_open','el_open','hpl_open','fl_open',
                       'cl_credit','el_credit','hpl_credit','fl_credit',
                       'cl_availed','el_availed','hpl_availed','fl_availed','com_availed',
                       'cl_close','el_close','hpl_close','fl_close'];
        fputcsv($out, array_merge(
            ['#','SAIL PERNO','Employee','Absence Month','Sanction Month','Remarks','Changed By','Date'],
            array_map(fn($f) => "Att Before:$f",    $att_fields),
            array_map(fn($f) => "Att After:$f",     $att_fields),
            array_map(fn($f) => "Ledger Before:$f", $ldg_fields),
            array_map(fn($f) => "Ledger After:$f",  $ldg_fields)
        ));
        foreach ($rectifications as $i => $r) {
            $ba = json_decode($r['before_data'],   true) ?? [];
            $aa = json_decode($r['after_data'],    true) ?? [];
            $bl = json_decode($r['before_ledger'] ?? '{}', true) ?? [];
            $al = json_decode($r['after_ledger']  ?? '{}', true) ?? [];
            fputcsv($out, array_merge(
                [$i+1, $r['sail_perno'], $r['emp_name'], month_label($r['rectify_yyyymm']),
                 month_label($r['sanction_yyyymm']), $r['remarks'], $r['created_by'],
                 date('d-m-Y H:i', strtotime($r['created_at']))],
                array_map(fn($f) => $ba[$f] ?? 0, $att_fields),
                array_map(fn($f) => $aa[$f] ?? 0, $att_fields),
                array_map(fn($f) => $bl[$f] ?? '', $ldg_fields),
                array_map(fn($f) => $al[$f] ?? '', $ldg_fields)
            ));
        }
    }
    fclose($out); exit;
}

$labels_short = ['duty'=>'Duty','w_off'=>'W/Off','c_nhday'=>'NH','c_off'=>'C/Off',
                 'cl'=>'CL','fl_coff'=>'FL','el'=>'EL','hpl'=>'HPL','com'=>'COM','eol'=>'EOL',
                 'abs'=>'ABS','others'=>'Others','night_shift'=>'Night','injury_lv'=>'Injury',
                 'ex_grat'=>'ExGrat','unaccounted'=>'Unaccnt','exwage'=>'ExWage','local_trg'=>'LclTrg',
                 'scl_bd'=>'SCL-BD','scl'=>'SCL','ccl'=>'CCL','short_hrs'=>'ShortHrs',
                 'el_blns'=>'EL Bal','hpl_blns'=>'HPL Bal','cl_blns'=>'CL Bal','fl_blns'=>'FL Bal'];

include __DIR__ . '/../../includes/header.php';
?>

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label mb-1 small fw-semibold">Year</label>
        <select name="year" class="form-select form-select-sm">
          <?php foreach ($years as $yr): ?>
            <option value="<?= $yr ?>" <?= $filter_year==$yr?'selected':'' ?>><?= $yr ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
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
        <label class="form-label mb-1 small fw-semibold">View</label>
        <select name="tab" class="form-select form-select-sm">
          <option value="all"            <?= $filter_tab==='all'?'selected':'' ?>>All Activity</option>
          <option value="sanctions"      <?= $filter_tab==='sanctions'?'selected':'' ?>>Leave Sanctions</option>
          <option value="rectifications" <?= $filter_tab==='rectifications'?'selected':'' ?>>Rectifications</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm">Filter</button>
        <?php if ($filter_tab !== 'rectifications'): ?>
          <a href="?<?= http_build_query(array_merge($_GET,['export'=>'sanctions'])) ?>"
             class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>CSV Sanctions</a>
        <?php endif; ?>
        <?php if ($filter_tab !== 'sanctions'): ?>
          <a href="?<?= http_build_query(array_merge($_GET,['export'=>'rectifications'])) ?>"
             class="btn btn-outline-warning btn-sm"><i class="bi bi-download me-1"></i>CSV Rectify</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Summary strip -->
<?php
$applied_totals = [];
foreach ($sanctions as $s) {
    if ($s['status'] === 'applied') {
        $applied_totals[$s['leave_type']] = ($applied_totals[$s['leave_type']] ?? 0) + (float)$s['days'];
    }
}
$total_rects = count($rectifications);
?>
<?php if ($applied_totals || $total_rects): ?>
<div class="row g-2 mb-3">
  <?php $badge_map = ['CL'=>'primary','EL'=>'info','COM'=>'warning','HPL'=>'secondary','SCL'=>'dark','EXWAGE'=>'success'];
  foreach ($applied_totals as $type => $tot):
    $col = $badge_map[$type] ?? 'secondary'; ?>
  <div class="col-auto">
    <div class="card border-0 bg-<?= $col ?> bg-opacity-10 text-center px-3 py-2">
      <div class="fw-bold text-<?= $col ?> small"><?= $type ?></div>
      <div class="fs-6 fw-semibold"><?= number_format($tot,2) ?> days</div>
      <?php if ($type==='COM'): ?><div class="small text-danger">−<?= number_format($tot*2,0) ?> HPL</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($total_rects): ?>
  <div class="col-auto">
    <div class="card border-0 bg-warning bg-opacity-10 text-center px-3 py-2">
      <div class="fw-bold text-warning small">Rectifications</div>
      <div class="fs-6 fw-semibold"><?= $total_rects ?></div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Leave Sanctions table ──────────────────────────────────────────────── -->
<?php if ($filter_tab !== 'rectifications'): ?>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between py-2">
    <span class="fw-semibold"><i class="bi bi-patch-plus me-2 text-success"></i>Leave Sanctions — <?= h($filter_year) ?></span>
    <span class="badge bg-secondary"><?= count($sanctions) ?> record<?= count($sanctions)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
      <thead class="table-dark">
        <tr>
          <th>#</th><th>SAIL PERNO</th><th>Employee</th>
          <th>Absence Month</th><th>Sanction Month</th>
          <th>Leave Type</th><th class="text-end">Days</th>
          <th>Remarks</th><th>Status</th><th>Applied On</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$sanctions): ?>
          <tr><td colspan="10" class="text-center text-muted py-3">No leave sanctions found.</td></tr>
        <?php endif; ?>
        <?php foreach ($sanctions as $i => $r):
          $bc = $badge_map[$r['leave_type']] ?? 'secondary';
          $row_cls = $r['status']==='cancelled' ? 'text-decoration-line-through text-muted' : '';
        ?>
        <tr class="<?= $row_cls ?>">
          <td class="text-muted"><?= $i+1 ?></td>
          <td><code><?= h($r['sail_perno']) ?></code></td>
          <td><?= h($r['emp_name'] ?? '—') ?></td>
          <td><?= month_label($r['absence_yyyymm']) ?></td>
          <td><?= month_label($r['sanction_yyyymm']) ?></td>
          <td><span class="badge bg-<?= $bc ?>"><?= h($r['leave_type']) ?></span></td>
          <td class="text-end fw-semibold">
            <?= number_format((float)$r['days'],2) ?>
            <?php if ($r['leave_type']==='COM'): ?>
              <br><span class="text-danger fw-normal" style="font-size:.7rem;">−<?= (int)($r['days']*2) ?> HPL</span>
            <?php elseif ($r['leave_type']==='EXWAGE'): ?>
              <br><span class="text-muted fw-normal" style="font-size:.7rem;">exwage days</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h($r['remarks'] ?: '—') ?></td>
          <td>
            <?php if ($r['status']==='applied'): ?>
              <span class="badge bg-success">Applied</span>
            <?php elseif ($r['status']==='pending'): ?>
              <span class="badge bg-warning text-dark">Pending</span>
            <?php else: ?>
              <span class="badge bg-secondary">Cancelled</span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= $r['applied_at'] ? date('d M Y', strtotime($r['applied_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Rectification log table ────────────────────────────────────────────── -->
<?php if ($filter_tab !== 'sanctions'): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between py-2">
    <span class="fw-semibold"><i class="bi bi-pencil-square me-2 text-warning"></i>Rectification Log — <?= h($filter_year) ?></span>
    <span class="badge bg-secondary"><?= count($rectifications) ?> record<?= count($rectifications)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
      <thead class="table-dark">
        <tr>
          <th>#</th><th>SAIL PERNO</th><th>Employee</th>
          <th>Absence Month</th><th>Sanctioned In</th>
          <th>Changes (Before → After)</th>
          <th>Remarks</th><th>By</th><th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rectifications): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">No rectifications found.</td></tr>
        <?php endif; ?>
        <?php
        $ldg_labels = [
            'cl_open'=>'CL Open','el_open'=>'EL Open','hpl_open'=>'HPL Open','fl_open'=>'FL Open',
            'cl_credit'=>'CL Crd','el_credit'=>'EL Crd','hpl_credit'=>'HPL Crd','fl_credit'=>'FL Crd',
            'cl_availed'=>'CL Avl','el_availed'=>'EL Avl','hpl_availed'=>'HPL Avl','fl_availed'=>'FL Avl',
            'com_availed'=>'COM Avl',
            'cl_close'=>'CL Close','el_close'=>'EL Close','hpl_close'=>'HPL Close','fl_close'=>'FL Close',
        ];
        foreach ($rectifications as $i => $r):
          $ba    = json_decode($r['before_data'],   true) ?? [];
          $aa    = json_decode($r['after_data'],    true) ?? [];
          $bl    = json_decode($r['before_ledger'] ?? '{}', true) ?? [];
          $al    = json_decode($r['after_ledger']  ?? '{}', true) ?? [];
          $diffs = array_filter(array_keys($labels_short), fn($f) => isset($ba[$f],$aa[$f]) && $ba[$f] != $aa[$f]);
          $ldiff = array_filter(array_keys($ldg_labels),   fn($f) => isset($bl[$f],$al[$f]) && (float)$bl[$f] != (float)$al[$f]);
        ?>
        <tr class="align-top">
          <td class="text-muted"><?= $i+1 ?></td>
          <td><code><?= h($r['sail_perno']) ?></code></td>
          <td><?= h($r['emp_name'] ?? '—') ?></td>
          <td><?= month_label($r['rectify_yyyymm']) ?></td>
          <td><?= month_label($r['sanction_yyyymm']) ?></td>
          <td>
            <?php if (!$diffs && !$ldiff): ?>
              <span class="text-muted">No changes</span>
            <?php else: ?>
              <?php if ($diffs): ?>
              <div class="mb-1"><span class="text-muted" style="font-size:.7rem;">Attendance:</span>
              <div class="d-flex flex-wrap gap-1 mt-1">
              <?php foreach ($diffs as $f): ?>
                <span class="badge bg-light text-dark border" style="font-size:.7rem;">
                  <?= $labels_short[$f] ?? $f ?>:
                  <span class="text-danger"><?= number_format((float)$ba[$f],2) ?></span>→<span class="text-success fw-bold"><?= number_format((float)$aa[$f],2) ?></span>
                </span>
              <?php endforeach; ?>
              </div></div>
              <?php endif; ?>
              <?php if ($ldiff): ?>
              <div><span class="text-muted" style="font-size:.7rem;">Leave Balance:</span>
              <div class="d-flex flex-wrap gap-1 mt-1">
              <?php foreach ($ldiff as $f): ?>
                <span class="badge bg-warning bg-opacity-25 text-dark border" style="font-size:.7rem;">
                  <?= $ldg_labels[$f] ?>:
                  <span class="text-danger"><?= number_format((float)$bl[$f],2) ?></span>→<span class="text-success fw-bold"><?= number_format((float)$al[$f],2) ?></span>
                </span>
              <?php endforeach; ?>
              </div></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h($r['remarks'] ?: '—') ?></td>
          <td class="text-muted small"><?= h($r['created_by'] ?? '—') ?></td>
          <td class="text-muted small"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
