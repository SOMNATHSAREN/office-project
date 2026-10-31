<?php
define('BASE_URL', rtrim(str_replace('/modules/attendance','',dirname($_SERVER['SCRIPT_NAME'])), '/'));
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

require_perm('att_write');
$page_title = 'Upload Attendance CSV';
$errors  = [];
$success = '';

// Column map: CSV header => DB column
const COL_MAP = [
    'UNIT_CD'          => 'unit_cd',
    'SAIL_PERNO'       => 'sail_perno',
    'UNIT_PERNO'       => 'unit_perno',
    'YYYYMM'           => 'yyyymm',
    'DUTY'             => 'duty',
    'W_OFF'            => 'w_off',
    'C_NHDAY'          => 'c_nhday',
    'C_OFF'            => 'c_off',
    'CL'               => 'cl',
    'FL_COFF'          => 'fl_coff',
    'EL'               => 'el',
    'HPL'              => 'hpl',
    'COM'              => 'com',
    'EOL'              => 'eol',
    'ABS'              => 'abs',
    'OTHERS'           => 'others',
    'NIGHT_SHIFT'      => 'night_shift',
    'INJURY_LV'        => 'injury_lv',
    'EX_GRAT'          => 'ex_grat',
    'UNACCOUNTED_DAYS' => 'unaccounted',
    'EXWAGE'           => 'exwage',
    'LOCAL_TRG'        => 'local_trg',
    'SCL_BD'           => 'scl_bd',
    'SCL'              => 'scl',
    'CCL'              => 'ccl',
    'SHORT_HRS'        => 'short_hrs',
    // EL_BLNS/HPL_BLNS/CL_BLNS/FL_BLNS are handled positionally below
    // because the file has a blank header column immediately before EL_BLNS,
    // shifting the actual balance values one column to the left of their labels.
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed.';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        // Detect delimiter (tab or comma)
        $firstLine = fgets($handle);
        rewind($handle);
        $delim = (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) ? "\t" : ',';

        $headers = fgetcsv($handle, 0, $delim);
        if (!$headers) { $errors[] = 'Could not read file headers.'; }

        if (!$errors) {
            // Normalise headers (trim, uppercase)
            $headers = array_map(fn($h) => strtoupper(trim($h)), $headers);

            // ── Pre-flight: scan every row for fractional COM values ──────────
            // Read all rows into memory first so we can reject before any DB write.
            $com_col = array_search('COM', $headers);
            $com_violations = [];
            $all_rows = [];
            $line = 2; // line 1 = header
            while (($row = fgetcsv($handle, 0, $delim)) !== false) {
                if (count(array_filter($row)) === 0) { $line++; continue; }
                $all_rows[] = ['data' => $row, 'line' => $line];
                if ($com_col !== false) {
                    $com_raw = trim($row[$com_col] ?? '');
                    if ($com_raw !== '' && $com_raw !== '0' && is_numeric($com_raw)) {
                        $com_num = (float)$com_raw;
                        if ($com_num != (int)$com_num) {
                            $perno = trim($row[array_search('SAIL_PERNO', $headers)] ?? "row $line");
                            $com_violations[] = "Line $line (SAIL_PERNO: $perno) — COM = $com_raw (fractional value not allowed)";
                        }
                    }
                }
                $line++;
            }
            fclose($handle);

            if ($com_violations) {
                $errors[] = 'Upload rejected — COM (Commuted Leave) must be a whole number. Fix the following rows and re-upload:';
                foreach ($com_violations as $v) $errors[] = $v;
                $all_rows = []; // discard rows, nothing will be written
            }

            if (!$errors) {
            // (all rows passed validation — proceed with DB inserts)

            $db = get_db();
            $inserted = $updated = $skipped = 0;

            $sql = 'INSERT INTO attendance (sail_perno,yyyymm,unit_cd,unit_perno,duty,w_off,c_nhday,c_off,cl,fl_coff,el,hpl,com,eol,abs,others,night_shift,injury_lv,ex_grat,unaccounted,exwage,local_trg,scl_bd,scl,ccl,short_hrs,el_blns,hpl_blns,cl_blns,fl_blns)
                    VALUES (:sail_perno,:yyyymm,:unit_cd,:unit_perno,:duty,:w_off,:c_nhday,:c_off,:cl,:fl_coff,:el,:hpl,:com,:eol,:abs,:others,:night_shift,:injury_lv,:ex_grat,:unaccounted,:exwage,:local_trg,:scl_bd,:scl,:ccl,:short_hrs,:el_blns,:hpl_blns,:cl_blns,:fl_blns)
                    ON DUPLICATE KEY UPDATE
                      unit_cd=VALUES(unit_cd),unit_perno=VALUES(unit_perno),duty=VALUES(duty),w_off=VALUES(w_off),
                      c_nhday=VALUES(c_nhday),c_off=VALUES(c_off),cl=VALUES(cl),fl_coff=VALUES(fl_coff),
                      el=VALUES(el),hpl=VALUES(hpl),com=VALUES(com),eol=VALUES(eol),abs=VALUES(abs),
                      others=VALUES(others),night_shift=VALUES(night_shift),injury_lv=VALUES(injury_lv),
                      ex_grat=VALUES(ex_grat),unaccounted=VALUES(unaccounted),exwage=VALUES(exwage),
                      local_trg=VALUES(local_trg),scl_bd=VALUES(scl_bd),scl=VALUES(scl),ccl=VALUES(ccl),
                      short_hrs=VALUES(short_hrs),el_blns=VALUES(el_blns),hpl_blns=VALUES(hpl_blns),
                      cl_blns=VALUES(cl_blns),fl_blns=VALUES(fl_blns),uploaded_at=NOW()';
            $stmt = $db->prepare($sql);

            foreach ($all_rows as ['data' => $row]) {
                $rec = [];
                foreach ($headers as $idx => $hdr) {
                    $col = COL_MAP[$hdr] ?? null;
                    if ($col) $rec[$col] = trim($row[$idx] ?? '');
                }
                if (empty($rec['sail_perno']) || empty($rec['yyyymm'])) { $skipped++; continue; }

                // Skip inactive employees — do not upload their data
                if (!is_employee_active($db, $rec['sail_perno'])) { $skipped++; continue; }

                // ── Balance columns: positional extraction ────────────────────
                // The file has a blank header immediately before EL_BLNS.
                // The actual EL balance sits in that blank column (one position
                // to the LEFT of the EL_BLNS label). Extract all four balances
                // relative to where EL_BLNS header is found.
                $el_hdr_pos = array_search('EL_BLNS', $headers);
                if ($el_hdr_pos !== false) {
                    $rec['el_blns']  = trim($row[$el_hdr_pos - 1] ?? '') ?: 0; // blank col = real EL
                    $rec['hpl_blns'] = trim($row[$el_hdr_pos]     ?? '') ?: 0; // EL_BLNS label  = real HPL
                    $rec['cl_blns']  = trim($row[$el_hdr_pos + 1] ?? '') ?: 0; // HPL_BLNS label = real CL
                    $rec['fl_blns']  = trim($row[$el_hdr_pos + 2] ?? '') ?: 0; // CL_BLNS label  = real FL
                } else {
                    $rec['el_blns'] = $rec['hpl_blns'] = $rec['cl_blns'] = $rec['fl_blns'] = 0;
                }

                // Replace remaining empty strings with 0 for numeric columns
                foreach ($rec as $k => $v) {
                    if (!in_array($k, ['sail_perno','yyyymm','unit_cd','unit_perno']) && $v === '') $rec[$k] = 0;
                }
                // Tag who uploaded
                $rec_with_user = $rec;
                // uploaded_by tracked via the ON DUPLICATE KEY path below — handled after insert
                $stmt->execute($rec);
                // Set uploaded_by on INSERT / UPDATE
                $db->prepare('UPDATE attendance SET uploaded_by=? WHERE sail_perno=? AND yyyymm=?')
                   ->execute([$_SESSION['username'] ?? 'admin', $rec['sail_perno'], $rec['yyyymm']]);

                // ── Leave ledger: determine opening balances ──────────────────
                $perno  = $rec['sail_perno'];
                $yyyymm = $rec['yyyymm'];

                // Are balance columns present in this CSV row?
                $has_balances = ($rec['cl_blns'] + $rec['el_blns'] + $rec['hpl_blns'] + $rec['fl_blns']) > 0
                             || $rec['cl_blns'] > 0 || $rec['el_blns'] > 0
                             || $rec['hpl_blns'] > 0 || $rec['fl_blns'] > 0;

                if ($has_balances) {
                    // CSV has balances: opening = closing_balance + availed
                    $cl_open  = $rec['cl_blns']  + $rec['cl'];
                    $el_open  = $rec['el_blns']  + $rec['el'];
                    $hpl_open = $rec['hpl_blns'] + $rec['hpl'];
                    $fl_open  = $rec['fl_blns']  + $rec['fl_coff'];
                } else {
                    // CSV has no balances: carry forward from previous month's closing balance
                    $prev = prev_month($yyyymm);
                    $ps   = $db->prepare('SELECT cl_close, el_close, hpl_close, fl_close FROM leave_ledger WHERE sail_perno=? AND yyyymm=?');
                    $ps->execute([$perno, $prev]);
                    $prevRow = $ps->fetch();
                    if ($prevRow) {
                        $cl_open  = $prevRow['cl_close'];
                        $el_open  = $prevRow['el_close'];
                        $hpl_open = $prevRow['hpl_close'];
                        $fl_open  = $prevRow['fl_close'];
                    } else {
                        // No previous month at all — opening = 0
                        $cl_open = $el_open = $hpl_open = $fl_open = 0;
                    }
                }

                // COM must be a whole number — round any fractional value from CSV
                $com_availed = (int)round((float)($rec['com'] ?? 0));

                $db->prepare('INSERT INTO leave_ledger
                    (sail_perno, yyyymm, cl_open, el_open, hpl_open, fl_open,
                     cl_availed, el_availed, hpl_availed, fl_availed, com_availed)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      cl_open=VALUES(cl_open), el_open=VALUES(el_open),
                      hpl_open=VALUES(hpl_open), fl_open=VALUES(fl_open),
                      cl_availed=VALUES(cl_availed), el_availed=VALUES(el_availed),
                      hpl_availed=VALUES(hpl_availed), fl_availed=VALUES(fl_availed),
                      com_availed=VALUES(com_availed)')
                  ->execute([$perno,$yyyymm,$cl_open,$el_open,$hpl_open,$fl_open,
                             $rec['cl'],$rec['el'],$rec['hpl'],$rec['fl_coff'],$com_availed]);

                $inserted++;
            }

            audit_log($db, 'upload', "CSV uploaded: $inserted rows processed, $skipped skipped.", '', '');
            flash('success', "Upload complete. $inserted row(s) processed" . ($skipped ? ", $skipped skipped (inactive/blank)." : '.'));
            redirect(BASE_URL . '/modules/attendance/index.php');

            } // end if (!$errors) — validation passed
        }
    }
}

