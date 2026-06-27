<?php
define('BASE_URL', rtrim(str_replace('/modules/employees','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $db  = get_db();
    $row = $db->prepare('SELECT sail_perno, name FROM employees WHERE id=?');
    $row->execute([$id]);
    $emp = $row->fetch();
    $db->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
    audit_log($db, 'emp_delete',
        'Employee deleted: ' . ($emp['name'] ?? '') . ' (' . ($emp['sail_perno'] ?? '') . ')',
        $emp['sail_perno'] ?? '');
    flash('success','Employee deleted.');
}
redirect(BASE_URL.'/modules/employees/index.php');
