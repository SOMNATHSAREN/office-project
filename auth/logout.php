<?php
define('BASE_URL', rtrim(str_replace('/auth','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
session_start();
session_destroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
