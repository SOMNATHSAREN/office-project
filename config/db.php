<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'office_db');
define('DB_USER', 'root');       // change to your MariaDB user
define('DB_PASS', '');           // change to your MariaDB password
define('DB_CHARSET', 'utf8mb4');

// Full path to mysqldump
define('MYSQLDUMP_PATH', 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
