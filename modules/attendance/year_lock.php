<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('att_lock');
$page_title = 'Year & Month Locks';
$db = get_db();

// ── Year lock / unlock ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'], $_POST['action'])) {
    $year   = (int)$_POST['year'];
    $action = $_POST['action'];
    $by     = $_SESSION['username'] ?? 'admin';

    if ($action === 'lock_year') {
        $db->prepare('INSERT INTO year_locks (year, locked, locked_at, locked_by)
                      VALUES (?, 1, NOW(), ?)
                      ON DUPLICATE KEY UPDATE locked=1, locked_at=NOW(), locked_by=?')
           ->execute([$year, $by, $by]);
        flash('success', "Year $year locked — all months are now read-only.");
    } elseif ($action === 'unlock_year') {
        $db->prepare('UPDATE year_locks SET locked=0, locked_at=NOW(), locked_by=? WHERE year=?')
           ->execute([$by, $year]);
        flash('success', "Year $year unlocked.");
    }
    redirect(BASE_URL . '/modules/attendance/year_lock.php');
}

// ── Month lock / unlock ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yyyymm'], $_POST['month_action'])) {
    $yyyymm = preg_replace('/[^0-9]/', '', $_POST['yyyymm']);
    $action = $_POST['month_action'];
    $by     = $_SESSION['username'] ?? 'admin';

    if (strlen($yyyymm) === 6) {
        if ($action === 'lock_month') {
            $db->prepare('INSERT INTO month_locks (yyyymm, locked, locked_at, locked_by)
                          VALUES (?, 1, NOW(), ?)
                          ON DUPLICATE KEY UPDATE locked=1, locked_at=NOW(), locked_by=?')
               ->execute([$yyyymm, $by, $by]);
            flash('success', month_label($yyyymm) . ' locked.');
        } elseif ($action === 'unlock_month') {
            $db->prepare('DELETE FROM month_locks WHERE yyyymm=?')->execute([$yyyymm]);
            flash('success', month_label($yyyymm) . ' unlocked.');
        } elseif ($action === 'lock_all_months') {
            // Lock all months for a year
            $year = substr($yyyymm, 0, 4);
            $months_res = $db->prepare('SELECT DISTINCT yyyymm FROM attendance WHERE yyyymm LIKE ?');
            $months_res->execute([$year . '%']);
            $stmt = $db->prepare('INSERT INTO month_locks (yyyymm, locked, locked_at, locked_by)
                                  VALUES (?, 1, NOW(), ?)
                                  ON DUPLICATE KEY UPDATE locked=1, locked_at=NOW(), locked_by=?');
            foreach ($months_res->fetchAll(PDO::FETCH_COLUMN) as $m) {
                $stmt->execute([$m, $by, $by]);
            }
            flash('success', "All months of $year locked individually.");
        }
    }
    redirect(BASE_URL . '/modules/attendance/year_lock.php');
}

// ── Data ───────────────────────────────────────────────────────────────────────
$years_data = $db->query(
    'SELECT DISTINCT SUBSTR(yyyymm,1,4) AS yr,
            COUNT(*) AS rec_count,
            MIN(yyyymm) AS first_month,
            MAX(yyyymm) AS last_month,
            GROUP_CONCAT(DISTINCT yyyymm ORDER BY yyyymm ASC SEPARATOR ",") AS all_months
     FROM attendance GROUP BY yr ORDER BY yr DESC'
)->fetchAll();

$year_locks_map = [];
foreach ($db->query('SELECT year, locked, locked_at, locked_by FROM year_locks')->fetchAll() as $l) {
    $year_locks_map[(int)$l['year']] = $l;
}

$month_locks_map = [];
foreach ($db->query('SELECT yyyymm, locked, locked_at, locked_by FROM month_locks WHERE locked=1')->fetchAll() as $l) {
    $month_locks_map[$l['yyyymm']] = $l;
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<p class="text-muted mb-3">
  Lock a <strong>year</strong> to freeze all its months at once, or lock individual <strong>months</strong> to prevent edits on specific periods.
  A month is read-only if <em>either</em> its year lock <em>or</em> its month lock is active.
</p>

<?php foreach ($years_data as $row):
  $yr        = (int)$row['yr'];
  $ylinfo    = $year_locks_map[$yr] ?? null;
  $year_locked = $ylinfo && $ylinfo['locked'];
  $all_months  = explode(',', $row['all_months']);
?>
<div class="card mb-4">
  <!-- Year header -->
  <div class="card-header d-flex align-items-center gap-3 py-2
              <?= $year_locked ? 'bg-danger bg-opacity-10' : 'bg-light' ?>">
    <span class="fw-bold fs-5"><?= $yr ?></span>
    <span class="text-muted small"><?= $row['rec_count'] ?> records</span>

    <?php if ($year_locked): ?>
      <span class="badge bg-danger"><i class="bi bi-lock-fill me-1"></i>Year Locked</span>
      <span class="text-muted small">
        by <?= h($ylinfo['locked_by']) ?> on <?= date('d M Y H:i', strtotime($ylinfo['locked_at'])) ?>
      </span>
    <?php else: ?>
      <span class="badge bg-success"><i class="bi bi-unlock me-1"></i>Year Open</span>
    <?php endif; ?>

    <div class="ms-auto d-flex gap-2">
      <!-- Lock all individual months -->
      <?php if (!$year_locked): ?>
      <form method="post" onsubmit="return confirm('Lock all months of <?= $yr ?> individually?')">
        <input type="hidden" name="yyyymm" value="<?= $yr ?>01">
        <input type="hidden" name="month_action" value="lock_all_months">
        <button class="btn btn-sm btn-outline-warning">
          <i class="bi bi-calendar-x me-1"></i>Lock All Months
        </button>
      </form>
      <?php endif; ?>

      <!-- Year lock / unlock -->
      <form method="post" onsubmit="return confirm('<?= $year_locked ? "Unlock year $yr?" : "Lock entire year $yr? All months become read-only." ?>')">
        <input type="hidden" name="year" value="<?= $yr ?>">
        <input type="hidden" name="action" value="<?= $year_locked ? 'unlock_year' : 'lock_year' ?>">
        <?php if ($year_locked): ?>
          <button class="btn btn-sm btn-outline-success"><i class="bi bi-unlock me-1"></i>Unlock Year</button>
        <?php else: ?>
          <button class="btn btn-sm btn-danger"><i class="bi bi-lock-fill me-1"></i>Lock Year</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Month grid -->
  <div class="card-body">
    <div class="row g-2">
      <?php foreach ($all_months as $m):
        $mlinfo    = $month_locks_map[$m] ?? null;
        $m_locked  = (bool)$mlinfo;
        $effective = $year_locked || $m_locked;   // locked by either mechanism
      ?>
      <div class="col-md-2 col-sm-3 col-4">
        <div class="border rounded p-2 text-center <?= $effective ? 'border-danger bg-danger bg-opacity-5' : 'border-success bg-success bg-opacity-5' ?>">
          <div class="small fw-semibold mb-1"><?= month_label($m) ?></div>

          <!-- Lock indicator -->
          <?php if ($year_locked && $m_locked): ?>
            <div class="mb-1">
              <span class="badge bg-danger" style="font-size:.65rem;">Year+Month</span>
            </div>
          <?php elseif ($year_locked): ?>
            <div class="mb-1">
              <span class="badge bg-danger" style="font-size:.65rem;"><i class="bi bi-lock-fill"></i> Year</span>
            </div>
          <?php elseif ($m_locked): ?>
            <div class="mb-1">
              <span class="badge bg-warning text-dark" style="font-size:.65rem;"><i class="bi bi-lock-fill"></i> Month</span>
            </div>
            <div class="text-muted" style="font-size:.65rem;">
              <?= h($mlinfo['locked_by']) ?><br><?= date('d M Y', strtotime($mlinfo['locked_at'])) ?>
            </div>
          <?php else: ?>
            <div class="mb-1">
              <span class="badge bg-success" style="font-size:.65rem;"><i class="bi bi-unlock"></i> Open</span>
            </div>
          <?php endif; ?>

          <!-- Month lock/unlock button (only shown when year is not locked) -->
          <?php if (!$year_locked): ?>
          <form method="post" class="mt-1">
            <input type="hidden" name="yyyymm" value="<?= $m ?>">
            <?php if ($m_locked): ?>
              <input type="hidden" name="month_action" value="unlock_month">
              <button class="btn btn-outline-success btn-sm w-100" style="font-size:.7rem;padding:2px 4px;"
                      onclick="return confirm('Unlock <?= month_label($m) ?>?')">
                <i class="bi bi-unlock"></i> Unlock
              </button>
            <?php else: ?>
              <input type="hidden" name="month_action" value="lock_month">
              <button class="btn btn-outline-danger btn-sm w-100" style="font-size:.7rem;padding:2px 4px;"
                      onclick="return confirm('Lock <?= month_label($m) ?>?')">
                <i class="bi bi-lock-fill"></i> Lock
              </button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$years_data): ?>
  <div class="alert alert-warning">No attendance data uploaded yet. <a href="upload.php">Upload now</a>.</div>
<?php endif; ?>

<div class="alert alert-info mt-2">
  <i class="bi bi-info-circle me-2"></i>
  <strong>Lock priority:</strong>
  A <span class="badge bg-danger">Year Lock</span> freezes all months in that year at once — individual month buttons are hidden.
  A <span class="badge bg-warning text-dark">Month Lock</span> freezes only that month while the rest of the year remains editable.
  Both types block attendance edits, leave ledger credit updates, supplementary applications, and rectifications.
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
