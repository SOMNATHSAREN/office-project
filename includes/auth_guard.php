<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}
// Ensure role is always in session (handles sessions created before RBAC was added)
if (empty($_SESSION['role'])) {
    try {
        $__s = get_db()->prepare('SELECT role FROM users WHERE id=?');
        $__s->execute([$_SESSION['user_id']]);
        $__u = $__s->fetch();
        $_SESSION['role'] = $__u['role'] ?? 'admin';
    } catch (Exception $__e) {
        // role column not yet migrated — grant full access to preserve existing behaviour
        $_SESSION['role'] = 'admin';
    }
}
require_once __DIR__ . '/auto_backup.php';
