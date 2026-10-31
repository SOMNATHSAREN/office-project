<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Office Management System') ?></title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/sail-logo.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f5f6fa; }
  .sidebar { min-height: 100vh; background: #1a2340; }
  .sidebar .nav-link { color: #adb5bd; border-radius: 6px; }
  .sidebar .nav-link:hover, .sidebar .nav-link.active { background: #2d3f6e; color: #fff; }
  .sidebar .nav-section { color: #6c757d; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; padding: .5rem 1rem; margin-top: .5rem; }
  .topbar { background: #fff; border-bottom: 1px solid #e9ecef; }
  .card { border: none; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
</style>
</head>
<body>
<div class="d-flex">
<!-- Sidebar -->
<div class="sidebar d-flex flex-column p-3" style="width:230px;min-width:230px;">
  <a href="<?= BASE_URL ?>/index.php" class="text-white text-decoration-none mb-3 d-flex align-items-center gap-2">
    <span class="bg-white rounded-2 d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px;flex:0 0 auto;">
      <img src="<?= BASE_URL ?>/assets/img/sail-logo.png" alt="SAIL" style="height:30px;width:auto;">
    </span>
    <span class="fw-bold">SAIL&nbsp;·&nbsp;Office MS</span>
  </a>
  <hr class="border-secondary">
  <nav class="nav flex-column gap-1 mb-2">
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/dashboard/') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/dashboard/index.php">
      <i class="bi bi-speedometer2 me-2"></i>Dashboard
    </a>
  </nav>
  <?php if (can_do('emp_view')): ?>
  <div class="nav-section">Personal Info</div>
  <nav class="nav flex-column gap-1 mb-2">
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/employees/index') !== false || strpos($_SERVER['PHP_SELF'],'/employees/add') !== false || strpos($_SERVER['PHP_SELF'],'/employees/edit') !== false || strpos($_SERVER['PHP_SELF'],'/employees/increments') !== false || strpos($_SERVER['PHP_SELF'],'/employees/report') !== false || strpos($_SERVER['PHP_SELF'],'/employees/profile') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/employees/index.php">
      <i class="bi bi-people me-2"></i>Employees
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/employees/quarter_charges') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/employees/quarter_charges.php">
      <i class="bi bi-house-gear me-2"></i>Quarter Charges
    </a>
  </nav>
  <?php endif; ?>
  <?php if (can_do('att_view')): ?>
  <div class="nav-section">Attendance</div>
  <nav class="nav flex-column gap-1 mb-2">
    <?php if (can_do('att_write')): ?>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/upload') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/upload.php">
      <i class="bi bi-upload me-2"></i>Upload CSV
    </a>
    <?php endif; ?>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/index') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/index.php">
      <i class="bi bi-calendar3 me-2"></i>View / Edit
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/ledger') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/ledger.php">
      <i class="bi bi-journal-text me-2"></i>Leave Ledger
    </a>
    <?php if (can_do('att_write')): ?>
    <a class="nav-link px-3 py-2 <?= ($_SERVER['PHP_SELF'] === BASE_URL.'/modules/attendance/supplementary.php' || basename($_SERVER['PHP_SELF'])==='supplementary.php') ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/supplementary.php">
      <i class="bi bi-patch-plus me-2"></i>Supplementary
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/supplementary_detail') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/supplementary_detail.php">
      <i class="bi bi-card-list me-2"></i>Supp. Details
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/supplementary_rectify') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/supplementary_rectify.php">
      <i class="bi bi-pencil-square me-2"></i>Rectification
    </a>
    <?php endif; ?>
    <?php if (can_do('att_lock')): ?>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/attendance/year_lock') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/attendance/year_lock.php">
      <i class="bi bi-shield-lock me-2"></i>Year Locks
    </a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php if (can_do('pay_view')): ?>
  <div class="nav-section">Payments</div>
  <nav class="nav flex-column gap-1 mb-2">
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/payments/') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/payments/index.php">
      <i class="bi bi-cash-stack me-2"></i>Payments &amp; Advances
    </a>
  </nav>
  <?php endif; ?>
  <?php if (can_do('ccs_view')): ?>
  <div class="nav-section">Credit Society</div>
  <nav class="nav flex-column gap-1 mb-2">
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/society/members') !== false || strpos($_SERVER['PHP_SELF'],'/society/member_') !== false || strpos($_SERVER['PHP_SELF'],'/society/passbook') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/society/members.php">
      <i class="bi bi-people me-2"></i>Members &amp; Shares
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/society/deposit') !== false || strpos($_SERVER['PHP_SELF'],'/society/schemes') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/society/deposits.php">
      <i class="bi bi-piggy-bank me-2"></i>RD / Deposits
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/society/loan') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/society/loans.php">
      <i class="bi bi-cash-coin me-2"></i>Loans
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/society/recovery') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/society/recovery.php">
      <i class="bi bi-arrow-down-circle me-2"></i>Monthly Recovery
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/society/report') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/society/reports.php">
      <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
    </a>
  </nav>
  <?php endif; ?>
  <?php if (can_do('admin_panel') || can_do('user_mgmt')): ?>
  <div class="nav-section">Admin</div>
  <nav class="nav flex-column gap-1 mb-2">
    <?php if (can_do('admin_panel')): ?>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/admin/backup') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/admin/backup.php">
      <i class="bi bi-database-add me-2"></i>Backup
    </a>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/admin/audit_log') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/admin/audit_log.php">
      <i class="bi bi-shield-check me-2"></i>Audit Log
    </a>
    <?php endif; ?>
    <?php if (can_do('user_mgmt')): ?>
    <a class="nav-link px-3 py-2 <?= (strpos($_SERVER['PHP_SELF'],'/admin/users') !== false) ? 'active':'' ?>"
       href="<?= BASE_URL ?>/modules/admin/users.php">
      <i class="bi bi-people-fill me-2"></i>Users
    </a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <!-- Role badge -->
  <div class="px-3 mb-2">
    <?php
      $rb = ['admin'=>['danger','Admin'],'manager'=>['primary','Manager'],'hr'=>['success','HR']];
      [$rc,$rl] = $rb[$_SESSION['role'] ?? 'hr'] ?? ['secondary','Unknown'];
    ?>
    <span class="badge bg-<?= $rc ?> w-100 py-1" style="font-size:.72rem;"><i class="bi bi-shield-fill me-1"></i><?= $rl ?> Access</span>
  </div>
  <div class="mt-auto">
    <hr class="border-secondary">
    <a class="nav-link px-3 py-2 text-danger" href="<?= BASE_URL ?>/auth/logout.php">
      <i class="bi bi-box-arrow-left me-2"></i>Logout
    </a>
  </div>
</div>
<!-- Main -->
<div class="flex-grow-1 d-flex flex-column" style="min-width:0;">
  <div class="topbar px-4 py-2 d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($page_title ?? '') ?></h6>
    <div class="dropdown">
      <a href="#" class="text-decoration-none text-muted small d-flex align-items-center gap-1 dropdown-toggle"
         id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-person-circle fs-5"></i>
        <span class="fw-semibold"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenu">
        <li class="dropdown-header">
          <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
          <?php $rbm=['admin'=>'Admin','manager'=>'Manager','hr'=>'HR']; ?>
          <span class="badge bg-secondary ms-1"><?= $rbm[$_SESSION['role'] ?? ''] ?? 'User' ?></span>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <a class="dropdown-item" href="<?= BASE_URL ?>/modules/account/change_password.php">
            <i class="bi bi-key me-2"></i>Change Password
          </a>
        </li>
        <li>
          <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i>Logout
          </a>
        </li>
      </ul>
    </div>
  </div>
  <div class="p-4 flex-grow-1">
