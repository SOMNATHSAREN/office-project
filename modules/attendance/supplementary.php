<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('att_write');
$page_title = 'Supplementary Attendance';
$db = get_db();

$employees = $db->query('SELECT sail_perno, name FROM employees WHERE status="active" ORDER BY name')->fetchAll();

// ── Actions ────────────────────────────────────────────────────────────────────

if (isset($_GET['apply'])) {
    $sid = (int)$_GET['apply'];
    $chk = $db->prepare('SELECT absence_yyyymm FROM supplementary_attendance WHERE id=?');
    $chk->execute([$sid]);
    $chkrow = $chk->fetch();
    if ($chkrow && is_period_locked($db, $chkrow['absence_yyyymm'])) {
        flash('error', 'Cannot apply — ' . month_label($chkrow['absence_yyyymm']) . ' is locked.');
    } elseif (apply_supplementary($db, $sid)) {
        $chkrow2 = $db->prepare('SELECT * FROM supplementary_attendance WHERE id=?');
        $chkrow2->execute([$sid]);
        $applied_row = $chkrow2->fetch();
        if ($applied_row) audit_log($db, 'supp_apply', "{$applied_row['leave_type']} {$applied_row['days']}d applied for " . month_label($applied_row['absence_yyyymm']), $applied_row['sail_perno'], $applied_row['absence_yyyymm']);
        flash('success', 'Supplementary entry applied and leave ledger recalculated.');
    } else {
        flash('error', 'Could not apply — attendance record for that month may not exist.');
    }
    redirect(BASE_URL . '/modules/attendance/supplementary.php');
}

if (isset($_GET['cancel'])) {
    $sid = (int)$_GET['cancel'];
    $canc = $db->prepare('SELECT * FROM supplementary_attendance WHERE id=?');
    $canc->execute([$sid]);
    $canc_row = $canc->fetch();
    $db->prepare('UPDATE supplementary_attendance SET status="cancelled" WHERE id=? AND status="pending"')
       ->execute([$sid]);
    if ($canc_row) audit_log($db, 'supp_cancel', "{$canc_row['leave_type']} {$canc_row['days']}d cancelled for " . month_label($canc_row['absence_yyyymm']), $canc_row['sail_perno'], $canc_row['absence_yyyymm']);
    flash('success', 'Entry cancelled.');
    redirect(BASE_URL . '/modules/attendance/supplementary.php');
}

