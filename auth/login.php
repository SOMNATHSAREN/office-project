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
        require_once __DIR__ . '/../includes/functions.php';
        $stmt = get_db()->prepare('SELECT id, username, password, role, permissions, status FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            if (($user['status'] ?? 'approved') === 'pending') {
                $error = 'Your account is awaiting admin approval.';
            } elseif (($user['status'] ?? 'approved') === 'disabled') {
                $error = 'Your account has been disabled. Contact an administrator.';
            } else {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'] ?? 'hr';
                $stored = parse_perms($user['permissions'] ?? '');
                $_SESSION['perms']    = $stored ?: perms_for_role($_SESSION['role']);
                header('Location: ' . BASE_URL . '/modules/dashboard/index.php'); exit;
            }
        } else {
            $error = 'Invalid credentials.';
        }
    }
    if (!$error) $error = 'Invalid credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In — Office Management System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{
    --brand1:#ff6a2b; --brand2:#ff3d6e;
    --ink:#201a17; --panel:#2a211d;
  }
  *{box-sizing:border-box}
  body{
    margin:0; min-height:100vh;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    background:
      radial-gradient(900px 500px at 15% 20%, #e9e3dd, transparent),
      radial-gradient(900px 500px at 85% 80%, #efe9e3, transparent),
      #f1ece7;
    display:flex; align-items:center; justify-content:center;
    padding:24px;
  }
  .shell{
    width:100%; max-width:1180px;
    display:grid; grid-template-columns:1fr 1fr;
    border-radius:28px; overflow:hidden;
    box-shadow:0 30px 70px rgba(40,28,20,.25);
    min-height:660px; background:#fff;
  }
  /* ── Left hero ── */
  .hero{
    position:relative; color:#f6f1ec;
    background:
      radial-gradient(600px 420px at 55% 30%, rgba(255,106,43,.10), transparent),
      linear-gradient(160deg,#2f2621 0%, #241d19 60%, #1c1613 100%);
    padding:44px 48px; display:flex; flex-direction:column;
  }
  .hero .tagline{font-size:.92rem; color:#c9bdb3; max-width:420px; line-height:1.5}
  .hero h1{
    margin:auto 0 0; font-weight:800; letter-spacing:-.02em;
    font-size:clamp(2.6rem,4.4vw,4rem); line-height:.98; color:#fff;
  }
  .hero .ring{
    position:absolute; top:34%; left:50%; width:360px; height:360px;
    transform:translate(-50%,-50%);
    border:1px solid rgba(255,255,255,.08); border-radius:50%;
  }
  .hero .ring::after{
    content:""; position:absolute; inset:48px; border-radius:50%;
    border:1px solid rgba(255,255,255,.06);
  }
  .hero .foot{
    display:flex; justify-content:space-between; align-items:center;
    color:#8c7f74; font-size:.8rem; margin-top:28px;
  }
  .badge-stat{
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08);
    padding:8px 14px; border-radius:999px; color:#e8ddd3; font-size:.85rem;
    backdrop-filter:blur(4px);
  }
  /* ── Right panel ── */
  .panel{ padding:44px 56px; display:flex; flex-direction:column; }
  .panel .top{ display:flex; justify-content:space-between; align-items:center; }
  .logo{ display:flex; align-items:center; gap:10px; font-weight:800; font-size:1.25rem; color:var(--ink); }
  .logo .dot{
    width:26px; height:26px; border-radius:50%;
    background:conic-gradient(from 0deg,var(--brand1),var(--brand2),#9b59b6,var(--brand1));
  }
  .panel a.link{ color:var(--brand1); text-decoration:none; font-weight:600; }
  .panel a.link:hover{ text-decoration:underline; }
  .signin-wrap{ margin:auto 0; width:100%; max-width:430px; align-self:center; }
  .signin-wrap h2{ font-weight:800; font-size:2.6rem; letter-spacing:-.02em; color:var(--ink); margin-bottom:26px; }
  .fld{
    position:relative; margin-bottom:18px;
  }
  .fld input{
    width:100%; border:1px solid #e3dcd4; border-radius:999px;
    padding:16px 22px; font-size:1rem; background:#fff; outline:none;
    transition:border-color .15s, box-shadow .15s;
  }
  .fld input:focus{ border-color:var(--brand1); box-shadow:0 0 0 4px rgba(255,106,43,.12); }
  .fld .toggle{
    position:absolute; right:18px; top:50%; transform:translateY(-50%);
    border:0; background:none; color:#9a8e82; cursor:pointer; font-size:1.1rem; padding:4px;
  }
  .forgot{ font-size:.9rem; margin:-4px 4px 22px; }
  .btn-signin{
    width:100%; border:0; border-radius:999px; color:#fff; font-weight:700; font-size:1.05rem;
    padding:16px; cursor:pointer; letter-spacing:.01em;
    background:linear-gradient(90deg,var(--brand1),var(--brand2));
    box-shadow:0 14px 30px rgba(255,61,110,.30);
    display:flex; align-items:center; justify-content:center; gap:10px;
    transition:transform .08s, box-shadow .15s;
  }
  .btn-signin:hover{ box-shadow:0 18px 36px rgba(255,61,110,.38); }
  .btn-signin:active{ transform:translateY(1px); }
  .panel .bottom{
    display:flex; justify-content:space-between; align-items:center;
    color:#a99d91; font-size:.82rem; margin-top:28px;
  }
  .alert{ border-radius:14px; }
  @media (max-width:860px){
    .shell{ grid-template-columns:1fr; min-height:0; }
    .hero{ min-height:240px; padding:32px; }
    .hero .ring{ display:none; }
    .panel{ padding:32px 26px; }
    .signin-wrap h2{ font-size:2.1rem; }
  }
</style>
</head>
<body>
<div class="shell">
  <!-- Left hero -->
  <section class="hero">
    <p class="tagline">Office Management System — attendance, employees, payroll &amp; society, all in one place.</p>
    <div class="ring"></div>
    <h1>Manage<br>your office</h1>
    <div class="foot">
      <span class="badge-stat"><i class="bi bi-shield-lock"></i> Secure access</span>
      <span>&copy; <?= date('Y') ?> Office MS</span>
    </div>
  </section>

  <!-- Right panel -->
  <section class="panel">
    <div class="top">
      <div class="logo"><span class="dot"></span> Office MS</div>
      <a class="link" href="register.php"><i class="bi bi-person-plus"></i> Sign Up</a>
    </div>

    <div class="signin-wrap">
      <h2>Sign In</h2>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <div class="fld">
          <input type="text" name="username" placeholder="Email or Username" autofocus required>
        </div>
        <div class="fld">
          <input type="password" name="password" id="pw" placeholder="Password" required>
          <button type="button" class="toggle" id="pwToggle" aria-label="Show password"><i class="bi bi-eye-slash"></i></button>
        </div>
        <div class="forgot"><a class="link" href="forgot.php">Forgot password?</a></div>
        <button class="btn-signin" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
      </form>
    </div>

    <div class="bottom">
      <span>&copy; <?= date('Y') ?> Office Management System</span>
      <span>Office MS</span>
    </div>
  </section>
</div>
<script>
  (function(){
    var pw = document.getElementById('pw'),
        tg = document.getElementById('pwToggle');
    if (tg) tg.addEventListener('click', function(){
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      tg.innerHTML = '<i class="bi bi-eye' + (show ? '' : '-slash') + '"></i>';
      tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  })();
</script>
</body>
</html>
