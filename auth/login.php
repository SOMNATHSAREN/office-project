<?php
define('BASE_URL', rtrim(str_replace('/auth','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../config/db.php';
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $stmt = get_db()->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'] ?? 'hr';
            header('Location: ' . BASE_URL . '/modules/dashboard/index.php'); exit;
        }
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Office Management System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background: #1a2340; min-height: 100vh; display:flex; align-items:center; justify-content:center; }
  .login-card { width: 100%; max-width: 380px; }
</style>
</head>
<body>
<div class="login-card">
  <div class="text-center mb-4">
    <span class="fs-3 text-white fw-bold">&#127970; Office MS</span>
    <p class="text-secondary mt-1 mb-0">Sign in to continue</p>
  </div>
  <div class="card shadow-sm p-4">
    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label fw-semibold">Username</label>
        <input type="text" name="username" class="form-control" autofocus required>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-primary w-100">Sign In</button>
    </form>
  </div>
  <p class="text-center text-secondary mt-3 small">Default: admin / admin123</p>
</div>
</body>
</html>
