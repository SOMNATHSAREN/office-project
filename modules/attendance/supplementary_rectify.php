<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('att_write');
$page_title = 'Supplementary Rectification';
$db = get_db();

$employees = $db->query('SELECT sail_perno, name FROM employees WHERE status="active" ORDER BY name')->fetchAll();

$num_fields = ['duty','w_off','c_nhday','c_off','cl','fl_coff','el','hpl','com','eol','abs','others',
               'night_shift','injury_lv','ex_grat','unaccounted','exwage','local_trg','scl_bd','scl','ccl',
               'short_hrs','el_blns','hpl_blns','cl_blns','fl_blns'];

$labels = ['duty'=>'Duty Days','w_off'=>'Weekly Off','c_nhday'=>'NH/Comp NH','c_off'=>'Comp Off',
           'cl'=>'CL','fl_coff'=>'FL/COFF','el'=>'EL','hpl'=>'HPL',
           'com'=>'COM (Commuted) ★','eol'=>'EOL',
           'abs'=>'Absence','others'=>'Others','night_shift'=>'Night Shift','injury_lv'=>'Injury Leave',
           'ex_grat'=>'Ex-Gratia','unaccounted'=>'Unaccounted','exwage'=>'Ex-Wage','local_trg'=>'Local Trg',
           'scl_bd'=>'SCL-BD','scl'=>'SCL','ccl'=>'CCL','short_hrs'=>'Short Hrs',
           'el_blns'=>'EL Balance','hpl_blns'=>'HPL Balance','cl_blns'=>'CL Balance','fl_blns'=>'FL Balance'];

$sel_perno  = $_GET['perno']  ?? $_POST['perno']  ?? '';
$sel_yyyymm = $_GET['yyyymm'] ?? $_POST['yyyymm'] ?? '';
if ($sel_yyyymm) $sel_yyyymm = str_replace('-', '', $sel_yyyymm);

$att    = null;
$errors = [];

// Current month for sanction_yyyymm
$today_yyyymm = date('Ym');

// Fetch attendance record when employee + month selected
if ($sel_perno && $sel_yyyymm) {
    $s = $db->prepare('SELECT a.*, e.name FROM attendance a LEFT JOIN employees e ON e.sail_perno=a.sail_perno WHERE a.sail_perno=? AND a.yyyymm=?');
    $s->execute([$sel_perno, $sel_yyyymm]);
    $att = $s->fetch();
}

