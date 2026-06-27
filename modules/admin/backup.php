<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('admin_panel');
$page_title  = 'Database Backup';
$backup_dir  = realpath(__DIR__ . '/../../backups');
$mysqldump   = defined('MYSQLDUMP_PATH') && is_file(MYSQLDUMP_PATH) ? MYSQLDUMP_PATH : 'mysqldump';
$cfg_file    = __DIR__ . '/../../config/backup_settings.json';
$auto_cfg    = is_file($cfg_file) ? (json_decode(file_get_contents($cfg_file), true) ?? []) : [];

// ── Save auto-backup settings ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto'])) {
    $auto_cfg['enabled']    = !empty($_POST['auto_enabled']);
    $auto_cfg['frequency']  = in_array($_POST['frequency'] ?? '', ['daily','weekly','monthly']) ? $_POST['frequency'] : 'daily';
    $auto_cfg['hour']       = max(0, min(23, (int)($_POST['hour'] ?? 2)));
    $auto_cfg['keep_count'] = max(1, min(90, (int)($_POST['keep_count'] ?? 7)));
    $auto_cfg['compress']   = !empty($_POST['auto_compress']);
    file_put_contents($cfg_file, json_encode($auto_cfg, JSON_PRETTY_PRINT));
    flash('success', 'Auto-backup settings saved.');
    redirect(BASE_URL . '/modules/admin/backup.php');
}

// ── Test / force auto-backup now ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_auto'])) {
    $timestamp = date('YmdHis');
    $filename  = 'office_db_' . $timestamp . '_auto.sql';
    $filepath  = $backup_dir . DIRECTORY_SEPARATOR . $filename;
    $errfile   = $backup_dir . DIRECTORY_SEPARATOR . 'auto_err_' . $timestamp . '.txt';

    $cmd = sprintf(
        '"%s" -h %s -u %s %s --single-transaction --routines --triggers --events --no-tablespaces %s > "%s" 2> "%s"',
        str_replace('/', '\\', $mysqldump),
        DB_HOST, DB_USER,
        DB_PASS !== '' ? '--password=' . DB_PASS : '',
        DB_NAME, $filepath, $errfile
    );
    exec($cmd, $out, $code);

    $err = is_file($errfile) ? trim(file_get_contents($errfile)) : '';
    if (is_file($errfile)) @unlink($errfile);
    $real_err = trim(preg_replace('/^mysqldump: \[Warning\].*\n?/m', '', $err));

    if (is_file($filepath) && filesize($filepath) > 500) {
        // Update last_auto_backup timestamp in settings
        $auto_cfg['last_auto_backup'] = date('Y-m-d H:i:s');
        file_put_contents($cfg_file, json_encode($auto_cfg, JSON_PRETTY_PRINT));

        // Prune old auto-backups
        $keep       = max(1, (int)($auto_cfg['keep_count'] ?? 7));
        $auto_files = glob($backup_dir . DIRECTORY_SEPARATOR . 'office_db_*_auto.sql*') ?: [];
        usort($auto_files, fn($a,$b) => filemtime($b) - filemtime($a));
        foreach (array_slice($auto_files, $keep) as $old) @unlink($old);

        flash('success', 'Auto-backup test successful: ' . $filename . ' (' . human_size(filesize($filepath)) . ')');
    } else {
        if (is_file($filepath)) @unlink($filepath);
        flash('error', 'Auto-backup test failed (exit code ' . $code . ').' . ($real_err ? ' Error: ' . $real_err : ''));
    }
    redirect(BASE_URL . '/modules/admin/backup.php');
}

// ── Download a backup file ─────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);                     // strip any path traversal
    $path = $backup_dir . DIRECTORY_SEPARATOR . $file;
    if (!preg_match('/^office_db_\d{14}(_auto)?\.sql(\.gz)?$/', $file) || !is_file($path)) {
        flash('error', 'Invalid or missing backup file.');
        redirect(BASE_URL . '/modules/admin/backup.php');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Delete a backup file ───────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $path = $backup_dir . DIRECTORY_SEPARATOR . $file;
    if (preg_match('/^office_db_\d{14}(_auto)?\.sql(\.gz)?$/', $file) && is_file($path)) {
        unlink($path);
        flash('success', 'Backup deleted: ' . $file);
    } else {
        flash('error', 'Invalid or missing backup file.');
    }
    redirect(BASE_URL . '/modules/admin/backup.php');
}

