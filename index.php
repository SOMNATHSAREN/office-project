<?php
define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
} else {
    header('Location: ' . BASE_URL . '/auth/login.php');
}
exit;
