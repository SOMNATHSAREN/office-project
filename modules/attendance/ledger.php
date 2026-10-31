<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Leave Ledger';
$db = get_db();

$employees = $db->query('SELECT sail_perno, name FROM employees WHERE status="active" ORDER BY name')->fetchAll();

// Available years in ledger
$years     = $db->query('SELECT DISTINCT SUBSTR(yyyymm,1,4) AS yr FROM leave_ledger ORDER BY yr DESC')->fetchAll(PDO::FETCH_COLUMN);
$sel_year  = (int)($_GET['year'] ?? ($years[0] ?? date('Y')));
$locked    = is_year_locked($db, $sel_year);  // year-level (used for credit edits)

// Months within selected year
$mstmt = $db->prepare('SELECT DISTINCT yyyymm FROM leave_ledger WHERE yyyymm LIKE ? ORDER BY yyyymm ASC');
$mstmt->execute([$sel_year . '%']);
$months = $mstmt->fetchAll(PDO::FETCH_COLUMN);

$sel_perno = $_GET['perno'] ?? ($employees[0]['sail_perno'] ?? '');
$sel_month = $_GET['month'] ?? '';
// Ensure selected month belongs to the selected year
if ($sel_month && substr($sel_month, 0, 4) != $sel_year) $sel_month = '';

$ledger = [];
if ($sel_perno) {
    $sql  = 'SELECT ll.*, e.name FROM leave_ledger ll LEFT JOIN employees e ON e.sail_perno=ll.sail_perno WHERE ll.sail_perno=? AND ll.yyyymm LIKE ?';
    $args = [$sel_perno, $sel_year . '%'];
    if ($sel_month) { $sql .= ' AND ll.yyyymm=?'; $args[] = $sel_month; }
    $sql .= ' ORDER BY ll.yyyymm';
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $ledger = $stmt->fetchAll();
}

// ── CSV helpers ───────────────────────────────────────────────────────────────
$csv_headers = ['Employee','SAIL PERNO','Month',
                'CL Open','EL Open','HPL Open','FL Open',
                'CL Availed','EL Availed','HPL Availed','FL Availed',
                'COM Availed','HPL Deducted by COM',
                'CL Credit','EL Credit','HPL Credit','FL Credit',
                'CL Close','EL Close','HPL Close','FL Close',
                'Notes'];

function ledger_row_csv(array $row): array {
    return [
        $row['name'] ?? '', $row['sail_perno'], month_label($row['yyyymm']),
        $row['cl_open'],  $row['el_open'],  $row['hpl_open'],  $row['fl_open'],
        $row['cl_availed'],$row['el_availed'],$row['hpl_availed'],$row['fl_availed'],
        $row['com_availed'], $row['com_availed'] * 2,
        $row['cl_credit'],$row['el_credit'],$row['hpl_credit'],$row['fl_credit'],
        $row['cl_close'], $row['el_close'], $row['hpl_close'], $row['fl_close'],
        $row['notes'] ?? '',
    ];
}

// ── Per-employee CSV download ─────────────────────────────────────────────────
if (isset($_GET['export']) && $ledger) {
    $empName = $ledger[0]['name'] ?? $sel_perno;
    $fname   = 'leave_ledger_' . preg_replace('/\s+/','_',$empName) . ($sel_month ? '_'.$sel_month : '_'.$sel_year) . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output','w');
    fputcsv($out, $csv_headers);
    foreach ($ledger as $row) fputcsv($out, ledger_row_csv($row));
    fclose($out);
    exit;
}

// ── Month-wise ALL employees CSV download ─────────────────────────────────────
if (isset($_GET['export_month']) && $_GET['export_month']) {
    $exp_month = preg_replace('/[^0-9]/','',$_GET['export_month']);
    if (strlen($exp_month) === 6) {
        $s = $db->prepare(
            'SELECT ll.*, e.name FROM leave_ledger ll
             LEFT JOIN employees e ON e.sail_perno=ll.sail_perno
             WHERE ll.yyyymm=? ORDER BY e.name, ll.sail_perno'
        );
        $s->execute([$exp_month]);
        $month_rows = $s->fetchAll();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="leave_ledger_' . $exp_month . '_all.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, $csv_headers);
        foreach ($month_rows as $row) fputcsv($out, ledger_row_csv($row));
        fclose($out);
        exit;
    }
}

