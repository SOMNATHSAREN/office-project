<?php
/**
 * Auto-backup check — included on every admin page load.
 * If auto-backup is enabled and the schedule is due, runs backup silently.
 */
function auto_backup_check(): void {
    $cfg_file  = __DIR__ . '/../config/backup_settings.json';
    $backup_dir = __DIR__ . '/../backups';

    if (!is_file($cfg_file)) return;
    $cfg = json_decode(file_get_contents($cfg_file), true);
    if (empty($cfg['enabled'])) return;

    $now       = time();
    $last      = $cfg['last_auto_backup'] ? strtotime($cfg['last_auto_backup']) : 0;
    $hour      = (int)($cfg['hour'] ?? 2);
    $freq      = $cfg['frequency'] ?? 'daily';

    // Calculate next due time
    $today_run = mktime($hour, 0, 0, date('n'), date('j'), date('Y'));

    $due = false;
    if ($freq === 'daily') {
        $due = ($now >= $today_run) && ($last < $today_run);
    } elseif ($freq === 'weekly') {
        // Run on Monday of each week
        $week_start = strtotime('last Monday', $today_run + 86400);
        $due = ($now >= $week_start) && ($last < $week_start);
    } elseif ($freq === 'monthly') {
        // Run on 1st of each month
        $month_run = mktime($hour, 0, 0, date('n'), 1, date('Y'));
        $due = ($now >= $month_run) && ($last < $month_run);
    }

    if (!$due) return;

    // Run the backup
    $timestamp = date('YmdHis');
    $compress  = !empty($cfg['compress']);
    $filename  = 'office_db_' . $timestamp . '_auto.sql' . ($compress ? '.gz' : '');
    $filepath  = $backup_dir . DIRECTORY_SEPARATOR . $filename;

    $mysqldump = defined('MYSQLDUMP_PATH') && is_file(MYSQLDUMP_PATH) ? MYSQLDUMP_PATH : 'mysqldump';
    $errfile   = $backup_dir . DIRECTORY_SEPARATOR . 'auto_err_' . date('YmdHis') . '.txt';
    $cmd = sprintf(
        '"%s" -h %s -u %s %s --single-transaction --routines --triggers --events --no-tablespaces %s > "%s" 2> "%s"',
        str_replace('/', '\\', $mysqldump),
        DB_HOST, DB_USER,
        DB_PASS !== '' ? '--password=' . DB_PASS : '',
        DB_NAME, $filepath, $errfile
    );

    exec($cmd, $out, $code);
    if (is_file($errfile)) @unlink($errfile);

    if ($code === 0 && is_file($filepath) && filesize($filepath) > 500) {
        // Update last backup timestamp
        $cfg['last_auto_backup'] = date('Y-m-d H:i:s');
        file_put_contents($cfg_file, json_encode($cfg, JSON_PRETTY_PRINT));

        // Prune old auto-backups — keep only the N most recent
        $keep = max(1, (int)($cfg['keep_count'] ?? 7));
        $auto_files = glob($backup_dir . DIRECTORY_SEPARATOR . 'office_db_*_auto.sql*');
        if ($auto_files) {
            usort($auto_files, fn($a,$b) => filemtime($b) - filemtime($a));
            foreach (array_slice($auto_files, $keep) as $old) {
                @unlink($old);
            }
        }
    } else {
        if (is_file($filepath)) @unlink($filepath);
    }
}

auto_backup_check();
