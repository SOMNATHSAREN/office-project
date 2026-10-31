<?php
define('BASE_URL', rtrim(str_replace('/auth','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php'); exit;
}

$error = ''; $done = false;
// Neutral message — shown whether or not the account exists (don't leak which emails are registered).
$NEUTRAL = 'If an account matches that email or username, a password-reset request has been sent to the administrator. '
         . 'They will set a new password for you and let you know.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = trim($_POST['username'] ?? '');
    if ($ident === '') {
        $error = 'Enter your email or username.';
    } else {
        try {
            $db = get_db();
            $stmt = $db->prepare('SELECT id, username, email, status FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$ident, $ident]);
            $user = $stmt->fetch();
            if ($user && ($user['status'] ?? 'approved') !== 'disabled') {
                // Flag the request. Guarded so it still works before the migration is run.
                try {
                    $db->prepare('UPDATE users SET reset_requested_at = NOW() WHERE id = ?')->execute([$user['id']]);
                } catch (PDOException $e) { /* column missing — audit entry below still records the request */ }
                audit_log($db, 'user_pwd_reset_req',
                    'Password-reset requested for: ' . $user['username'] . ' <' . ($user['email'] ?? '') . '>');
            }
            // Always show the same neutral result.
            $done = true;
        } catch (PDOException $e) {
            $error = 'Service temporarily unavailable. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password — Office Management System</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/sail-logo.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{ --brand1:#ff6a2b; --brand2:#ff3d6e; --ink:#201a17; }
  *{box-sizing:border-box}
  body{
    margin:0; min-height:100vh;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    background:
      radial-gradient(900px 500px at 15% 20%, #e9e3dd, transparent),
      radial-gradient(900px 500px at 85% 80%, #efe9e3, transparent),
      #f1ece7;
    display:flex; align-items:center; justify-content:center; padding:24px;
  }
  .shell{
    width:100%; max-width:1180px;
    display:grid; grid-template-columns:1fr 1fr;
    border-radius:28px; overflow:hidden;
    box-shadow:0 30px 70px rgba(40,28,20,.25);
    min-height:600px; background:#fff;
  }
  .hero{
    position:relative; color:#f6f1ec;
    background:
      radial-gradient(600px 420px at 55% 30%, rgba(255,106,43,.10), transparent),
      linear-gradient(160deg,#2f2621 0%, #241d19 60%, #1c1613 100%);
    padding:44px 48px; display:flex; flex-direction:column;
  }
  .hero .tagline{font-size:.92rem; color:#c9bdb3; max-width:420px; line-height:1.5}
  .hero h1{ margin:auto 0 0; font-weight:800; letter-spacing:-.02em; font-size:clamp(2.4rem,4vw,3.6rem); line-height:1; color:#fff; }
  .hero .ring{ position:absolute; top:34%; left:50%; width:340px; height:340px; transform:translate(-50%,-50%); border:1px solid rgba(255,255,255,.08); border-radius:50%; }
  .hero .foot{ display:flex; justify-content:space-between; align-items:center; color:#8c7f74; font-size:.8rem; margin-top:28px; }
  .panel{ padding:44px 56px; display:flex; flex-direction:column; }
  .panel .top{ display:flex; justify-content:space-between; align-items:center; }
  .logo{ display:flex; align-items:center; gap:12px; font-weight:800; font-size:1.25rem; color:var(--ink); }
  .logo img{ height:42px; width:auto; display:block; }
  .panel a.link{ color:var(--brand1); text-decoration:none; font-weight:600; }
  .panel a.link:hover{ text-decoration:underline; }
  .wrap{ margin:auto 0; width:100%; max-width:430px; align-self:center; }
  .wrap h2{ font-weight:800; font-size:2.3rem; letter-spacing:-.02em; color:var(--ink); margin-bottom:8px; }
  .wrap .sub{ color:#8a7d72; margin-bottom:24px; }
  .fld{ position:relative; margin-bottom:18px; }
  .fld input{ width:100%; border:1px solid #e3dcd4; border-radius:999px; padding:16px 22px; font-size:1rem; outline:none; transition:border-color .15s, box-shadow .15s; }
  .fld input:focus{ border-color:var(--brand1); box-shadow:0 0 0 4px rgba(255,106,43,.12); }
  .btn-main{ width:100%; border:0; border-radius:999px; color:#fff; font-weight:700; font-size:1.05rem; padding:16px; cursor:pointer;
    background:linear-gradient(90deg,var(--brand1),var(--brand2)); box-shadow:0 14px 30px rgba(255,61,110,.30);
    display:flex; align-items:center; justify-content:center; gap:10px; transition:transform .08s, box-shadow .15s; }
  .btn-main:hover{ box-shadow:0 18px 36px rgba(255,61,110,.38); }
  .btn-main:active{ transform:translateY(1px); }
  .back{ text-align:center; margin-top:20px; font-size:.95rem; }
  .alert{ border-radius:14px; }
  @media (max-width:860px){
    .shell{ grid-template-columns:1fr; min-height:0; }
    .hero{ min-height:200px; padding:32px; } .hero .ring{ display:none; }
    .panel{ padding:32px 26px; } .wrap h2{ font-size:2rem; }
  }
</style>
</head>
<body>
<div class="shell">
  <section class="hero">
    <p class="tagline">Office Management System — attendance, employees, payroll &amp; society, all in one place.</p>
    <div class="ring"></div>
    <h1>Reset your<br>password</h1>
    <div class="foot">
      <span><i class="bi bi-shield-lock me-1"></i> Secure access</span>
      <span>&copy; <?= date('Y') ?> Office MS</span>
    </div>
  </section>

  <section class="panel">
    <div class="top">
      <div class="logo"><img src="<?= BASE_URL ?>/assets/img/sail-logo.png" alt="SAIL"> <span>Office MS</span></div>
      <a class="link" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
    </div>

    <div class="wrap">
      <?php if ($done): ?>
        <h2>Request sent</h2>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= h($NEUTRAL) ?></div>
        <a class="btn-main" href="login.php" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Sign In</a>
      <?php else: ?>
        <h2>Forgot password?</h2>
        <p class="sub">Enter your email or username. Your administrator will set a new password for you.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
        <form method="post" novalidate>
          <div class="fld">
            <input type="text" name="username" placeholder="Email or Username"
                   value="<?= h($_POST['username'] ?? '') ?>" autofocus required>
          </div>
          <button class="btn-main" type="submit"><i class="bi bi-send"></i> Send reset request</button>
        </form>
        <div class="back"><a class="link" href="login.php">&larr; Back to Sign In</a></div>
      <?php endif; ?>
    </div>

    <div class="back" style="color:#a99d91;font-size:.82rem;margin-top:auto;">&copy; <?= date('Y') ?> Office Management System</div>
  </section>
</div>
</body>
</html>