// ── Add new entry ──────────────────────────────────────────────────────────────
$errors = [];
$form   = ['sail_perno'=>'','absence_yyyymm'=>'','sanction_yyyymm'=>'',
           'leave_type'=>'CL','days'=>'','remarks'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sail_perno'])) {
    $form = array_map('trim', $_POST);
    // Convert YYYY-MM (HTML month input) → YYYYMM
    foreach (['absence_yyyymm','sanction_yyyymm'] as $f) {
        $form[$f] = str_replace('-', '', $form[$f] ?? '');
    }

    if (!$form['sail_perno'])        $errors[] = 'Employee is required.';
    if (!$form['absence_yyyymm'])    $errors[] = 'Absence month is required.';
    if (!$form['sanction_yyyymm'])   $errors[] = 'Sanction month is required.';
    if (!$form['leave_type'])        $errors[] = 'Leave type is required.';

    $leave_type = strtoupper($form['leave_type']);
    $days       = (float)($form['days'] ?? 0);

    if ($days <= 0) $errors[] = 'Days must be greater than 0.';
    if ($leave_type === 'COM' && $days != (int)$days)
        $errors[] = 'COM (Commuted Leave) must be a whole number.';

    if ($form['absence_yyyymm'] && $form['sanction_yyyymm'] &&
        $form['sanction_yyyymm'] < $form['absence_yyyymm']) {
        $errors[] = 'Sanction month cannot be earlier than absence month.';
    }

    if (!$errors) {
        $chk = $db->prepare('SELECT id FROM attendance WHERE sail_perno=? AND yyyymm=?');
        $chk->execute([$form['sail_perno'], $form['absence_yyyymm']]);
        if (!$chk->fetch()) {
            $errors[] = 'No attendance record found for ' . month_label($form['absence_yyyymm'])
                      . '. Upload that month\'s attendance first.';
        }
    }

    if (!$errors && is_period_locked($db, $form['absence_yyyymm'])) {
        $errors[] = month_label($form['absence_yyyymm']) . ' is locked. Cannot add supplementary entry.';
    }

    if (!$errors && !is_employee_active($db, $form['sail_perno'])) {
        $errors[] = 'Employee is inactive. Supplementary entries cannot be added for inactive employees.';
    }

    if (!$errors) {
        $db->prepare('INSERT INTO supplementary_attendance
            (sail_perno, absence_yyyymm, sanction_yyyymm, leave_type, days, remarks, status)
            VALUES (?,?,?,?,?,?,"pending")')
           ->execute([$form['sail_perno'], $form['absence_yyyymm'], $form['sanction_yyyymm'],
                      $leave_type, $days, $form['remarks']]);
        audit_log($db, 'supp_add', "$leave_type {$days}d added for " . month_label($form['absence_yyyymm']), $form['sail_perno'], $form['absence_yyyymm']);
        flash('success', 'Supplementary entry added. Click Apply to update ledger.');
        redirect(BASE_URL . '/modules/attendance/supplementary.php');
    }
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'pending';
$filter_perno  = $_GET['perno']  ?? '';

$sql  = 'SELECT s.*, e.name FROM supplementary_attendance s
         LEFT JOIN employees e ON e.sail_perno = s.sail_perno
         WHERE 1=1';
$args = [];
if ($filter_status !== 'all') { $sql .= ' AND s.status=?'; $args[] = $filter_status; }
if ($filter_perno)            { $sql .= ' AND s.sail_perno=?'; $args[] = $filter_perno; }
$sql .= ' ORDER BY s.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($args);
$entries = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<div class="row g-4">

<!-- ── Add form ─────────────────────────────────────────────────────────── -->
<div class="col-lg-4">
<div class="card">
  <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-2"></i>Add Supplementary Entry</div>
  <div class="card-body">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger py-2 small"><?= h($e) ?></div>
    <?php endforeach; ?>
    <form method="post" id="suppForm">
      <div class="mb-2">
        <label class="form-label small fw-semibold">Employee <span class="text-danger">*</span></label>
        <select name="sail_perno" class="form-select form-select-sm" required>
          <option value="">— Select —</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= h($emp['sail_perno']) ?>" <?= $form['sail_perno']===$emp['sail_perno']?'selected':'' ?>>
              <?= h($emp['name']) ?> (<?= h($emp['sail_perno']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-2">
        <label class="form-label small fw-semibold">Absence Month <span class="text-danger">*</span></label>
        <input type="month" name="absence_yyyymm" class="form-control form-control-sm"
               value="<?= h($form['absence_yyyymm'] ? substr($form['absence_yyyymm'],0,4).'-'.substr($form['absence_yyyymm'],4,2) : '') ?>"
               required>
        <div class="form-text">Month when the employee was absent</div>
      </div>

      <div class="mb-2">
        <label class="form-label small fw-semibold">Sanction Month <span class="text-danger">*</span></label>
        <input type="month" name="sanction_yyyymm" class="form-control form-control-sm"
               value="<?= h($form['sanction_yyyymm'] ? substr($form['sanction_yyyymm'],0,4).'-'.substr($form['sanction_yyyymm'],4,2) : '') ?>"
               required>
        <div class="form-text">Month when the leave is being sanctioned</div>
      </div>

      <div class="mb-2">
        <label class="form-label small fw-semibold">Leave Type <span class="text-danger">*</span></label>
        <select name="leave_type" class="form-select form-select-sm" id="leave_type_sel" onchange="onTypeChange(this.value)">
          <?php foreach (['CL','EL','COM','HPL','SCL','EXWAGE'] as $lt): ?>
            <option value="<?= $lt ?>" <?= $form['leave_type']===$lt?'selected':'' ?>><?= $lt ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-2">
        <label class="form-label small fw-semibold" id="days_label">Days <span class="text-danger">*</span></label>
        <input type="number" name="days" id="days_inp" class="form-control form-control-sm"
               step="0.5" min="0.5" value="<?= h($form['days']) ?>" required>
        <div id="com_hint" class="form-text text-warning" style="display:none;">
          COM must be a whole number · 1 COM = 2 HPL deducted
        </div>
        <div id="exwage_hint" class="form-text" style="display:none;">
          Enter number of Ex-Wage days sanctioned (updates attendance exwage column)
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-semibold">Remarks</label>
        <textarea name="remarks" class="form-control form-control-sm" rows="2"><?= h($form['remarks']) ?></textarea>
      </div>

      <button class="btn btn-primary btn-sm w-100"><i class="bi bi-save me-1"></i>Save Entry</button>
    </form>
  </div>
</div>

<div class="card mt-3">
  <div class="card-body p-3">
    <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle me-1 text-primary"></i>How it works</h6>
    <ol class="mb-0 small text-muted ps-3">
      <li>Add the entry (status: <span class="badge bg-warning text-dark">Pending</span>)</li>
      <li>Review — then click <strong>Apply</strong></li>
      <li><strong>CL / EL / HPL / COM / SCL</strong>: only leave ledger is updated (availed increases). Attendance record is not changed.</li>
      <li><strong>EXWAGE</strong>: attendance exwage days increase. No ledger change.</li>
      <li>COM also deducts 2× days from HPL closing balance.</li>
      <li>Ledger recalculates and cascades forward from the absence month.</li>
    </ol>
  </div>
</div>
</div>

<!-- ── Entry list ────────────────────────────────────────────────────────── -->
<div class="col-lg-8">
  <div class="card mb-3">
    <div class="card-body py-2">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
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
        <div class="col-md-4">
          <label class="form-label mb-1 small fw-semibold">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="pending"  <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
            <option value="applied"  <?= $filter_status==='applied'?'selected':'' ?>>Applied</option>
            <option value="cancelled"<?= $filter_status==='cancelled'?'selected':'' ?>>Cancelled</option>
            <option value="all"      <?= $filter_status==='all'?'selected':'' ?>>All</option>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm">Filter</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem;">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Employee</th>
            <th>Absence Month</th>
            <th>Sanction Month</th>
            <th>Leave Type</th>
            <th>Days</th>
            <th>Remarks</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$entries): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No entries found.</td></tr>
          <?php endif; ?>
          <?php foreach ($entries as $i => $ent): ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td>
              <span class="fw-semibold"><?= h($ent['name'] ?? '—') ?></span><br>
              <code class="text-muted" style="font-size:.72rem;"><?= h($ent['sail_perno']) ?></code>
            </td>
            <td><?= month_label($ent['absence_yyyymm']) ?></td>
            <td><?= month_label($ent['sanction_yyyymm']) ?></td>
            <td>
              <?php
              $badge = ['CL'=>'primary','EL'=>'info','COM'=>'warning','HPL'=>'secondary','SCL'=>'dark','EXWAGE'=>'success'];
              $bc = $badge[$ent['leave_type']] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $bc ?>"><?= h($ent['leave_type']) ?></span>
            </td>
            <td>
              <?= h($ent['days']) ?> day<?= $ent['days'] != 1 ? 's' : '' ?>
              <?php if ($ent['leave_type'] === 'COM'): ?>
                <br><span class="text-muted" style="font-size:.7rem;">(−<?= $ent['days']*2 ?> HPL)</span>
              <?php elseif ($ent['leave_type'] === 'EXWAGE'): ?>
                <br><span class="text-muted" style="font-size:.7rem;">attendance exwage</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= h($ent['remarks']) ?></td>
            <td>
              <?php if ($ent['status'] === 'pending'): ?>
                <span class="badge bg-warning text-dark">Pending</span>
              <?php elseif ($ent['status'] === 'applied'): ?>
                <span class="badge bg-success">Applied</span>
                <?php if ($ent['applied_at']): ?>
                  <br><span class="text-muted" style="font-size:.7rem;"><?= date('d M Y', strtotime($ent['applied_at'])) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge bg-secondary">Cancelled</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($ent['status'] === 'pending'): ?>
                <a href="?apply=<?= $ent['id'] ?>&status=<?= h($filter_status) ?>&perno=<?= h($filter_perno) ?>"
                   class="btn btn-success btn-sm"
                   onclick="return confirm('Apply this supplementary entry for <?= month_label($ent['absence_yyyymm']) ?>?')">
                  <i class="bi bi-check2-circle me-1"></i>Apply
                </a>
                <a href="?cancel=<?= $ent['id'] ?>&status=<?= h($filter_status) ?>&perno=<?= h($filter_perno) ?>"
                   class="btn btn-outline-danger btn-sm"
                   onclick="return confirm('Cancel this entry?')">
                  <i class="bi bi-x-circle"></i>
                </a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
</div><!-- /row -->

<script>
function onTypeChange(type) {
    const isCom    = type === 'COM';
    const isExwage = type === 'EXWAGE';
    document.getElementById('com_hint').style.display    = isCom    ? '' : 'none';
    document.getElementById('exwage_hint').style.display = isExwage ? '' : 'none';
    const daysInp = document.getElementById('days_inp');
    daysInp.step  = isCom ? '1' : '0.5';
    daysInp.min   = isCom ? '1' : '0.5';
    daysInp.title = isCom ? 'COM must be a whole number' : '';
    document.getElementById('days_label').innerHTML =
        isExwage ? 'Ex-Wage Days <span class="text-danger">*</span>'
                 : 'Days <span class="text-danger">*</span>';
}
onTypeChange(document.getElementById('leave_type_sel').value);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