// ── Create a new backup ────────────────────────────────────────────────────────
$backup_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    $timestamp = date('YmdHis');
    $filename  = 'office_db_' . $timestamp . '.sql';
    $filepath  = $backup_dir . DIRECTORY_SEPARATOR . $filename;
    $errfile   = $backup_dir . DIRECTORY_SEPARATOR . 'dump_err_' . $timestamp . '.txt';

    // Build password argument (no space between -p and password for MySQL 8)
    $pass_arg = DB_PASS !== '' ? '--password=' . DB_PASS : '--no-tablespaces';

    // Windows-safe command — redirect stderr to a temp error file, stdout to sql file
    $cmd = sprintf(
        '"%s" -h %s -u %s %s --single-transaction --routines --triggers --events --no-tablespaces %s > "%s" 2> "%s"',
        str_replace('/', '\\', $mysqldump),
        DB_HOST,
        DB_USER,
        DB_PASS !== '' ? '--password=' . DB_PASS : '',
        DB_NAME,
        $filepath,
        $errfile
    );

    $code = 0;
    exec($cmd, $out, $code);

    $err_output = is_file($errfile) ? trim(file_get_contents($errfile)) : '';
    if (is_file($errfile)) @unlink($errfile);

    // MySQL 8 prints a warning about passwords to stderr even on success — ignore it
    $real_error = preg_replace('/^mysqldump: \[Warning\].*\n?/m', '', $err_output);
    $real_error = trim($real_error);

    if (is_file($filepath) && filesize($filepath) > 500) {
        flash('success', 'Backup created: ' . $filename . ' (' . human_size(filesize($filepath)) . ')');
    } else {
        if (is_file($filepath)) unlink($filepath);
        $backup_error = 'Backup failed (exit code ' . $code . ').';
        if ($real_error) $backup_error .= ' Error: ' . $real_error;
    }
    redirect(BASE_URL . '/modules/admin/backup.php');
}

// ── List existing backups ──────────────────────────────────────────────────────
$files = [];
foreach (glob($backup_dir . DIRECTORY_SEPARATOR . 'office_db_*.sql*') as $f) {
    $name = basename($f);
    if (preg_match('/^office_db_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(_auto)?\.sql/', $name, $m)) {
        $files[] = [
            'name'       => $name,
            'size'       => filesize($f),
            'created'    => sprintf('%s-%s-%s %s:%s:%s', $m[1],$m[2],$m[3],$m[4],$m[5],$m[6]),
            'compressed' => str_ends_with($name, '.gz'),
            'auto'       => isset($m[7]) && $m[7] === '_auto',
        ];
    }
}
usort($files, fn($a,$b) => strcmp($b['created'], $a['created']));

function human_size(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes/1048576, 2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes/1024,    2) . ' KB';
    return $bytes . ' B';
}