// Handle manual credit update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ledger_id'])) {
    $db  = get_db();
    $lid = (int)$_POST['ledger_id'];

    // Fetch the row so we know sail_perno + yyyymm for cascade
    $row = $db->prepare('SELECT sail_perno, yyyymm FROM leave_ledger WHERE id=?');
    $row->execute([$lid]);
    $lrow = $row->fetch();

    // Lock guard
    if ($lrow && is_period_locked($db, $lrow['yyyymm'])) {
        flash('error', 'Year ' . year_of($lrow['yyyymm']) . ' is locked. Credits cannot be modified.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $db->prepare('UPDATE leave_ledger SET cl_credit=?,el_credit=?,hpl_credit=?,fl_credit=?,notes=? WHERE id=?')
       ->execute([$_POST['cl_credit']??0,$_POST['el_credit']??0,$_POST['hpl_credit']??0,
                  $_POST['fl_credit']??0,trim($_POST['notes']??''),$lid]);

    // Cascade closing balance into subsequent months' opening balances
    if ($lrow) {
        recalculate_ledger_chain($db, $lrow['sail_perno'], $lrow['yyyymm']);
    }

    flash('success','Ledger updated and balances recalculated.');
    redirect($_SERVER['REQUEST_URI']);
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?>

<!-- ── Download All Employees — Month-wise ───────────────────────────────── -->
<div class="card mb-3 border-success">
  <div class="card-header py-2 bg-success bg-opacity-10 d-flex align-items-center gap-2">
    <i class="bi bi-people-fill text-success"></i>
    <span class="fw-semibold">Download Leave Ledger — All Employees (Month-wise)</span>
  </div>
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1 small fw-semibold">Year</label>
        <select name="dl_year" id="dl_year" class="form-select form-select-sm" onchange="loadDlMonths(this.value)">
          <?php foreach ($years as $y): ?>
            <option value="<?= h($y) ?>" <?= (int)$y===$sel_year?'selected':'' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-1 small fw-semibold">Month <span class="text-danger">*</span></label>
        <select name="export_month" id="dl_month" class="form-select form-select-sm" required>
          <option value="">— Select Month —</option>
          <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>"><?= month_label($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-success btn-sm">
          <i class="bi bi-download me-1"></i>Download CSV
        </button>
      </div>
      <div class="col-auto text-muted small align-self-end pb-1">
        Downloads all employees' leave ledger for the selected month
      </div>
    </form>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1 fw-semibold">Year</label>
        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= h($y) ?>" <?= (int)$y===$sel_year?'selected':'' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1 fw-semibold">Employee</label>
        <select name="perno" class="form-select form-select-sm">
          <?php foreach ($employees as $emp): ?>
            <option value="<?= h($emp['sail_perno']) ?>" <?= $emp['sail_perno']===$sel_perno?'selected':'' ?>>
              <?= h($emp['name']) ?> (<?= h($emp['sail_perno']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1 fw-semibold">Month (optional)</label>
        <select name="month" class="form-select form-select-sm">
          <option value="">All months</option>
          <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>" <?= $m===$sel_month?'selected':'' ?>><?= month_label($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <?php if ($locked): ?>
          <span class="badge bg-danger fs-6"><i class="bi bi-lock-fill me-1"></i><?= $sel_year ?> Locked</span>
        <?php else: ?>
          <span class="badge bg-success fs-6"><i class="bi bi-unlock me-1"></i><?= $sel_year ?> Open</span>
        <?php endif; ?>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">View Ledger</button>
      </div>
      <div class="col-auto ms-auto d-flex gap-2">
        <?php if ($ledger): ?>
          <?php $export_url = '?' . http_build_query(['year'=>$sel_year,'perno'=>$sel_perno,'month'=>$sel_month,'export'=>1]); ?>
          <a href="<?= h($export_url) ?>" class="btn btn-outline-success btn-sm" title="Download this employee's ledger">
            <i class="bi bi-person-lines-fill me-1"></i>Download Employee CSV
          </a>
        <?php endif; ?>
        <?php if ($sel_month): ?>
          <?php $month_export_url = '?' . http_build_query(['year'=>$sel_year,'export_month'=>$sel_month]); ?>
          <a href="<?= h($month_export_url) ?>" class="btn btn-success btn-sm" title="Download all employees for <?= month_label($sel_month) ?>">
            <i class="bi bi-people-fill me-1"></i>Download <?= month_label($sel_month) ?> (All)
          </a>
        <?php elseif ($months): ?>
          <div class="input-group input-group-sm" style="width:auto;">
            <select id="quick_month_dl" class="form-select form-select-sm" style="min-width:140px;">
              <option value="">— Select Month —</option>
              <?php foreach ($months as $m): ?>
                <option value="<?= $m ?>"><?= month_label($m) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-success btn-sm" onclick="downloadMonth()">
              <i class="bi bi-download me-1"></i>All Employees
            </button>
          </div>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($ledger): ?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-bordered table-sm mb-0 align-middle text-center" style="font-size:.82rem;">
      <thead class="table-dark">
        <tr>
          <th rowspan="2" class="align-middle text-start">Month</th>
          <th colspan="4" class="table-primary text-primary">Opening Balance</th>
          <th colspan="5" class="table-warning text-warning-emphasis">Availed</th>
          <th colspan="4" class="table-success text-success">Credit</th>
          <th colspan="4" class="table-info text-info-emphasis">Closing Balance</th>
          <?php if (!$locked): ?><th rowspan="2" class="align-middle">Edit</th><?php endif; ?>
        </tr>
        <tr>
          <th>CL</th><th>EL</th><th>HPL</th><th>FL</th>
          <th>CL</th><th>EL</th><th>HPL</th><th>FL</th>
          <th title="Commuted Leave — deducts 2× from HPL">COM★</th>
          <th>CL</th><th>EL</th><th>HPL</th><th>FL</th>
          <th>CL</th><th>EL</th><th>HPL</th><th>FL</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ledger as $row): ?>
        <tr>
          <td class="text-start fw-semibold"><?= month_label($row['yyyymm']) ?></td>
          <td><?= h($row['cl_open'])  ?></td><td><?= h($row['el_open'])  ?></td>
          <td><?= h($row['hpl_open']) ?></td><td><?= h($row['fl_open'])  ?></td>
          <td class="text-danger"><?= h($row['cl_availed'])  ?></td>
          <td class="text-danger"><?= h($row['el_availed'])  ?></td>
          <td class="text-danger"><?= h($row['hpl_availed']) ?></td>
          <td class="text-danger"><?= h($row['fl_availed'])  ?></td>
          <td class="text-danger fw-semibold">
            <?php if ($row['com_availed'] > 0): ?>
              <?= h($row['com_availed']) ?>
              <span class="text-muted" style="font-size:.7rem;" title="HPL deducted">(−<?= h($row['com_availed'] * 2) ?> HPL)</span>
            <?php else: ?>
              0
            <?php endif; ?>
          </td>
          <td class="text-success"><?= h($row['cl_credit'])  ?></td>
          <td class="text-success"><?= h($row['el_credit'])  ?></td>
          <td class="text-success"><?= h($row['hpl_credit']) ?></td>
          <td class="text-success"><?= h($row['fl_credit'])  ?></td>
          <td class="fw-bold"><?= h($row['cl_close'])  ?></td>
          <td class="fw-bold"><?= h($row['el_close'])  ?></td>
          <td class="fw-bold"><?= h($row['hpl_close']) ?></td>
          <td class="fw-bold"><?= h($row['fl_close'])  ?></td>
          <?php if (!$locked): ?>
          <td>
            <button class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:2px 7px;"
                    onclick="openEdit(<?= $row['id'] ?>,<?= h($row['cl_credit']) ?>,<?= h($row['el_credit']) ?>,<?= h($row['hpl_credit']) ?>,<?= h($row['fl_credit']) ?>,'<?= addslashes($row['notes']??'') ?>')">
              <i class="bi bi-pencil"></i>
            </button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php elseif ($sel_perno): ?>
  <div class="alert alert-info">No ledger records found. Upload attendance data first.</div>
<?php endif; ?>

<!-- Edit Credit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
      <input type="hidden" name="ledger_id" id="modal_lid">
      <div class="modal-header"><h5 class="modal-title">Edit Leave Credits</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6"><label class="form-label">CL Credit</label>
            <input type="number" step="0.5" name="cl_credit"  id="m_cl"  class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label">EL Credit</label>
            <input type="number" step="0.5" name="el_credit"  id="m_el"  class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label">HPL Credit</label>
            <input type="number" step="0.5" name="hpl_credit" id="m_hpl" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label">FL Credit</label>
            <input type="number" step="0.5" name="fl_credit"  id="m_fl"  class="form-control form-control-sm"></div>
          <div class="col-12"><label class="form-label">Notes</label>
            <textarea name="notes" id="m_notes" class="form-control form-control-sm" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary btn-sm">Save</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      </div>
      </form>
    </div>
  </div>
</div>
<script>
// All months by year (for the download section year-change)
const monthsByYear = <?= json_encode(
    array_reduce(
        $db->query('SELECT DISTINCT yyyymm FROM leave_ledger ORDER BY yyyymm ASC')->fetchAll(PDO::FETCH_COLUMN),
        function($carry, $m) { $carry[substr($m,0,4)][] = $m; return $carry; },
        []
    )
) ?>;

function loadDlMonths(year) {
    const sel = document.getElementById('dl_month');
    sel.innerHTML = '<option value="">— Select Month —</option>';
    (monthsByYear[year] || []).forEach(function(m) {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = m.substring(0,4) + '-' + m.substring(4,6) +
            ' (' + new Date(m.substring(0,4), parseInt(m.substring(4,6))-1, 1)
                .toLocaleString('default',{month:'long'}) + ')';
        sel.appendChild(opt);
    });
}

function downloadMonth() {
    const m = document.getElementById('quick_month_dl').value;
    if (!m) { alert('Please select a month first.'); return; }
    window.location = '?year=<?= $sel_year ?>&export_month=' + m;
}
function openEdit(id,cl,el,hpl,fl,notes){
  document.getElementById('modal_lid').value=id;
  document.getElementById('m_cl').value=cl;
  document.getElementById('m_el').value=el;
  document.getElementById('m_hpl').value=hpl;
  document.getElementById('m_fl').value=fl;
  document.getElementById('m_notes').value=notes;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
