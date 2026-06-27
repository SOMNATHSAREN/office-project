<?php
define('BASE_URL', rtrim(str_replace('/modules/admin','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_perm('user_mgmt');

$id = (int)($_GET['id'] ?? 0);
$db = get_db();

if ($id && $id != $_SESSION['user_id']) {
    $row = $db->prepare('SELECT username, role FROM users WHERE id=?');
    $row->execute([$id]);
    $user = $row->fetch();
    if ($user) {
        // Prevent deleting last admin
        if ($user['role'] === 'admin') {
            $admin_count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            if ($admin_count <= 1) {
                flash('error', 'Cannot delete the last Admin user.');
                redirect(BASE_URL . '/modules/admin/users.php');
            }
        }
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        audit_log($db, 'user_delete', 'User deleted: ' . $user['username'] . ' | Role: ' . $user['role']);
        flash('success', 'User "' . $user['username'] . '" deleted.');
    }
} else {
    flash('error', 'Cannot delete yourself.');
}
redirect(BASE_URL . '/modules/admin/users.php');
