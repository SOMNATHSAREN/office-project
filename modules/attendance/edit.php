<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('att_write');
$id    = (int)($_GET['id'] ?? 0);
$back  = $_GET['month'] ?? '';
$db    = get_db();
$stmt  = $db->prepare('SELECT a.*, e.name FROM attendance a LEFT JOIN employees e ON e.sail_perno=a.sail_perno WHERE a.id=?');
$stmt->execute([$id]);
$data  = $stmt->fetch();
if (!$data) { flash('error','Record not found.'); redirect(BASE_URL.'/modules/attendance/index.php'); }

// Lock guard — block edits on locked year or locked month
if (is_period_locked($db, $data['yyyymm'])) {
    flash('error', month_label($data['yyyymm']) . ' is locked. No edits allowed.');
    redirect(BASE_URL . '/modules/attendance/index.php?year=' . year_of($data['yyyymm']));
}

// Inactive employee guard
if (!is_employee_active($db, $data['sail_perno'])) {
    flash('error', 'Employee ' . ($data['sail_perno']) . ' is inactive. Attendance cannot be edited.');
    redirect(BASE_URL . '/modules/attendance/index.php?year=' . year_of($data['yyyymm']));
}

$page_title = 'Edit Attendance — ' . h($data['name'] ?? $data['sail_perno']) . ' / ' . month_label($data['yyyymm']);
$errors = [];

$num_fields = ['duty','w_off','c_nhday','c_off','cl','fl_coff','el','hpl','com','eol','abs','others',
               'night_shift','injury_lv','ex_grat','unaccounted','exwage','local_trg','scl_bd','scl','ccl',
               'short_hrs','el_blns','hpl_blns','cl_blns','fl_blns'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    // COM must be a whole number
    $com_val = $post['com'] ?? 0;
    if ((float)$com_val != (int)$com_val) {
        $errors[] = 'COM (Commuted Leave) must be a whole number — fractional values are not allowed.';
    }

    if (!$errors) {
        // Snapshot before values for audit diff
        $before_stmt = $db->prepare('SELECT ' . implode(',', $num_fields) . ' FROM attendance WHERE id=?');
        $before_stmt->execute([$id]);
        $before = $before_stmt->fetch(PDO::FETCH_ASSOC);

        $sets = []; $params = [];
        foreach ($num_fields as $f) {
            $sets[]   = "$f=?";
            $val      = is_numeric($post[$f] ?? '') ? (float)$post[$f] : 0;
            // Force COM to integer
            if ($f === 'com') $val = (int)$val;
            $params[] = $val;
        }
        $db->prepare('UPDATE attendance SET '.implode(',',$sets).', updated_by=? WHERE id=?')
           ->execute(array_merge($params, [$_SESSION['username'] ?? 'admin', $id]));

        // Sync leave ledger: com_availed drives 2× HPL deduction via generated column
        $com_availed = (int)($post['com'] ?? 0);
        $db->prepare('UPDATE leave_ledger SET cl_availed=?, el_availed=?, hpl_availed=?, fl_availed=?, com_availed=?
                      WHERE sail_perno=? AND yyyymm=?')
           ->execute([$post['cl']??0,$post['el']??0,$post['hpl']??0,$post['fl_coff']??0,
                      $com_availed,$data['sail_perno'],$data['yyyymm']]);

        recalculate_ledger_chain($db, $data['sail_perno'], $data['yyyymm']);

        // Build before/after diff for detailed audit
        $diffs = [];
        foreach ($num_fields as $f) {
            $old_val = (float)($before[$f] ?? 0);
            $new_val = ($f === 'com') ? (int)($post[$f] ?? 0) : (float)($post[$f] ?? 0);
            if ($old_val != $new_val) {
                $diffs[] = $f . ': ' . $old_val . ' → ' . $new_val;
            }
        }
        $desc = 'Attendance edited for ' . month_label($data['yyyymm'])
              . ($diffs ? ' | Changed: ' . implode(', ', $diffs) : ' | No field changes');
        audit_log($db, 'edit', $desc, $data['sail_perno'], $data['yyyymm']);
        flash('success','Attendance updated.');
        redirect(BASE_URL.'/modules/attendance/index.php?month='.urlencode($back ?: $data['yyyymm']));
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="mb-3">
  <span class="badge bg-primary-subtle text-primary fs-6"><?= h($data['name'] ?? $data['sail_perno']) ?></span>
  <span class="badge bg-secondary-subtle text-secondary fs-6 ms-1"><?= month_label($data['yyyymm']) ?></span>
</div>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= h($err) ?></div>
<?php endforeach; ?>
<form method="post">
<div class="row g-3">
  <?php
  $labels = ['duty'=>'Duty Days','w_off'=>'Weekly Off','c_nhday'=>'NH/Comp NH','c_off'=>'Comp Off',
             'cl'=>'CL','fl_coff'=>'FL/COFF','el'=>'EL','hpl'=>'HPL',
             'com'=>'COM (Commuted) ★','eol'=>'EOL',
             'abs'=>'Absence','others'=>'Others','night_shift'=>'Night Shift','injury_lv'=>'Injury Leave',
             'ex_grat'=>'Ex-Gratia','unaccounted'=>'Unaccounted','exwage'=>'Ex-Wage','local_trg'=>'Local Trg',
             'scl_bd'=>'SCL-BD','scl'=>'SCL','ccl'=>'CCL','short_hrs'=>'Short Hrs',
             'el_blns'=>'EL Balance','hpl_blns'=>'HPL Balance','cl_blns'=>'CL Balance','fl_blns'=>'FL Balance'];
  foreach ($num_fields as $f):
      $highlight = in_array($f, ['cl','el','hpl','fl_coff','cl_blns','el_blns','hpl_blns','fl_blns']) ? ' border-primary' : '';
      $is_com    = ($f === 'com');
      $step      = $is_com ? '1' : '0.5';   // COM = whole numbers only
      $extra_cls = $is_com ? ' border-warning' : '';
  ?>
  <div class="col-md-3 col-6">
    <label class="form-label small fw-semibold"><?= $labels[$f] ?? $f ?></label>
    <input type="number" step="<?= $step ?>" min="0" name="<?= $f ?>"
           class="form-control form-control-sm<?= $highlight . $extra_cls ?>"
           value="<?= h($post[$f] ?? $data[$f]) ?>"
           <?= $is_com ? 'title="COM must be a whole number. Each COM day deducts 2 days from HPL balance."' : '' ?>>
    <?php if ($is_com): ?>
      <div class="form-text text-warning" style="font-size:.7rem;">Whole number only · 1 COM = 2 HPL deducted</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<hr>
<div class="d-flex gap-2">
  <button class="btn btn-primary">Save Changes</button>
  <a href="index.php?month=<?= h($back ?: $data['yyyymm']) ?>" class="btn btn-outline-secondary">Cancel</a>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
