<?php
define('BASE_URL', rtrim(str_replace('/auth','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
session_start();

$error = ''; $success = '';
$first = $middle = $last = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $pass2  = $_POST['password2'] ?? '';

    if ($first === '' || $last === '')             $error = 'First and last name are required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (strlen($pass) < 6)                      $error = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2)                        $error = 'Passwords do not match.';

    if (!$error) {
        try {
            $db = get_db();
            $dup = $db->prepare('SELECT COUNT(*) FROM users WHERE email=?');
            $dup->execute([$email]);
            if ((int)$dup->fetchColumn() > 0) {
                $error = 'An account with this email already exists.';
            } else {
                $fullname = full_name($first, $middle, $last);
                $username = unique_username($db, $fullname);
                $db->prepare("INSERT INTO users (username, first_name, middle_name, last_name, email, password, role, permissions, status)
                              VALUES (?,?,?,?,?,?,?,?, 'pending')")
                   ->execute([$username, $first, $middle ?: null, $last, $email, password_hash($pass, PASSWORD_DEFAULT), 'hr', '']);
                audit_log($db, 'user_register', 'Self-registration: ' . $fullname . ' <' . $email . '> — pending approval');
                $success = 'Registration submitted for ' . $fullname . '. Your account is awaiting admin approval. You can log in with your email once approved.';
            }
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(),'Duplicate') ? 'This email is already registered.' : 'Database error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign Up — Office Management System</title>
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
    min-height:660px; background:#fff;
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
  .hero .ring::after{ content:""; position:absolute; inset:46px; border-radius:50%; border:1px solid rgba(255,255,255,.06); }
  .hero .foot{ display:flex; justify-content:space-between; align-items:center; color:#8c7f74; font-size:.8rem; margin-top:28px; }
  .panel{ padding:40px 56px; display:flex; flex-direction:column; }
  .panel .top{ display:flex; justify-content:space-between; align-items:center; }
  .logo{ display:flex; align-items:center; gap:12px; font-weight:800; font-size:1.25rem; color:var(--ink); }
  .logo img{ height:42px; width:auto; display:block; }
  .panel a.link{ color:var(--brand1); text-decoration:none; font-weight:600; }
  .panel a.link:hover{ text-decoration:underline; }
  .wrap{ margin:auto 0; width:100%; max-width:460px; align-self:center; }
  .wrap h2{ font-weight:800; font-size:2.4rem; letter-spacing:-.02em; color:var(--ink); margin-bottom:6px; }
  .wrap .sub{ color:#8a7d72; margin-bottom:22px; }
  .lbl{ font-size:.82rem; font-weight:600; color:#6c6058; margin:0 0 6px 14px; }
  .fld{ position:relative; margin-bottom:16px; }
  .fld input{ width:100%; border:1px solid #e3dcd4; border-radius:999px; padding:14px 20px; font-size:1rem; outline:none; transition:border-color .15s, box-shadow .15s; }
  .fld input:focus{ border-color:var(--brand1); box-shadow:0 0 0 4px rgba(255,106,43,.12); }
  .fld .toggle{ position:absolute; right:16px; top:50%; transform:translateY(-50%); border:0; background:none; color:#9a8e82; cursor:pointer; font-size:1.05rem; padding:4px; }
  .hint{ font-size:.8rem; color:#a99d91; margin:-8px 4px 14px 14px; }
  .btn-main{ width:100%; border:0; border-radius:999px; color:#fff; font-weight:700; font-size:1.05rem; padding:15px; cursor:pointer;
    background:linear-gradient(90deg,var(--brand1),var(--brand2)); box-shadow:0 14px 30px rgba(255,61,110,.30);
    display:flex; align-items:center; justify-content:center; gap:10px; transition:transform .08s, box-shadow .15s; }
  .btn-main:hover{ box-shadow:0 18px 36px rgba(255,61,110,.38); }
  .btn-main:active{ transform:translateY(1px); }
  .back{ text-align:center; margin-top:18px; font-size:.95rem; color:#8a7d72; }
  .alert{ border-radius:14px; }
  @media (max-width:860px){
    .shell{ grid-template-columns:1fr; min-height:0; }
    .hero{ min-height:200px; padding:32px; } .hero .ring{ display:none; }
    .panel{ padding:30px 24px; } .wrap h2{ font-size:2rem; }
  }
</style>
</head>
<body>
<div class="shell">
  <section class="hero">
    <p class="tagline">Office Management System — attendance, employees, payroll &amp; society, all in one place.</p>
    <div class="ring"></div>
    <h1>Join the<br>workspace</h1>
    <div class="foot">
      <span><i class="bi bi-shield-check me-1"></i> Admin-approved access</span>
      <span>&copy; <?= date('Y') ?> Office MS</span>
    </div>
  </section>

  <section class="panel">
    <div class="top">
      <div class="logo"><img src="<?= BASE_URL ?>/assets/img/sail-logo.png" alt="SAIL"> <span>Office MS</span></div>
      <a class="link" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
    </div>

    <div class="wrap">
      <?php if ($success): ?>
        <h2>Registration sent</h2>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?></div>
        <a class="btn-main" href="login.php" style="text-decoration:none;"><i class="bi bi-box-arrow-in-right"></i> Go to Sign In</a>
      <?php else: ?>
        <h2>Sign Up</h2>
        <p class="sub">Create your account — an administrator will approve and grant your access.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" novalidate>
          <div class="row g-2">
            <div class="col-4">
              <div class="lbl">First name *</div>
              <div class="fld"><input type="text" name="first_name" placeholder="First" value="<?= htmlspecialchars($first) ?>" required autofocus></div>
            </div>
            <div class="col-4">
              <div class="lbl">Middle</div>
              <div class="fld"><input type="text" name="middle_name" placeholder="Middle" value="<?= htmlspecialchars($middle) ?>"></div>
            </div>
            <div class="col-4">
              <div class="lbl">Last name *</div>
              <div class="fld"><input type="text" name="last_name" placeholder="Last" value="<?= htmlspecialchars($last) ?>" required></div>
            </div>
          </div>
          <div class="fld"><input type="email" name="email" placeholder="Email address" value="<?= htmlspecialchars($email) ?>" required></div>
          <div class="hint">Your username will be your full name. You'll sign in with this email.</div>
          <div class="fld">
            <input type="password" name="password" id="pw" placeholder="Password (min 6 characters)" required>
            <button type="button" class="toggle" data-for="pw" aria-label="Show password"><i class="bi bi-eye-slash"></i></button>
          </div>
          <div class="fld">
            <input type="password" name="password2" id="pw2" placeholder="Confirm password" required>
            <button type="button" class="toggle" data-for="pw2" aria-label="Show password"><i class="bi bi-eye-slash"></i></button>
          </div>
          <button class="btn-main" type="submit"><i class="bi bi-person-plus"></i> Create Account</button>
        </form>
        <div class="back">Already have an account? <a class="link" href="login.php">Sign in</a></div>
      <?php endif; ?>
    </div>

    <div class="back" style="color:#a99d91;font-size:.82rem;margin-top:auto;">&copy; <?= date('Y') ?> Office Management System</div>
  </section>
</div>
<script>
  document.querySelectorAll('.toggle').forEach(function(tg){
    tg.addEventListener('click', function(){
      var inp = document.getElementById(tg.getAttribute('data-for')),
          show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      tg.innerHTML = '<i class="bi bi-eye' + (show ? '' : '-slash') + '"></i>';
      tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });
</script>
</body>
</html>
