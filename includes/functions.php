<?php
function flash(string $key, string $msg = ''): string {
    if ($msg !== '') {
        $_SESSION['flash'][$key] = $msg;
        return '';
    }
    $out = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $out;
}

function flash_html(string $type = 'success'): string {
    $msg = flash($type);
    if (!$msg) return '';
    $cls = $type === 'error' ? 'danger' : $type;
    return '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
         . htmlspecialchars($msg)
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function month_label(string $yyyymm): string {
    $d = DateTime::createFromFormat('Ym', $yyyymm);
    return $d ? $d->format('F Y') : $yyyymm;
}

// Returns the YYYYMM string for the month before the given one. e.g. 202601 → 202512
function prev_month(string $yyyymm): string {
    $d = DateTime::createFromFormat('Ym', $yyyymm);
    $d->modify('-1 month');
    return $d->format('Ym');
}

function next_month(string $yyyymm): string {
    $d = DateTime::createFromFormat('Ym', $yyyymm);
    $d->modify('+1 month');
    return $d->format('Ym');
}

/**
 * Apply a supplementary sanction.
 *
 * Rules:
 *  - EXWAGE : adds days to attendance.exwage only. No leave ledger change.
 *  - CL/EL/HPL/COM/SCL : adds to leave_ledger availed only.
 *                         Original attendance record is NOT modified.
 *                         COM adds to com_availed → triggers 2× HPL deduction.
 *  - Ledger cascades forward from absence_yyyymm after any ledger change.
 *
 * Returns true on success, false if no attendance record found for absence month.
 */
function apply_supplementary(PDO $db, int $supp_id): bool {
    $s = $db->prepare('SELECT * FROM supplementary_attendance WHERE id=? AND status="pending"');
    $s->execute([$supp_id]);
    $supp = $s->fetch();
    if (!$supp) return false;

    $perno  = $supp['sail_perno'];
    $yyyymm = $supp['absence_yyyymm'];
    $days   = (float)$supp['days'];
    $type   = $supp['leave_type'];

    // Attendance record must exist for the absence month
    $ar = $db->prepare('SELECT id FROM attendance WHERE sail_perno=? AND yyyymm=?');
    $ar->execute([$perno, $yyyymm]);
    if (!$ar->fetch()) return false;

    if ($type === 'EXWAGE') {
        // EXWAGE: update attendance exwage days only — no leave ledger impact
        $db->prepare('UPDATE attendance SET exwage = exwage + ? WHERE sail_perno=? AND yyyymm=?')
           ->execute([$days, $perno, $yyyymm]);

    } else {
        // Leave sanction: update leave_ledger availed only — attendance unchanged
        $ledger_col_map = [
            'CL'  => 'cl_availed',
            'EL'  => 'el_availed',
            'HPL' => 'hpl_availed',
            'COM' => 'com_availed',  // drives 2× HPL deduction via generated column
            // SCL has no standard ledger column — no ledger action needed
        ];

        if (isset($ledger_col_map[$type])) {
            $col  = $ledger_col_map[$type];
            $add  = ($type === 'COM') ? (int)$days : $days;

            // Upsert leave_ledger row if it doesn't exist, then increment availed
            $db->prepare("INSERT INTO leave_ledger (sail_perno, yyyymm, $col)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE $col = $col + ?")
               ->execute([$perno, $yyyymm, $add, $add]);
        }
        // SCL: recorded in supplementary table only, no ledger change

        // Cascade closing balances forward from absence month
        recalculate_ledger_chain($db, $perno, $yyyymm);
    }

    // Mark supplementary entry as applied
    $db->prepare('UPDATE supplementary_attendance SET status="applied", applied_at=NOW() WHERE id=?')
       ->execute([$supp_id]);

    return true;
}

// Returns 'active'|'inactive'|null for an employee
function employee_status(PDO $db, string $sail_perno): ?string {
    $s = $db->prepare('SELECT status FROM employees WHERE sail_perno=?');
    $s->execute([$sail_perno]);
    $row = $s->fetch();
    return $row ? $row['status'] : null;
}

// Returns true if employee exists and is active
function is_employee_active(PDO $db, string $sail_perno): bool {
    return employee_status($db, $sail_perno) === 'active';
}

// Insert one audit log row
function audit_log(PDO $db, string $action, string $description, string $sail_perno = '', string $yyyymm = ''): void {
    $db->prepare('INSERT INTO audit_log (action, sail_perno, yyyymm, username, ip_address, description)
                  VALUES (?,?,?,?,?,?)')
       ->execute([
           $action, $sail_perno, $yyyymm,
           $_SESSION['username'] ?? 'system',
           $_SERVER['REMOTE_ADDR'] ?? '',
           $description,
       ]);
}

// Returns true if the given year is locked
function is_year_locked(PDO $db, int $year): bool {
    $s = $db->prepare('SELECT locked FROM year_locks WHERE year = ?');
    $s->execute([$year]);
    $row = $s->fetch();
    return $row && (bool)$row['locked'];
}

// Returns true if the specific month is locked (month-level lock)
function is_month_locked(PDO $db, string $yyyymm): bool {
    $s = $db->prepare('SELECT locked FROM month_locks WHERE yyyymm = ?');
    $s->execute([$yyyymm]);
    $row = $s->fetch();
    return $row && (bool)$row['locked'];
}

// Returns true if a YYYYMM period is locked at either year OR month level
function is_period_locked(PDO $db, string $yyyymm): bool {
    return is_year_locked($db, year_of($yyyymm)) || is_month_locked($db, $yyyymm);
}

// Extracts the 4-digit year from a YYYYMM string
function year_of(string $yyyymm): int {
    return (int)substr($yyyymm, 0, 4);
}

// ── Role-based access control ─────────────────────────────────────────────────
// Permission map: role → list of granted permissions
function _role_perms(): array {
    return [
        'admin'   => ['emp_view','emp_write','att_view','att_write','att_lock','admin_panel','user_mgmt'],
        'manager' => ['emp_view','emp_write','att_view','att_write'],
        'hr'      => ['emp_view','emp_write','att_view'],
    ];
}

function can_do(string $perm): bool {
    $role = $_SESSION['role'] ?? 'hr';
    return in_array($perm, _role_perms()[$role] ?? [], true);
}

// Redirect with error if user lacks the permission
function require_perm(string $perm): void {
    if (!can_do($perm)) {
        flash('error', 'You do not have permission to access this page.');
        $base = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $base . '/modules/dashboard/index.php');
        exit;
    }
}

function recalculate_ledger_chain(PDO $db, string $sail_perno, string $from_yyyymm): void {
    // Fetch all ledger rows for this employee from the edited month onward, ordered
    $rows = $db->prepare(
        'SELECT id, yyyymm, cl_open, el_open, hpl_open, fl_open,
                cl_credit, el_credit, hpl_credit, fl_credit,
                cl_availed, el_availed, hpl_availed, fl_availed,
                cl_close, el_close, hpl_close, fl_close
         FROM leave_ledger
         WHERE sail_perno = ? AND yyyymm >= ?
         ORDER BY yyyymm ASC'
    );
    $rows->execute([$sail_perno, $from_yyyymm]);
    $ledger = $rows->fetchAll();

    $upd = $db->prepare(
        'UPDATE leave_ledger SET cl_open=?, el_open=?, hpl_open=?, fl_open=? WHERE id=?'
    );

    for ($i = 1; $i < count($ledger); $i++) {
        $prev    = $ledger[$i - 1];
        $current = $ledger[$i];

        // Only update if this month immediately follows the previous in the ledger
        if ($current['yyyymm'] !== next_month($prev['yyyymm'])) break;

        $upd->execute([
            $prev['cl_close'],
            $prev['el_close'],
            $prev['hpl_close'],
            $prev['fl_close'],
            $current['id'],
        ]);

        // Refresh the closing values for the next iteration (re-fetch since generated columns updated)
        $refresh = $db->prepare('SELECT cl_close, el_close, hpl_close, fl_close FROM leave_ledger WHERE id=?');
        $refresh->execute([$current['id']]);
        $ledger[$i] = array_merge($current, $refresh->fetch());
    }
}
