<?php
/**
 * CLI backup script — run via Windows Task Scheduler or command line:
 *   php C:\path\to\office_project\backup_cli.php
 *
 * Options:
 *   --compress    Compress output with gzip
 *   --keep=N      Keep last N backups (default 7)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('BASE_URL', '');
require_once __DIR__ . '/config/db.php';

$compress   = in_array('--compress', $argv ?? []);
$keep       = 7;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) $keep = (int)$m[1];
}

$backup_dir = __DIR__ . '/backups';
$timestamp  = date('YmdHis');
$filename   = 'office_db_' . $timestamp . '_auto.sql' . ($compress ? '.gz' : '');
$filepath   = $backup_dir . DIRECTORY_SEPARATOR . $filename;

$mysqldump = defined('MYSQLDUMP_PATH') && is_file(MYSQLDUMP_PATH) ? MYSQLDUMP_PATH : 'mysqldump';
$pass_arg  = DB_PASS !== '' ? '-p' . DB_PASS : '';   // CLI: no shell escaping needed for the flag itself

// Build command
$cmd = sprintf('"%s" -h %s -u %s %s --single-transaction --routines --triggers --events %s',
    $mysqldump, DB_HOST, DB_USER, $pass_arg, DB_NAME
);
$cmd .= $compress
    ? ' | gzip > "' . $filepath . '" 2>&1'
    : ' > "'        . $filepath . '" 2>&1';

echo "[" . date('Y-m-d H:i:s') . "] Running backup...\n";
system($cmd, $code);

if ($code === 0 && is_file($filepath) && filesize($filepath) > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Backup created: $filename (" . round(filesize($filepath)/1024, 1) . " KB)\n";

    // Update settings file last_auto_backup
    $cfg_file = __DIR__ . '/config/backup_settings.json';
    if (is_file($cfg_file)) {
        $cfg = json_decode(file_get_contents($cfg_file), true) ?? [];
        $cfg['last_auto_backup'] = date('Y-m-d H:i:s');
        file_put_contents($cfg_file, json_encode($cfg, JSON_PRETTY_PRINT));
    }

    // Prune old auto backups
    $auto_files = glob($backup_dir . DIRECTORY_SEPARATOR . 'office_db_*_auto.sql*') ?: [];
    usort($auto_files, fn($a,$b) => filemtime($b) - filemtime($a));
    foreach (array_slice($auto_files, $keep) as $old) {
        unlink($old);
        echo "[" . date('Y-m-d H:i:s') . "] Deleted old backup: " . basename($old) . "\n";
    }
    exit(0);
} else {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Backup failed (exit code $code)\n";
    if (is_file($filepath)) unlink($filepath);
    exit(1);
}