// ── Save ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rectify'])) {
    $sel_perno       = trim($_POST['perno']        ?? '');
    $sel_yyyymm      = str_replace('-', '', trim($_POST['yyyymm']        ?? ''));
    $sanction_yyyymm = str_replace('-', '', trim($_POST['sanction_yyyymm'] ?? $today_yyyymm));
    $remarks         = trim($_POST['remarks'] ?? '');

    if (is_period_locked($db, $sel_yyyymm)) {
        $errors[] = month_label($sel_yyyymm) . ' is locked. Rectification not allowed.';
    } elseif (!is_employee_active($db, $sel_perno)) {
        $errors[] = 'Employee is inactive. Rectification not allowed.';
    }

    $com_val = $_POST['com'] ?? 0;
    if (!$errors && (float)$com_val != (int)$com_val) {
        $errors[] = 'COM must be a whole number — fractional values are not allowed.';
    }

    // Fetch existing record (need before snapshot)
    if (!$errors) {
        $s = $db->prepare('SELECT a.*, e.name FROM attendance a LEFT JOIN employees e ON e.sail_perno=a.sail_perno WHERE a.sail_perno=? AND a.yyyymm=?');
        $s->execute([$sel_perno, $sel_yyyymm]);
        $att = $s->fetch();
        if (!$att) {
            $errors[] = 'No attendance record found for this employee and month. Upload the attendance CSV first.';
        }
    }

    if (!$errors) {
        // Helper: fetch leave_ledger snapshot for one employee+month
        $fetch_ledger = function(string $perno, string $yyyymm) use ($db): array {
            $st = $db->prepare('SELECT cl_open,el_open,hpl_open,fl_open,
                                       cl_credit,el_credit,hpl_credit,fl_credit,
                                       cl_availed,el_availed,hpl_availed,fl_availed,com_availed,
                                       cl_close,el_close,hpl_close,fl_close
                                FROM leave_ledger WHERE sail_perno=? AND yyyymm=?');
            $st->execute([$perno, $yyyymm]);
            return $st->fetch() ?: [];
        };

        // Snapshot BEFORE values
        $before        = [];
        foreach ($num_fields as $f) $before[$f] = (float)$att[$f];
        $before_ledger = $fetch_ledger($sel_perno, $sel_yyyymm);

        // Build AFTER values from POST
        $after = [];
        $sets = []; $params = [];
        foreach ($num_fields as $f) {
            $val = is_numeric($_POST[$f] ?? '') ? (float)$_POST[$f] : 0;
            if ($f === 'com') $val = (int)$val;
            $after[$f]  = $val;
            $sets[]     = "$f=?";
            $params[]   = $val;
        }
        $params[] = $att['id'];

        $db->prepare('UPDATE attendance SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);

        // Sync leave ledger availed
        $db->prepare('UPDATE leave_ledger SET cl_availed=?, el_availed=?, hpl_availed=?, fl_availed=?, com_availed=?
                      WHERE sail_perno=? AND yyyymm=?')
           ->execute([$after['cl'], $after['el'], $after['hpl'], $after['fl_coff'],
                      (int)$after['com'], $sel_perno, $sel_yyyymm]);

        recalculate_ledger_chain($db, $sel_perno, $sel_yyyymm);

        // Snapshot AFTER ledger (post-recalculation)
        $after_ledger = $fetch_ledger($sel_perno, $sel_yyyymm);

        // Insert audit log
        $db->prepare('INSERT INTO supplementary_rectify_log
                      (sail_perno, rectify_yyyymm, sanction_yyyymm, before_data, after_data,
                       before_ledger, after_ledger, remarks, created_by)
                      VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([
               $sel_perno, $sel_yyyymm, $sanction_yyyymm,
               json_encode($before), json_encode($after),
               json_encode($before_ledger), json_encode($after_ledger),
               $remarks,
               $_SESSION['username'] ?? 'admin',
           ]);

        // Re-fetch updated record
        $s->execute([$sel_perno, $sel_yyyymm]);
        $att = $s->fetch();

        $changed_fields = implode(', ', array_filter($num_fields, fn($f) => $before[$f] != $after[$f]));
        audit_log($db, 'rectify', 'Rectified ' . month_label($sel_yyyymm) . ' (sanctioned ' . month_label($sanction_yyyymm) . '). Changed: ' . ($changed_fields ?: 'none') . '. Remarks: ' . $remarks, $sel_perno, $sel_yyyymm);
        flash('success', 'Attendance rectified for ' . month_label($sel_yyyymm) .
              ' (sanctioned in ' . month_label($sanction_yyyymm) . '). Leave ledger recalculated.');
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<!-- ── Employee + month picker ──────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-search me-2"></i>Select Employee & Month to Rectify</div>
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="form-label small fw-semibold">Employee <span class="text-danger">*</span></label>
        <select name="perno" class="form-select form-select-sm" required>
          <option value="">— Select Employee —</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= h($emp['sail_perno']) ?>" <?= $sel_perno===$emp['sail_perno']?'selected':'' ?>>
              <?= h($emp['name']) ?> (<?= h($emp['sail_perno']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Absence Month <span class="text-danger">*</span></label>
        <input type="month" name="yyyymm" class="form-control form-control-sm" required
               value="<?= $sel_yyyymm ? substr($sel_yyyymm,0,4).'-'.substr($sel_yyyymm,4,2) : '' ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Load Record</button>
      </div>
    </form>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($sel_perno && $sel_yyyymm && !$att && !$errors): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    No attendance record found for <strong><?= month_label($sel_yyyymm) ?></strong>. Upload the month's CSV first.
  </div>
<?php endif; ?>

<?php if ($att):
  $locked = is_period_locked($db, $att['yyyymm']);
?>
<div class="row g-4">

<!-- ── Rectification form ───────────────────────────────────────────────── -->
<div class="col-lg-8">
<div class="card">
  <div class="card-header d-flex align-items-center gap-2 py-2">
    <i class="bi bi-pencil-square text-warning"></i>
    <span class="fw-semibold">Rectify Attendance</span>
    <span class="badge bg-primary-subtle text-primary ms-1"><?= h($att['name'] ?? $att['sail_perno']) ?></span>
    <span class="badge bg-secondary-subtle text-secondary"><?= month_label($att['yyyymm']) ?></span>
    <?php if ($locked): ?>
      <span class="badge bg-danger ms-auto"><i class="bi bi-lock-fill me-1"></i>Year Locked</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="perno"  value="<?= h($sel_perno) ?>">
      <input type="hidden" name="yyyymm" value="<?= h($sel_yyyymm) ?>">
      <input type="hidden" name="save_rectify" value="1">

      <!-- Sanction month + remarks -->
      <div class="row g-3 mb-3 pb-3 border-bottom">
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Sanction Month <span class="text-danger">*</span>
            <span class="text-muted fw-normal">(when rectified)</span>
          </label>
          <input type="month" name="sanction_yyyymm" class="form-control form-control-sm"
                 value="<?= substr($today_yyyymm,0,4).'-'.substr($today_yyyymm,4,2) ?>"
                 <?= $locked ? 'disabled' : '' ?> required>
        </div>
        <div class="col-md-8">
          <label class="form-label small fw-semibold">Remarks / Reason for Rectification</label>
          <input type="text" name="remarks" class="form-control form-control-sm"
                 placeholder="e.g. Leave sanctioned by HoD on 15 Jun 2025"
                 value="<?= h($_POST['remarks'] ?? '') ?>"
                 <?= $locked ? 'disabled' : '' ?>>
        </div>
      </div>

      <div class="row g-3">
        <?php foreach ($num_fields as $f):
          $is_com   = ($f === 'com');
          $is_leave = in_array($f, ['cl','el','hpl','fl_coff']);
          $is_bal   = in_array($f, ['cl_blns','el_blns','hpl_blns','fl_blns']);
          $cls      = ($is_leave || $is_bal) ? ' border-primary' : ($is_com ? ' border-warning' : '');
          $step     = $is_com ? '1' : '0.5';
          $cur_val  = $_POST[$f] ?? $att[$f] ?? 0;
        ?>
        <div class="col-md-3 col-6">
          <label class="form-label small fw-semibold"><?= $labels[$f] ?? $f ?></label>
          <input type="number" step="<?= $step ?>" min="0"
                 name="<?= h($f) ?>"
                 class="form-control form-control-sm<?= $cls ?>"
                 value="<?= h($cur_val) ?>"
                 <?= $locked ? 'disabled' : '' ?>
                 <?= $is_com ? 'title="COM must be a whole number. 1 COM = 2 HPL deducted."' : '' ?>>
          <?php if ($is_com): ?>
            <div class="form-text text-warning" style="font-size:.7rem;">Whole number only · 1 COM = 2 HPL deducted</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <hr>
      <div class="d-flex gap-2 align-items-center">
        <?php if (!$locked): ?>
          <button name="save_rectify" value="1" class="btn btn-warning">
            <i class="bi bi-check2-circle me-1"></i>Save Rectification
          </button>
        <?php else: ?>
          <span class="text-danger"><i class="bi bi-lock-fill me-1"></i>Year is locked — editing not allowed.</span>
        <?php endif; ?>
        <a href="supplementary_rectify.php" class="btn btn-outline-secondary btn-sm">Clear</a>
        <a href="<?= BASE_URL ?>/modules/attendance/ledger.php?perno=<?= urlencode($sel_perno) ?>&year=<?= year_of($sel_yyyymm) ?>"
           class="btn btn-outline-primary btn-sm ms-auto">
          <i class="bi bi-journal-text me-1"></i>View Leave Ledger
        </a>
      </div>
    </form>
  </div>
</div>

<div class="card mt-2 border-0 bg-light">
  <div class="card-body py-2 px-3 small text-muted">
    <i class="bi bi-clock-history me-1"></i>
    Record last updated: <strong><?= date('d M Y H:i', strtotime($att['updated_at'])) ?></strong>
    &nbsp;|&nbsp; Uploaded: <strong><?= date('d M Y H:i', strtotime($att['uploaded_at'])) ?></strong>
  </div>
</div>
</div>

<!-- ── Rectification history for this employee + month ──────────────────── -->
<div class="col-lg-4">
<?php
$logs = $db->prepare('SELECT * FROM supplementary_rectify_log WHERE sail_perno=? AND rectify_yyyymm=? ORDER BY created_at DESC');
$logs->execute([$sel_perno, $sel_yyyymm]);
$log_rows = $logs->fetchAll();
?>
<div class="card">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-clock-history me-2 text-info"></i>Rectification History
    <span class="badge bg-secondary ms-1"><?= count($log_rows) ?></span>
  </div>
  <div class="card-body p-0">
    <?php if (!$log_rows): ?>
      <p class="text-muted small p-3 mb-0">No rectifications recorded yet for this employee and month.</p>
    <?php endif; ?>
    <?php foreach ($log_rows as $log):
      $before = json_decode($log['before_data'], true) ?? [];
      $after  = json_decode($log['after_data'],  true) ?? [];
      $diffs  = array_filter($num_fields, fn($f) => isset($before[$f], $after[$f]) && $before[$f] != $after[$f]);
    ?>
    <div class="border-bottom p-3" style="font-size:.8rem;">
      <div class="d-flex justify-content-between align-items-start mb-1">
        <span class="fw-semibold text-warning"><i class="bi bi-pencil-square me-1"></i>Rectification</span>
        <span class="text-muted" style="font-size:.72rem;"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></span>
      </div>
      <div class="mb-1">
        <span class="badge bg-secondary-subtle text-secondary">Absence: <?= month_label($log['rectify_yyyymm']) ?></span>
        <span class="badge bg-primary-subtle text-primary ms-1">Sanctioned: <?= month_label($log['sanction_yyyymm']) ?></span>
      </div>
      <?php if ($log['remarks']): ?>
        <div class="text-muted mb-1"><i class="bi bi-chat-left-text me-1"></i><?= h($log['remarks']) ?></div>
      <?php endif; ?>
      <?php if ($diffs): ?>
        <table class="table table-sm mb-0 mt-1" style="font-size:.75rem;">
          <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
          <tbody>
          <?php foreach ($diffs as $f): ?>
            <tr>
              <td><?= $labels[$f] ?? $f ?></td>
              <td class="text-danger"><?= number_format((float)$before[$f], 2) ?></td>
              <td class="text-success fw-semibold"><?= number_format((float)$after[$f], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <span class="text-muted" style="font-size:.72rem;">No field values changed.</span>
      <?php endif; ?>
      <div class="text-muted mt-1" style="font-size:.7rem;">By: <?= h($log['created_by'] ?? '—') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Leave sanctions for same absence month -->
<?php
$sanctions = $db->prepare('SELECT * FROM supplementary_attendance WHERE sail_perno=? AND absence_yyyymm=? ORDER BY created_at DESC');
$sanctions->execute([$sel_perno, $sel_yyyymm]);
$sanc_rows = $sanctions->fetchAll();
?>
<?php if ($sanc_rows): ?>
<div class="card mt-3">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-patch-plus me-2 text-success"></i>Leave Sanctions — <?= month_label($sel_yyyymm) ?>
  </div>
  <div class="card-body p-0">
    <?php foreach ($sanc_rows as $s):
      $badge = ['CL'=>'primary','EL'=>'info','COM'=>'warning','HPL'=>'secondary','SCL'=>'dark','EXWAGE'=>'success'];
      $bc = $badge[$s['leave_type']] ?? 'secondary';
    ?>
    <div class="border-bottom px-3 py-2" style="font-size:.8rem;">
      <div class="d-flex justify-content-between">
        <span><span class="badge bg-<?= $bc ?>"><?= h($s['leave_type']) ?></span>
          <strong class="ms-1"><?= number_format((float)$s['days'],2) ?> day<?= $s['days']!=1?'s':'' ?></strong>
          <?php if ($s['leave_type']==='COM'): ?>
            <span class="text-danger" style="font-size:.72rem;">(−<?= (int)($s['days']*2) ?> HPL)</span>
          <?php endif; ?>
        </span>
        <span class="badge bg-<?= $s['status']==='applied'?'success':($s['status']==='pending'?'warning text-dark':'secondary') ?>">
          <?= ucfirst($s['status']) ?>
        </span>
      </div>
      <div class="text-muted mt-1">Sanctioned in: <?= month_label($s['sanction_yyyymm']) ?></div>
      <?php if ($s['remarks']): ?>
        <div class="text-muted"><i class="bi bi-chat-left-text me-1"></i><?= h($s['remarks']) ?></div>
      <?php endif; ?>
      <?php if ($s['applied_at']): ?>
        <div class="text-muted" style="font-size:.7rem;">Applied: <?= date('d M Y', strtotime($s['applied_at'])) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
</div><!-- /col -->

</div><!-- /row -->
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
