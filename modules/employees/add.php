<?php
define('BASE_URL', rtrim(str_replace('/modules/employees','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

$page_title = 'Add Employee';
$errors = [];
$data = ['sail_perno'=>'','unit_perno'=>'','unit_cd'=>'','name'=>'','department'=>'','designation'=>'','date_of_joining'=>'','status'=>'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_map('trim', $_POST);
    if (!$data['sail_perno']) $errors[] = 'SAIL PERNO is required.';
    if (!$data['name'])       $errors[] = 'Name is required.';

    if (!$errors) {
        try {
            $db = get_db();
            $db->prepare('INSERT INTO employees (sail_perno,unit_perno,unit_cd,name,department,designation,date_of_joining,status)
                          VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$data['sail_perno'],$data['unit_perno'],$data['unit_cd'],$data['name'],
                          $data['department'],$data['designation'],$data['date_of_joining']?:null,$data['status']]);
            audit_log($db, 'emp_add',
                'Employee added: ' . $data['name'] . ' | Dept: ' . $data['department'] . ' | Status: ' . $data['status'],
                $data['sail_perno']);
            flash('success', 'Employee added successfully.');
            redirect(BASE_URL . '/modules/employees/index.php');
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(),'Duplicate') ? 'SAIL PERNO already exists.' : 'Database error.';
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="card" style="max-width:640px;">
  <div class="card-body">
    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger py-2"><?= h($err) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">SAIL PERNO <span class="text-danger">*</span></label>
          <input type="text" name="sail_perno" class="form-control" value="<?= h($data['sail_perno']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Unit PERNO</label>
          <input type="text" name="unit_perno" class="form-control" value="<?= h($data['unit_perno']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Unit Code</label>
          <input type="text" name="unit_cd" class="form-control" value="<?= h($data['unit_cd']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= h($data['name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Department</label>
          <input type="text" name="department" class="form-control" value="<?= h($data['department']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Designation</label>
          <input type="text" name="designation" class="form-control" value="<?= h($data['designation']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Date of Joining</label>
          <input type="date" name="date_of_joining" class="form-control" value="<?= h($data['date_of_joining']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active"   <?= $data['status']==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $data['status']==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
      </div>
      <hr>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">Save Employee</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
