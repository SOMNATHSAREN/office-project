<?php
define('BASE_URL', rtrim(str_replace('/modules/dashboard','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Dashboard';
$db = get_db();

// Find the last month that has attendance data
$last_att_month = $db->query("SELECT MAX(yyyymm) FROM attendance")->fetchColumn();
$cur_yyyymm     = $last_att_month ?: date('Ym');
$cur_label      = $cur_yyyymm
    ? date('F Y', mktime(0,0,0,(int)substr($cur_yyyymm,4,2),1,(int)substr($cur_yyyymm,0,4)))
    : date('F Y');

// --- KPI queries ---
$total_emp    = (int)$db->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$active_emp   = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$inactive_emp = $total_emp - $active_emp;

$stmt = $db->prepare('SELECT COUNT(*) FROM attendance WHERE yyyymm=?');
$stmt->execute([$cur_yyyymm]);
$att_this_month = (int)$stmt->fetchColumn();

// Average duty days for that month
$stmt3 = $db->prepare('SELECT ROUND(AVG(duty),1) FROM attendance WHERE yyyymm=?');
$stmt3->execute([$cur_yyyymm]);
$avg_duty = $stmt3->fetchColumn() ?: '—';

// Total audit events today
$audit_today = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Pending supplementary for the last attendance month
$stmt_supp = $db->prepare("SELECT COUNT(*) FROM supplementary_attendance WHERE status='pending' AND absence_yyyymm=?");
$stmt_supp->execute([$cur_yyyymm]);
$supp_pending = (int)$stmt_supp->fetchColumn();

// --- Department breakdown ---
$depts = $db->query("SELECT department, COUNT(*) AS cnt,
                     SUM(status='active') AS active_cnt
                     FROM employees WHERE department IS NOT NULL AND department != ''
                     GROUP BY department ORDER BY cnt DESC LIMIT 8")->fetchAll();

// --- Recent audit log (last 10) ---
$recent_audit = $db->query("SELECT a.*, e.name AS emp_name
                             FROM audit_log a
                             LEFT JOIN employees e ON e.sail_perno=a.sail_perno
                             ORDER BY a.created_at DESC LIMIT 10")->fetchAll();

// --- Month-wise attendance count (last 6 months) ---
$month_trend = $db->query("SELECT yyyymm, COUNT(*) AS cnt FROM attendance
                            WHERE yyyymm >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH),'%Y%m')
                            GROUP BY yyyymm ORDER BY yyyymm")->fetchAll();

// --- Leave summary this month (totals) ---
$leave_sum = $db->prepare("SELECT
    SUM(cl) AS cl, SUM(el) AS el, SUM(hpl) AS hpl,
    SUM(fl_coff) AS fl, SUM(com) AS com, SUM(abs) AS abs
    FROM attendance WHERE yyyymm=?");
$leave_sum->execute([$cur_yyyymm]);
$lv = $leave_sum->fetch(PDO::FETCH_ASSOC);

// --- Action badges for audit ---
$action_badges = [
    'upload'        => ['primary',   'Upload'],
    'edit'          => ['warning',   'Att Edit'],
    'supp_add'      => ['info',      'Supp Add'],
    'supp_apply'    => ['success',   'Supp Apply'],
    'supp_cancel'   => ['secondary', 'Supp Cancel'],
    'rectify'       => ['warning',   'Rectify'],
    'ledger_credit' => ['info',      'Ledger Credit'],
    'lock'          => ['danger',    'Lock'],
    'unlock'        => ['success',   'Unlock'],
    'emp_add'       => ['success',   'Emp Add'],
    'emp_edit'      => ['warning',   'Emp Edit'],
    'emp_delete'    => ['danger',    'Emp Delete'],
];

include __DIR__ . '/../../includes/header.php';
?>

<style>
.kpi-card { border-radius: 14px; border: none; box-shadow: 0 2px 12px rgba(0,0,0,.07); transition: transform .15s; }
.kpi-card:hover { transform: translateY(-3px); }
.kpi-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.kpi-val  { font-size: 2rem; font-weight: 700; line-height: 1; }
.kpi-lbl  { font-size: .78rem; color: #6c757d; margin-top: 2px; }
.section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #6c757d; margin-bottom: .75rem; }
.trend-bar { height: 8px; border-radius: 4px; background: #e9ecef; overflow: hidden; }
.trend-bar-fill { height: 100%; border-radius: 4px; }
.quick-btn { border-radius: 10px; padding: .6rem 1rem; font-size: .82rem; font-weight: 600; display: flex; align-items: center; gap: .5rem; transition: all .15s; }
.quick-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.audit-row td { vertical-align: middle; font-size: .8rem; }
.dept-row { padding: .45rem 0; border-bottom: 1px solid #f0f0f0; }
.dept-row:last-child { border-bottom: none; }
.greeting-card { background: linear-gradient(135deg,#1a2340 0%,#2d3f6e 100%); color: #fff; border-radius: 16px; border: none; }
</style>

<?= flash_html('success') ?><?= flash_html('error') ?>

<!-- Greeting Banner -->
<div class="card greeting-card mb-4 px-4 py-3 d-flex flex-row align-items-center justify-content-between">
  <div>
    <div class="fw-bold fs-5">Welcome back, <?= h($_SESSION['username'] ?? 'Admin') ?> 👋</div>
    <div style="opacity:.7;font-size:.85rem;"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Showing data for <strong><?= $cur_label ?></strong> (last uploaded month)</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/attendance/upload.php" class="btn btn-light btn-sm fw-semibold">
      <i class="bi bi-upload me-1"></i>Upload CSV
    </a>
    <a href="<?= BASE_URL ?>/modules/attendance/index.php" class="btn btn-outline-light btn-sm fw-semibold">
      <i class="bi bi-calendar3 me-1"></i>View Attendance
    </a>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">

  <div class="col-6 col-md-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-start justify-content-between mb-2">
        <div class="kpi-icon" style="background:#e8f4fd;color:#0d6efd;"><i class="bi bi-people-fill"></i></div>
        <span class="badge bg-primary-subtle text-primary" style="font-size:.7rem;">Total</span>
      </div>
      <div class="kpi-val text-primary"><?= $total_emp ?></div>
      <div class="kpi-lbl">Total Employees</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-start justify-content-between mb-2">
        <div class="kpi-icon" style="background:#e8f8f0;color:#198754;"><i class="bi bi-person-check-fill"></i></div>
        <span class="badge bg-success-subtle text-success" style="font-size:.7rem;">Active</span>
      </div>
      <div class="kpi-val text-success"><?= $active_emp ?></div>
      <div class="kpi-lbl">Active Employees</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-start justify-content-between mb-2">
        <div class="kpi-icon" style="background:#fff3e0;color:#fd7e14;"><i class="bi bi-calendar2-check-fill"></i></div>
        <span class="badge bg-warning-subtle text-warning" style="font-size:.7rem;"><?= $cur_label ?></span>
      </div>
      <div class="kpi-val text-warning"><?= $att_this_month ?></div>
      <div class="kpi-lbl">Attendance Records</div>
    </div>
  </div>

  <div class="col-6 col-md-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-start justify-content-between mb-2">
        <div class="kpi-icon" style="background:#fce8f8;color:#d63384;"><i class="bi bi-patch-plus-fill"></i></div>
        <span class="badge bg-danger-subtle text-danger" style="font-size:.7rem;">Pending</span>
      </div>
      <div class="kpi-val" style="color:#d63384;"><?= $supp_pending ?></div>
      <div class="kpi-lbl">Supp. Pending</div>
    </div>
  </div>

</div>

<!-- Row 2: Quick Actions + Leave Summary + Dept Breakdown -->
<div class="row g-3 mb-4">

  <!-- Quick Actions -->
  <div class="col-md-4">
    <div class="card h-100 p-3">
      <div class="section-title"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Quick Actions</div>
      <div class="d-flex flex-column gap-2">
        <a href="<?= BASE_URL ?>/modules/employees/add.php" class="btn btn-outline-primary quick-btn">
          <i class="bi bi-person-plus-fill"></i> Add Employee
        </a>
        <a href="<?= BASE_URL ?>/modules/attendance/upload.php" class="btn btn-outline-success quick-btn">
          <i class="bi bi-cloud-upload-fill"></i> Upload Attendance CSV
        </a>
        <a href="<?= BASE_URL ?>/modules/attendance/supplementary.php" class="btn btn-outline-info quick-btn">
          <i class="bi bi-patch-plus-fill"></i> Add Supplementary
        </a>
        <a href="<?= BASE_URL ?>/modules/attendance/ledger.php" class="btn btn-outline-secondary quick-btn">
          <i class="bi bi-journal-text"></i> Leave Ledger
        </a>
        <a href="<?= BASE_URL ?>/modules/admin/audit_log.php" class="btn btn-outline-dark quick-btn">
          <i class="bi bi-shield-check"></i> Audit Log
        </a>
        <a href="<?= BASE_URL ?>/modules/admin/backup.php" class="btn btn-outline-danger quick-btn">
          <i class="bi bi-database-fill-add"></i> Backup DB
        </a>
      </div>
    </div>
  </div>

  <!-- Leave Summary This Month -->
  <div class="col-md-4">
    <div class="card h-100 p-3">
      <div class="section-title"><i class="bi bi-bar-chart-fill text-info me-1"></i>Leave Summary — <?= $cur_label ?></div>
      <?php if ($att_this_month === 0): ?>
        <div class="text-center text-muted py-4" style="font-size:.85rem;">
          <i class="bi bi-inbox fs-3 d-block mb-2 opacity-40"></i>No attendance data for this month
        </div>
      <?php else: ?>
      <?php
        $leave_items = [
          'CL'      => ['cl',  '#0d6efd'],
          'EL'      => ['el',  '#198754'],
          'HPL'     => ['hpl', '#fd7e14'],
          'FL/COFF' => ['fl',  '#6f42c1'],
          'COM'     => ['com', '#d63384'],
          'Absence' => ['abs', '#dc3545'],
        ];
        $max_lv = max(1, max(array_filter(array_map(fn($k)=>(float)($lv[$k[0]]??0), $leave_items))));
      ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($leave_items as $label => [$key, $color]): ?>
        <?php $val = round((float)($lv[$key] ?? 0), 1); ?>
        <div>
          <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;">
            <span class="fw-semibold"><?= $label ?></span>
            <span style="color:<?= $color ?>;font-weight:600;"><?= $val ?> days</span>
          </div>
          <div class="trend-bar">
            <div class="trend-bar-fill" style="background:<?= $color ?>;width:<?= $val>0 ? max(4, round($val/$max_lv*100)) : 0 ?>%;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 pt-2 border-top d-flex gap-3" style="font-size:.75rem;color:#6c757d;">
        <span><i class="bi bi-calendar3 me-1"></i>Avg Duty: <strong><?= $avg_duty ?></strong> days</span>
        <span><i class="bi bi-people me-1"></i><?= $att_this_month ?> records</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Department Breakdown -->
  <div class="col-md-4">
    <div class="card h-100 p-3">
      <div class="section-title"><i class="bi bi-diagram-3-fill text-success me-1"></i>Department Breakdown</div>
      <?php if (!$depts): ?>
        <div class="text-muted text-center py-4" style="font-size:.85rem;">No department data</div>
      <?php else: ?>
      <?php $max_dept = max(array_column($depts, 'cnt')); ?>
      <?php
        $dept_colors = ['#0d6efd','#198754','#fd7e14','#6f42c1','#d63384','#0dcaf0','#ffc107','#dc3545'];
        $ci = 0;
      ?>
      <div class="d-flex flex-column">
        <?php foreach ($depts as $d): ?>
        <?php $pct = round($d['cnt'] / max(1,$max_dept) * 100); $c = $dept_colors[$ci++ % count($dept_colors)]; ?>
        <div class="dept-row">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span style="font-size:.8rem;font-weight:600;"><?= h($d['department'] ?: '(No dept)') ?></span>
            <span class="badge" style="background:<?= $c ?>15;color:<?= $c ?>;font-size:.7rem;"><?= $d['cnt'] ?> emp</span>
          </div>
          <div class="trend-bar">
            <div class="trend-bar-fill" style="background:<?= $c ?>;width:<?= max(4,$pct) ?>%;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 pt-2 border-top" style="font-size:.75rem;color:#6c757d;">
        <i class="bi bi-person-x me-1"></i>Inactive: <strong><?= $inactive_emp ?></strong> employees
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Row 3: Attendance Trend + Recent Audit -->
<div class="row g-3">

  <!-- Attendance Month Trend -->
  <div class="col-md-5">
    <div class="card p-3">
      <div class="section-title"><i class="bi bi-graph-up-arrow text-primary me-1"></i>Attendance Records — Last 6 Months</div>
      <?php if (!$month_trend): ?>
        <div class="text-muted text-center py-4" style="font-size:.85rem;"><i class="bi bi-inbox d-block fs-3 mb-2 opacity-40"></i>No data yet</div>
      <?php else: ?>
      <?php $max_tr = max(array_column($month_trend,'cnt')); ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($month_trend as $tr): ?>
        <?php
          $pct = round($tr['cnt'] / max(1,$max_tr) * 100);
          $mlabel = strlen($tr['yyyymm']) === 6
            ? date('M Y', mktime(0,0,0,(int)substr($tr['yyyymm'],4,2),1,(int)substr($tr['yyyymm'],0,4)))
            : $tr['yyyymm'];
          $is_cur = ($tr['yyyymm'] == $cur_yyyymm);
        ?>
        <div>
          <div class="d-flex justify-content-between mb-1" style="font-size:.8rem;">
            <span <?= $is_cur ? 'class="fw-bold text-primary"' : '' ?>><?= $mlabel ?><?= $is_cur ? ' ●' : '' ?></span>
            <span class="text-muted"><?= $tr['cnt'] ?> records</span>
          </div>
          <div class="trend-bar">
            <div class="trend-bar-fill" style="background:<?= $is_cur ? '#0d6efd' : '#6c757d' ?>;width:<?= max(4,$pct) ?>%;opacity:<?= $is_cur ? '1' : '.5' ?>;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Audit Activity -->
  <div class="col-md-7">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="section-title mb-0"><i class="bi bi-activity text-danger me-1"></i>Recent Activity</div>
        <div class="d-flex align-items-center gap-2">
          <?php if ($audit_today > 0): ?>
            <span class="badge bg-danger-subtle text-danger" style="font-size:.7rem;"><?= $audit_today ?> today</span>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/modules/admin/audit_log.php" class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;">
            View All <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>
      </div>
      <?php if (!$recent_audit): ?>
        <div class="text-muted text-center py-4" style="font-size:.85rem;"><i class="bi bi-shield-slash d-block fs-3 mb-2 opacity-40"></i>No activity yet</div>
      <?php else: ?>
      <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead style="font-size:.72rem;color:#6c757d;">
          <tr>
            <th>Time</th>
            <th>Action</th>
            <th>Employee</th>
            <th>User</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent_audit as $l):
          [$bcol, $blabel] = $action_badges[$l['action']] ?? ['secondary', $l['action']];
          $desc_short = mb_strimwidth($l['description'] ?? '', 0, 60, '…');
          $time_diff = time() - strtotime($l['created_at']);
          if ($time_diff < 60)        $time_str = 'Just now';
          elseif ($time_diff < 3600)  $time_str = floor($time_diff/60) . 'm ago';
          elseif ($time_diff < 86400) $time_str = floor($time_diff/3600) . 'h ago';
          else                        $time_str = date('d M', strtotime($l['created_at']));
        ?>
        <tr class="audit-row">
          <td class="text-muted text-nowrap"><?= $time_str ?></td>
          <td><span class="badge bg-<?= $bcol ?>" style="font-size:.67rem;"><?= $blabel ?></span></td>
          <td class="text-nowrap">
            <?php if ($l['emp_name']): ?>
              <span class="fw-semibold" style="font-size:.78rem;"><?= h($l['emp_name']) ?></span>
            <?php elseif ($l['sail_perno']): ?>
              <code style="font-size:.72rem;"><?= h($l['sail_perno']) ?></code>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-dark" style="font-size:.67rem;"><?= h($l['username']) ?></span></td>
          <td class="text-muted" style="max-width:200px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"
              title="<?= h($l['description'] ?? '') ?>"><?= h($desc_short) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