// Auto-delete backups older than N days (keep last 30 by default)
$keep_days = 30;
$now = time();
foreach ($files as $f) {
    $path = $backup_dir . DIRECTORY_SEPARATOR . $f['name'];
    if (($now - filemtime($path)) > ($keep_days * 86400)) {
        // Don't auto-delete here — just mark for display
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('success') ?><?= flash_html('error') ?>

<?php if ($backup_error): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($backup_error) ?></div>
<?php endif; ?>

<div class="row g-4">

<!-- ── Create backup ────────────────────────────────────────────────────── -->
<div class="col-lg-4">
  <div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-database-add me-2"></i>Create Backup</div>
    <div class="card-body">
      <form method="post">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Database</label>
          <input type="text" class="form-control form-control-sm" value="<?= h(DB_NAME) ?>" disabled>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Format</label>
          <input type="text" class="form-control form-control-sm" value="Plain SQL (.sql)" disabled>
          <div class="form-text">Download and zip locally if needed</div>
        </div>
        <button name="create_backup" value="1" class="btn btn-primary w-100">
          <i class="bi bi-download me-1"></i>Create Backup Now
        </button>
      </form>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body p-3">
      <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle me-1 text-primary"></i>Backup Info</h6>
      <ul class="mb-0 small text-muted ps-3">
        <li>Backups are stored in the <code>backups/</code> folder</li>
        <li>Direct web access to backup files is blocked</li>
        <li>Uses <code>mysqldump</code> with <code>--single-transaction</code></li>
        <li>Includes routines, triggers, and events</li>
        <li>Download and store backups off-server regularly</li>
      </ul>
    </div>
  </div>

  <!-- Diagnostics -->
  <div class="card mt-3">
    <div class="card-header fw-semibold py-2"><i class="bi bi-tools me-2"></i>Diagnostics</div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0" style="font-size:.8rem;">
        <tbody>
          <?php
          $checks = [
              'mysqldump path'      => [$mysqldump, is_file($mysqldump)],
              'backups/ writable'   => [$backup_dir, is_writable($backup_dir)],
              'exec() enabled'      => ['php.ini', function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))],
              'DB connection'       => [DB_HOST . ' / ' . DB_NAME, true],
          ];
          foreach ($checks as $label => [$detail, $ok]):
          ?>
          <tr>
            <td class="ps-3"><?= $label ?></td>
            <td class="text-muted" style="max-width:220px;word-break:break-all;"><?= h($detail) ?></td>
            <td><?= $ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">FAIL</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Auto-backup settings -->
  <div class="card mt-3">
    <div class="card-header fw-semibold d-flex align-items-center gap-2 py-2">
      <i class="bi bi-clock-history text-success"></i>Auto Backup
      <?php if (!empty($auto_cfg['enabled'])): ?>
        <span class="badge bg-success ms-auto">Enabled</span>
      <?php else: ?>
        <span class="badge bg-secondary ms-auto">Disabled</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" name="auto_enabled" id="auto_enabled"
                 value="1" <?= !empty($auto_cfg['enabled']) ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="auto_enabled">Enable Auto Backup</label>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Frequency</label>
          <select name="frequency" class="form-select form-select-sm">
            <?php foreach (['daily'=>'Daily','weekly'=>'Weekly (Mon)','monthly'=>'Monthly (1st)'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($auto_cfg['frequency'] ?? 'daily')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Run at hour (0–23)</label>
          <input type="number" name="hour" class="form-control form-control-sm"
                 min="0" max="23" value="<?= h($auto_cfg['hour'] ?? 2) ?>">
          <div class="form-text">Triggers on first page load at or after this hour</div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Keep last N backups</label>
          <input type="number" name="keep_count" class="form-control form-control-sm"
                 min="1" max="90" value="<?= h($auto_cfg['keep_count'] ?? 7) ?>">
        </div>
        <div class="mb-3 form-check">
          <input type="checkbox" class="form-check-input" name="auto_compress" id="auto_compress"
                 value="1" <?= !empty($auto_cfg['compress']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="auto_compress">Compress with gzip</label>
        </div>
        <?php if (!empty($auto_cfg['last_auto_backup'])): ?>
          <div class="alert alert-info py-1 small mb-2">
            <i class="bi bi-check-circle me-1"></i>Last auto backup: <strong><?= h($auto_cfg['last_auto_backup']) ?></strong>
          </div>
        <?php endif; ?>
        <button name="save_auto" value="1" class="btn btn-success btn-sm w-100">
          <i class="bi bi-save me-1"></i>Save Auto Backup Settings
        </button>
      </form>

      <hr class="my-2">
      <form method="post">
        <button name="test_auto" value="1" class="btn btn-outline-primary btn-sm w-100"
                onclick="return confirm('Run an auto-backup right now to test it?')">
          <i class="bi bi-play-circle me-1"></i>Test Auto Backup Now
        </button>
      </form>
    </div>
  </div>

  <!-- Task Scheduler setup -->
  <div class="card mt-3 border-secondary">
    <div class="card-header fw-semibold py-2 text-secondary">
      <i class="bi bi-terminal me-1"></i>Windows Task Scheduler (Reliable)
    </div>
    <div class="card-body p-3 small">
      <p class="text-muted mb-2">Register backup_schedule.bat for guaranteed daily runs:</p>
      <code class="d-block bg-light p-2 rounded" style="font-size:.72rem; white-space:pre-wrap;">schtasks /create /tn "OfficeProjectBackup" /tr "<?= h(str_replace('/','\\',$_SERVER['DOCUMENT_ROOT'])) ?>\office_project\backup_schedule.bat" /sc daily /st 02:00 /ru SYSTEM /f</code>
      <p class="text-muted mt-2 mb-1">Check it runs correctly:</p>
      <code class="d-block bg-light p-2 rounded" style="font-size:.72rem;">schtasks /run /tn "OfficeProjectBackup"</code>
      <p class="text-muted mt-2 mb-0">Log file: <code>backups/backup.log</code></p>
    </div>
  </div>

  <!-- Restore instructions -->
  <div class="card mt-3 border-warning">
    <div class="card-header fw-semibold text-warning py-2">
      <i class="bi bi-arrow-counterclockwise me-1"></i>Restore Instructions
    </div>
    <div class="card-body p-3 small">
      <p class="text-muted mb-2">To restore a backup, download the file then run:</p>
      <code class="d-block bg-light p-2 rounded" style="font-size:.75rem;">
        mysql -u root -p <?= h(DB_NAME) ?> &lt; office_db_TIMESTAMP.sql
      </code>
      <p class="text-muted mt-2 mb-0">For compressed backups:</p>
      <code class="d-block bg-light p-2 rounded mt-1" style="font-size:.75rem;">
        gunzip -c office_db_TIMESTAMP.sql.gz | mysql -u root -p <?= h(DB_NAME) ?>
      </code>
      <p class="text-danger mt-2 mb-0 fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Restore overwrites all current data.</p>
    </div>
  </div>
</div>

<!-- ── Backup list ───────────────────────────────────────────────────────── -->
<div class="col-lg-8">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
      <span class="fw-semibold"><i class="bi bi-archive me-2"></i>Backup Files</span>
      <span class="badge bg-secondary"><?= count($files) ?> backup<?= count($files)!=1?'s':'' ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (!$files): ?>
        <p class="text-muted text-center py-4 mb-0">No backups yet. Create your first backup.</p>
      <?php else: ?>
      <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.85rem;">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Filename</th>
            <th>Created</th>
            <th class="text-end">Size</th>
            <th>Type</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $i => $f): ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td>
              <i class="bi bi-file-earmark-zip<?= $f['compressed']?'':'-fill' ?> me-1 text-muted"></i>
              <code style="font-size:.78rem;"><?= h($f['name']) ?></code>
            </td>
            <td><?= date('d M Y H:i:s', strtotime($f['created'])) ?></td>
            <td class="text-end"><?= human_size($f['size']) ?></td>
            <td>
              <?php if ($f['auto']): ?>
                <span class="badge bg-success">Auto</span>
              <?php else: ?>
                <span class="badge bg-primary">Manual</span>
              <?php endif; ?>
              <?php if ($f['compressed']): ?>
                <span class="badge bg-info text-dark ms-1">GZ</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <a href="?download=<?= urlencode($f['name']) ?>"
                 class="btn btn-outline-primary btn-sm" title="Download">
                <i class="bi bi-download"></i>
              </a>
              <a href="?delete=<?= urlencode($f['name']) ?>"
                 class="btn btn-outline-danger btn-sm ms-1"
                 title="Delete"
                 onclick="return confirm('Delete backup <?= h($f['name']) ?>? This cannot be undone.')">
                <i class="bi bi-trash"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td colspan="3" class="text-end text-muted small fw-semibold">Total size:</td>
            <td class="text-end fw-semibold"><?= human_size(array_sum(array_column($files,'size'))) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- /row -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