// ── Template download ─────────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_template.csv"');
    $out = fopen('php://output', 'w');
    // Header row — exact format matching the system's expected input
    fputcsv($out, [
        'UNIT_CD','SAIL_PERNO','UNIT_PERNO','YYYYMM',
        'DUTY','W_OFF','C_NHDAY','C_OFF',
        'CL','FL_COFF','EL','HPL','COM','EOL','ABS','OTHERS',
        'NIGHT_SHIFT','INJURY_LV','EX_GRAT','UNACCOUNTED_DAYS',
        'EXWAGE','LOCAL_TRG','SCL_BD','SCL','CCL','SHORT_HRS',
        '',          // blank column — EL balance goes here
        'EL_BLNS',   // HPL balance goes here
        'HPL_BLNS',  // CL balance goes here
        'CL_BLNS',   // FL balance goes here
        'FL_BLNS',
    ]);
    // Two sample rows
    fputcsv($out, ['TE','T001001','10001',date('Ym'), 26,4,'','','','','','','','','','','','','','','','','','','','', 8,15,20,3,'']);
    fputcsv($out, ['TE','T001002','10002',date('Ym'), 22,4,'',1,2,'','','','','','','','','','','','','','','','','', 6,13,18,3,'']);
    fclose($out);
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>
<?= flash_html('error') ?>
<?php if ($errors): ?>
  <div class="alert alert-danger" style="max-width:700px;">
    <div class="d-flex align-items-center gap-2 mb-2">
      <i class="bi bi-exclamation-triangle-fill fs-5"></i>
      <strong>Upload Rejected</strong>
    </div>
    <?php
      // First error is the summary message; rest are per-row details
      echo '<p class="mb-2">' . h($errors[0]) . '</p>';
      if (count($errors) > 1):
    ?>
    <ul class="mb-0">
      <?php for ($i = 1; $i < count($errors); $i++): ?>
        <li><code><?= h($errors[$i]) ?></code></li>
      <?php endfor; ?>
    </ul>
    <hr class="my-2">
    <small class="text-muted">
      <i class="bi bi-info-circle me-1"></i>
      COM (Commuted Leave) must be a whole number (e.g. 1, 2, 3).
      Correct the highlighted rows in your file and upload again.
      No data was saved.
    </small>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-4" style="max-width:900px;">

  <!-- Upload card -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-upload me-2"></i>Upload Attendance File</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Upload the monthly attendance CSV exported from your system.
          Both <strong>comma-separated</strong> and <strong>tab-separated</strong> formats are accepted.
          Existing records for the same employee + month will be overwritten.
        </p>
        <form method="post" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select file <span class="text-muted fw-normal">(.csv / .txt)</span></label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
          </div>
          <button class="btn btn-primary w-100"><i class="bi bi-cloud-upload me-1"></i>Upload &amp; Process</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Template + format guide -->
  <div class="col-md-7">
    <div class="card">
      <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark-spreadsheet me-2"></i>CSV Format &amp; Template</span>
        <a href="?template=1" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Download Template</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:.78rem;">
          <thead class="table-dark">
            <tr><th>Column</th><th>Header name</th><th>Description</th><th>Example</th></tr>
          </thead>
          <tbody>
            <?php
            $cols = [
              ['UNIT_CD',          'Unit Code',                    'TE'],
              ['SAIL_PERNO',       'SAIL Personnel No.',           'T002474'],
              ['UNIT_PERNO',       'Unit Personnel No.',           '24405'],
              ['YYYYMM',           'Year &amp; Month',             '202604'],
              ['DUTY',             'Duty days',                    '26'],
              ['W_OFF',            'Weekly offs',                  '4'],
              ['C_NHDAY',          'Comp./Nat. Holiday',           '2'],
              ['C_OFF',            'Compensatory off',             '1'],
              ['CL',               'Casual Leave availed',         '2'],
              ['FL_COFF',          'Festival / Comp off availed',  '1'],
              ['EL',               'Earned Leave availed',         '0'],
              ['HPL',              'Half Pay Leave availed',       '0'],
              ['COM',              'COM leave',                    '0'],
              ['EOL',              'Extra Ordinary Leave',         '0'],
              ['ABS',              'Absence',                      '0'],
              ['OTHERS',           'Other absences',               '0'],
              ['NIGHT_SHIFT',      'Night shift days',             '0'],
              ['INJURY_LV',        'Injury Leave',                 '0'],
              ['EX_GRAT',          'Ex-Gratia',                    '0'],
              ['UNACCOUNTED_DAYS', 'Unaccounted days',             '0'],
              ['EXWAGE',           'Ex-Wage',                      '0'],
              ['LOCAL_TRG',        'Local Training',               '0'],
              ['SCL_BD',           'SCL Before Delivery',          '0'],
              ['SCL',              'Special Casual Leave',         '0'],
              ['CCL',              'Child Care Leave',             '0'],
              ['SHORT_HRS',        'Short hours',                  '0'],
              ['(blank)',          'EL closing balance ★',         '92.5'],
              ['EL_BLNS',          'HPL closing balance ★',        '94'],
              ['HPL_BLNS',         'CL closing balance ★',         '7'],
              ['CL_BLNS',          'FL closing balance ★',         '0'],
              ['FL_BLNS',          '(trailing — ignored)',          ''],
            ];
            foreach ($cols as [$hdr, $desc, $eg]):
              $isBalance = str_contains($desc, '★');
            ?>
            <tr <?= $isBalance ? 'class="table-success"' : '' ?>>
              <td><code><?= $hdr ?></code></td>
              <td><?= $desc ?></td>
              <td class="text-muted"><?= $eg ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <div class="px-3 py-2 bg-success-subtle border-top">
          <small class="text-success"><i class="bi bi-info-circle me-1"></i>
            <strong>★ Balance columns</strong> — the blank column holds EL balance; the remaining label names are shifted by one position. Leave balance columns are optional: if absent, the system carries forward the previous month's closing balance automatically.
          </small>
        </div>
      </div>
    </div>
  </div>

</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
