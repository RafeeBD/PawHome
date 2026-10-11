<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/medical.php';
require_once __DIR__ . '/includes/slots.php';

$me = require_role('vet');
$uid = $me['user_id'];

function vet_appointment(int $id, int $vetId): ?array {
    return db_one('SELECT ap.*, p.name AS pet_name, p.breed, p.birth_date, p.photo_path, c.name AS category, u.name AS adopter_name
                   FROM appointments ap JOIN pets p ON p.pet_id = ap.pet_id JOIN categories c ON c.category_id = p.category_id
                   JOIN users u ON u.user_id = ap.adopter_id WHERE ap.appointment_id = ? AND ap.vet_id = ?', [$id, $vetId]);
}

$selectedId = (int) ($_GET['appt'] ?? 0);
$recordErrors = [];
$recordOld = [];
$openRecord = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('That file is too large — documents can be up to 5 MB.');
    redirect('vet.php' . ($selectedId ? '?appt=' . $selectedId : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $back = 'vet.php' . ($selectedId ? '?appt=' . $selectedId : '');

    if ($action === 'confirm_appointment' || $action === 'cancel_appointment') {
        $ap = vet_appointment((int) ($_POST['appointment_id'] ?? 0), $uid);
        $allowedFrom = $action === 'confirm_appointment' ? ['Pending'] : ['Pending', 'Confirmed'];
        if (!$ap || !in_array($ap['status'], $allowedFrom, true)) {
            flash('That appointment was already updated.');
            redirect($back);
        }
        if ($action === 'confirm_appointment' && strtotime($ap['scheduled_at']) <= time()) {
            flash('The time ' . fmt_datetime($ap['scheduled_at']) . ' has already passed, so this request cannot be confirmed. Cancel it so the adopter can rebook.');
            redirect($back);
        }
        db_exec('UPDATE appointments SET status = ? WHERE appointment_id = ?', [$action === 'confirm_appointment' ? 'Confirmed' : 'Cancelled', $ap['appointment_id']]);
        flash($action === 'confirm_appointment'
            ? 'Confirmed ' . $ap['pet_name'] . '\'s appointment on ' . fmt_datetime($ap['scheduled_at']) . '.'
            : 'Cancelled ' . $ap['pet_name'] . '\'s appointment.');
        redirect($action === 'confirm_appointment' ? 'vet.php?appt=' . $ap['appointment_id'] : 'vet.php');
    }

    if ($action === 'save_checkup') {
        $ap = vet_appointment((int) ($_POST['appointment_id'] ?? 0), $uid);
        if (!$ap || $ap['status'] !== 'Confirmed') {
            flash('Only confirmed appointments can be checked in.');
            redirect($back);
        }
        if (!checkin_open($ap['scheduled_at'])) {
            flash('Checkups can be recorded from the day of the appointment (' . fmt_date($ap['scheduled_at']) . ').');
            redirect($back);
        }
        $old = [
            'weight_kg' => trim((string) ($_POST['weight_kg'] ?? '')), 'temperature_c' => trim((string) ($_POST['temperature_c'] ?? '')),
            'symptoms' => clean('symptoms', 255), 'findings' => clean('findings', 2000), 'diagnosis' => clean('diagnosis', 255),
            'treatment' => clean('treatment', 255), 'follow_up_date' => trim((string) ($_POST['follow_up_date'] ?? '')), 'notes' => clean('notes', 255),
            'no_medication' => isset($_POST['no_medication']), 'vaccine_choice' => ($_POST['vaccine_choice'] ?? 'prescribe') === 'none' ? 'none' : 'prescribe',
            'meds' => [], 'vax' => [],
        ];
        $errors = [];

        $weight = null;
        if ($old['weight_kg'] !== '') {
            if (!is_numeric($old['weight_kg']) || $old['weight_kg'] <= 0 || $old['weight_kg'] > 200) $errors['checkup']['weight_kg'] = 'Weight must be between 0.01 and 200 kg.';
            else $weight = round((float) $old['weight_kg'], 2);
        }
        $temp = null;
        if ($old['temperature_c'] !== '') {
            if (!is_numeric($old['temperature_c']) || $old['temperature_c'] < 30 || $old['temperature_c'] > 45) $errors['checkup']['temperature_c'] = 'Temperature must be between 30 and 45 °C.';
            else $temp = round((float) $old['temperature_c'], 1);
        }
        if (mb_strlen($old['findings']) < 3) $errors['checkup']['findings'] = 'Record the clinical findings.';
        if (mb_strlen($old['diagnosis']) < 2) $errors['checkup']['diagnosis'] = 'Diagnosis is required.';
        $followUp = null;
        if ($old['follow_up_date'] !== '') {
            $followUp = parse_date($old['follow_up_date']);
            if (!$followUp || $followUp < date('Y-m-d')) $errors['checkup']['follow_up_date'] = 'Follow-up date must be today or later.';
        }

        
        $meds = [];
        foreach ((array) ($_POST['meds'] ?? []) as $i => $m) {
            $row = [];
            foreach (['name' => 100, 'dosage' => 50, 'frequency' => 50, 'duration' => 50, 'instructions' => 255] as $k => $max) {
                $row[$k] = mb_substr(trim((string) ($m[$k] ?? '')), 0, $max);
            }
            $old['meds'][] = $row;
            if (implode('', $row) === '') continue;
            foreach (['name' => 'medicine name', 'dosage' => 'dosage', 'frequency' => 'frequency', 'duration' => 'duration'] as $k => $label) {
                if ($row[$k] === '') $errors['prescription']['meds'][count($old['meds']) - 1][$k] = 'Enter the ' . $label . '.';
            }
            $meds[] = $row;
        }
        if (!$old['no_medication'] && !$meds) {
            $errors['prescription']['_general'] = 'Add at least one medicine, or tick "No medication prescribed".';
        }

        
        $vax = [];
        if ($old['vaccine_choice'] === 'prescribe') {
            foreach ((array) ($_POST['vax'] ?? []) as $v) {
                $row = ['name' => mb_substr(trim((string) ($v['name'] ?? '')), 0, 80), 'priority' => (string) ($v['priority'] ?? 'Routine'),
                    'date' => trim((string) ($v['date'] ?? '')), 'administered' => ($v['administered'] ?? '') === '1' ? '1' : '0',
                    'notes' => mb_substr(trim((string) ($v['notes'] ?? '')), 0, 255)];
                $old['vax'][] = $row;
                $idx = count($old['vax']) - 1;
                if ($row['name'] === '' && $row['date'] === '' && $row['notes'] === '') continue;
                if ($row['name'] === '') $errors['vaccination']['vax'][$idx]['name'] = 'Enter the vaccine name.';
                if (!in_array($row['priority'], ['Routine', 'Priority', 'Urgent'], true)) $row['priority'] = 'Routine';
                $d = $row['date'] === '' ? null : parse_date($row['date']);
                if ($row['administered'] === '1') {
                    if ($row['date'] !== '' && (!$d || $d <= date('Y-m-d'))) $errors['vaccination']['vax'][$idx]['date'] = 'The next booster date must be after today.';
                } elseif (!$d) {
                    $errors['vaccination']['vax'][$idx]['date'] = 'Choose when this vaccine should be given.';
                } elseif ($d < date('Y-m-d')) {
                    $errors['vaccination']['vax'][$idx]['date'] = 'The due date can\'t be in the past.';
                }
                $row['date'] = $d;
                $vax[] = $row;
            }
            if (!$vax) $errors['vaccination']['_general'] = 'Add a vaccine, or choose "Not Needed".';
        }

        if ($errors) {
            
            $_SESSION['checkup_form'] = ['appt' => $ap['appointment_id'], 'old' => $old, 'errors' => $errors];
            $tab = isset($errors['checkup']) ? 'checkupPanel' : (isset($errors['prescription']) ? 'prescriptionPanel' : 'vaccinationPanel');
            redirect('vet.php?appt=' . $ap['appointment_id'] . '#' . $tab);
        }

        $db = get_db();
        mysqli_begin_transaction($db);
        try {
            $checkupId = db_exec('INSERT INTO checkups (appointment_id, pet_id, vet_id, weight_kg, temperature_c, symptoms, findings, diagnosis, treatment, follow_up_date, notes, no_medication)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $ap['appointment_id'], $ap['pet_id'], $uid, $weight, $temp, $old['symptoms'] ?: 'None reported', $old['findings'],
                $old['diagnosis'], $old['treatment'], $followUp, $old['notes'], $meds ? 0 : 1,
            ]);
            foreach ($meds as $m) {
                db_exec('INSERT INTO prescriptions (checkup_id, medicine_name, dosage, frequency, duration, instructions) VALUES (?, ?, ?, ?, ?, ?)',
                    [$checkupId, $m['name'], $m['dosage'], $m['frequency'], $m['duration'], $m['instructions']]);
            }
            foreach ($vax as $v) {
                
                $due = db_one('SELECT vaccination_id FROM vaccinations WHERE pet_id = ? AND status = "Due" AND vaccine_name = ? ORDER BY due_date LIMIT 1',
                    [$ap['pet_id'], $v['name']]);
                if ($v['administered'] === '1') {
                    $vals = ['Given', $v['priority'], date('Y-m-d'), $uid, $v['date'], $v['notes'], $checkupId];
                    if ($due) {
                        db_exec('UPDATE vaccinations SET status = ?, priority = ?, given_date = ?, vet_id = ?, next_due_date = ?, notes = ?, checkup_id = ? WHERE vaccination_id = ?', array_merge($vals, [$due['vaccination_id']]));
                    } else {
                        db_exec('INSERT INTO vaccinations (status, priority, given_date, vet_id, next_due_date, notes, checkup_id, pet_id, vaccine_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$ap['pet_id'], $v['name']]));
                    }
                } else {
                    $vals = ['Due', $v['priority'], $v['date'], $uid, $v['notes'], $checkupId];
                    if ($due) {
                        db_exec('UPDATE vaccinations SET status = ?, priority = ?, due_date = ?, vet_id = ?, notes = ?, checkup_id = ? WHERE vaccination_id = ?', array_merge($vals, [$due['vaccination_id']]));
                    } else {
                        db_exec('INSERT INTO vaccinations (status, priority, due_date, vet_id, notes, checkup_id, pet_id, vaccine_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$ap['pet_id'], $v['name']]));
                    }
                }
            }
            db_exec('UPDATE appointments SET status = "Completed" WHERE appointment_id = ?', [$ap['appointment_id']]);
            mysqli_commit($db);
        } catch (Throwable $ex) {
            mysqli_rollback($db);
            throw $ex;
        }
        flash('Checkup record saved for ' . $ap['pet_name'] . '. It now appears in the medical history.');
        redirect('vet.php');
    }

    if ($action === 'record_vaccination') {
        $vid = (int) ($_POST['vaccination_id'] ?? 0);
        $x = db_one('SELECT x.*, p.name AS pet_name FROM vaccinations x JOIN pets p ON p.pet_id = x.pet_id
                     JOIN appointments ap ON ap.pet_id = x.pet_id AND ap.vet_id = ?
                     WHERE x.vaccination_id = ? AND x.status = "Due" LIMIT 1', [$uid, $vid]);
        if (!$x) {
            flash('That vaccination was already recorded.');
            redirect($back);
        }
        $o = ['given_date' => trim((string) ($_POST['given_date'] ?? '')), 'dose' => clean('dose', 30), 'batch_number' => clean('batch_number', 50),
            'next_due_date' => trim((string) ($_POST['next_due_date'] ?? '')), 'notes' => clean('notes', 255)];
        $errs = [];
        $given = parse_date($o['given_date']);
        if (!$given) $errs['given_date'] = 'Enter the date the vaccine was given.';
        elseif ($given > date('Y-m-d')) $errs['given_date'] = 'The vaccination date can\'t be in the future.';
        if ($o['dose'] === '') $errs['dose'] = 'Enter the dose (e.g. 1 ml).';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ ]{1,49}$/', $o['batch_number'])) $errs['batch_number'] = 'Enter the batch / reference number.';
        $next = null;
        if ($o['next_due_date'] !== '') {
            $next = parse_date($o['next_due_date']);
            if (!$next || ($given && $next <= $given)) $errs['next_due_date'] = 'The next due date must be after the vaccination date.';
        }
        $files = uploaded_files('document');
        if ($files && ($problem = upload_problem($files[0], DOC_MIMES))) $errs['document'] = $problem;

        if ($errs) {
            $recordErrors = $errs;
            $recordOld = $o;
            $openRecord = $vid;
        } else {
            $path = $files ? store_upload($files[0], DOC_MIMES, 'private/vaccinations') : null;
            db_exec('UPDATE vaccinations SET status = "Given", given_date = ?, dose = ?, batch_number = ?, vet_id = ?, next_due_date = ?, notes = ?,
                     document_path = COALESCE(?, document_path), document_name = COALESCE(?, document_name) WHERE vaccination_id = ?', [
                $given, $o['dose'], strtoupper($o['batch_number']), $uid, $next, $o['notes'], $path,
                $files ? mb_substr(basename($files[0]['name']), 0, 255) : null, $vid,
            ]);
            flash($x['vaccine_name'] . ' recorded for ' . $x['pet_name'] . '.');
            redirect($back);
        }
    }
}

expire_stale_appointments();
$appointments = db_all('SELECT ap.*, p.name AS pet_name, p.breed, p.birth_date, p.photo_path, c.name AS category, u.name AS adopter_name
                        FROM appointments ap JOIN pets p ON p.pet_id = ap.pet_id JOIN categories c ON c.category_id = p.category_id
                        JOIN users u ON u.user_id = ap.adopter_id
                        WHERE ap.vet_id = ? AND ((ap.status = "Pending" AND ap.scheduled_at >= CURDATE()) OR ap.status = "Confirmed")
                        ORDER BY ap.scheduled_at', [$uid]);
$selected = null;
foreach ($appointments as $ap) {
    if ((int) $ap['appointment_id'] === $selectedId) $selected = $ap;
}
if (!$selected) {
    foreach ($appointments as $ap) {
        if ($ap['status'] === 'Confirmed') { $selected = $ap; break; }
    }
}
$selected ??= $appointments[0] ?? null;

$form = $_SESSION['checkup_form'] ?? null;
unset($_SESSION['checkup_form']);
if ($form && (!$selected || (int) $form['appt'] !== (int) $selected['appointment_id'])) {
    $form = null;
}
$fo = $form['old'] ?? [];
$fe = $form['errors'] ?? [];

$petStatus = [];
foreach ($appointments as $ap) {
    $petStatus[$ap['pet_id']] ??= vaccine_status([(int) $ap['pet_id']]);
}

$alertFor = function (int $petId) use (&$petStatus) {
    $petStatus[$petId] ??= vaccine_status([$petId]);
    foreach ($petStatus[$petId] as $s) {
        if (in_array($s['state'], ['overdue', 'dueSoon', 'due'], true)) return $s;
    }
    return null;
};

$snapshot = null;
if ($selected) {
    $pid = (int) $selected['pet_id'];
    $alert = $alertFor($pid);
    $lastCheckup = db_one('SELECT created_at, diagnosis, findings FROM checkups WHERE pet_id = ? ORDER BY created_at DESC LIMIT 1', [$pid]);
    $dueList = db_all('SELECT * FROM vaccinations WHERE pet_id = ? AND status = "Due" ORDER BY due_date', [$pid]);
    $history = medical_history([$pid]);
    $snapshot = compact('pid', 'alert', 'lastCheckup', 'dueList', 'history');
}

$err = function (string $tab, string $field) use ($fe) {
    return isset($fe[$tab][$field]) ? '<span class="field-error">' . e($fe[$tab][$field]) . '</span>' : '';
};
$inv = fn(string $tab, string $field) => isset($fe[$tab][$field]) ? 'is-invalid' : '';
$meds = $fo['meds'] ?? [['name' => '', 'dosage' => '', 'frequency' => '', 'duration' => '', 'instructions' => '']];
if (!$meds) $meds = [['name' => '', 'dosage' => '', 'frequency' => '', 'duration' => '', 'instructions' => '']];
$defaultVax = ['name' => $snapshot['alert']['record']['vaccine_name'] ?? '', 'priority' => $snapshot['alert']['record']['priority'] ?? 'Routine',
    'date' => '', 'administered' => '0', 'notes' => ''];
$vaxRows = $fo['vax'] ?? [$defaultVax];
if (!$vaxRows) $vaxRows = [$defaultVax];
$vaccineChoice = $fo['vaccine_choice'] ?? ($snapshot && $snapshot['alert'] ? 'prescribe' : 'none');
$canCheckup = $selected && $selected['status'] === 'Confirmed' && checkin_open($selected['scheduled_at']);
$missed = fn(array $ap) => $ap['status'] === 'Confirmed' && date('Y-m-d', strtotime($ap['scheduled_at'])) < date('Y-m-d');
$rErr = fn(string $f) => isset($recordErrors[$f]) ? '<span class="field-error">' . e($recordErrors[$f]) . '</span>' : '';
$badgeFor = ['overdue' => 'badge-overdue', 'dueSoon' => 'badge-dueSoon', 'due' => 'badge-pending', 'upToDate' => 'badge-completed'];

function detail(string $label, string $valueHtml): string {
    return '<div class="detail"><div class="detail-label">' . e($label) . '</div><div class="detail-value">' . $valueHtml . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Vet Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --ink: #211b28;
    --muted: #766f7c;
    --soft: #9a929f;
    --accent: #c95537;
    --accent-dark: #ad4229;
    --line: rgba(57, 45, 62, 0.15);
    --panel: rgba(255, 255, 255, 0.58);
    --surface: rgba(255, 255, 255, 0.74);
    --shadow: 0 16px 34px rgba(70, 48, 64, 0.08);
  }

  * { box-sizing: border-box; }
  html { min-width: 320px; }
  body {
    margin: 0;
    min-height: 100vh;
    color: var(--ink);
    font-family: 'Inter', sans-serif;
    background:
      radial-gradient(circle at 12% 5%, rgba(255,255,255,0.6), transparent 30%),
      linear-gradient(145deg, #fbf1e9 0%, #f5e9e5 52%, #ece8f2 100%);
  }
  button, input, textarea { font: inherit; }
  button { color: inherit; }

  .nav {
    position: sticky;
    top: 0;
    z-index: 30;
    border-bottom: 1px solid rgba(255,255,255,0.82);
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
  }
  .nav-inner {
    max-width: 1180px;
    margin: 0 auto;
    padding: 13px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .brand { display: inline-flex; align-items: center; gap: 10px; color: var(--ink); text-decoration: none; }
  .brand img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #2d2731; }
  .brand span { font-family: 'Poppins', sans-serif; font-size: 1.25rem; font-weight: 700; }
  .nav-actions { display: flex; align-items: center; gap: 12px; }
  .logout {
    padding: 9px 18px;
    border: 0;
    border-radius: 12px;
    color: #fff;
    background: #f47b26;
    box-shadow: 0 5px 12px rgba(244,123,38,0.2);
    font-weight: 700;
    text-decoration: none;
  }
  .avatar { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; color: #fff; background: #f47b26; border: 1px solid #d86319; font-weight: 600; }

  .container { width: min(1080px, calc(100% - 40px)); margin: 0 auto; padding: 40px 0 72px; }
  .page-title { margin: 0; font: 600 1.65rem/1.2 'Poppins', sans-serif; letter-spacing: -0.025em; }
  .subhead { margin: 5px 0 26px; color: var(--muted); font-size: 0.875rem; }
  .dashboard-grid { display: grid; grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr); gap: 20px; align-items: stretch; }
  .left-stack { display: grid; gap: 16px; }
  .panel {
    padding: 22px;
    border: 1px solid rgba(255,255,255,0.86);
    border-radius: 18px;
    background: var(--panel);
    box-shadow: var(--shadow);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
  }
  .panel-title { margin: 0 0 16px; font: 600 1rem/1.25 'Poppins', sans-serif; }
  .appointment-list { display: grid; gap: 10px; }
  .appointment-card {
    padding: 14px 16px;
    border: 1px solid transparent;
    border-radius: 13px;
    background: var(--surface);
    transition: border-color .18s ease, transform .18s ease;
  }
  .appointment-card.selected { border-color: #df7457; }
  .appointment-card:focus-within { border-color: var(--accent); }
  .appointment-main { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; }
  .item-title { font-weight: 600; font-size: 0.91rem; }
  .meta { margin-top: 3px; color: var(--muted); font-size: 0.78rem; line-height: 1.4; }
  .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 11px; }
  .btn {
    min-height: 32px;
    padding: 7px 13px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: rgba(255,255,255,0.7);
    cursor: pointer;
    font-size: 0.79rem;
    font-weight: 600;
    transition: transform .15s ease, background .15s ease, border-color .15s ease;
  }
  .btn:hover { transform: translateY(-1px); border-color: rgba(201,85,55,0.52); }
  .btn:focus-visible, .tab:focus-visible, .choice:focus-visible, .icon-btn:focus-visible { outline: 3px solid rgba(201,85,55,0.23); outline-offset: 2px; }
  .btn-primary { border-color: var(--accent); color: #fff; background: var(--accent); }
  .btn-primary:hover { background: var(--accent-dark); }
  .btn-wide { width: 100%; min-height: 42px; }
  .btn-danger { color: #8c3129; }
  .badge { display: inline-flex; align-items: center; white-space: nowrap; padding: 4px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; }
  .badge-confirmed, .badge-completed { color: #276440; background: #dcefe3; }
  .badge-pending, .badge-dueSoon { color: #805910; background: #f6e8bb; }
  .badge-inProgress { color: #344c98; background: #dfe6ff; }
  .badge-overdue { color: #963e34; background: #f5dcd7; }
  .badge-cancelled { color: #7b5963; background: #eee5e7; }
  .status-note { margin-top: 10px; color: #344c98; font-size: 0.76rem; font-weight: 600; }
  .status-note::before { content: ''; display: inline-block; width: 7px; height: 7px; margin-right: 5px; border-radius: 50%; background: #4d59b9; }

  .snapshot { min-height: 175px; }
  .snapshot-title { margin-bottom: 3px; font-weight: 600; font-size: 0.9rem; }
  .alert-line { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 15px; padding: 11px 12px; border: 1px solid #ee8053; border-radius: 9px; background: rgba(255,255,255,0.62); font-size: 0.78rem; }
  .snapshot-note { margin: 12px 0 15px; color: #5f5865; font-size: 0.78rem; line-height: 1.55; }

  .patient-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 12px; border-radius: 12px; background: var(--surface); }
  .patient-id { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .patient-id img { width: 38px; height: 38px; border-radius: 9px; object-fit: cover; }
  .patient-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.86rem; font-weight: 600; }
  .patient-sub { margin-top: 2px; color: var(--muted); font-size: 0.72rem; }
  .tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 14px 0 16px; padding: 4px; border-radius: 11px; background: rgba(255,255,255,0.48); }
  .tab { padding: 9px 8px; border: 0; border-radius: 8px; background: transparent; cursor: pointer; font-size: 0.78rem; font-weight: 600; }
  .tab.active { color: #fff; background: var(--accent); box-shadow: 0 4px 10px rgba(201,85,55,0.18); }
  .tab-panel[hidden] { display: none; }
  .section-label { margin: 0 0 10px; color: var(--soft); font-size: 0.68rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
  .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .field-grid .span-2 { grid-column: 1 / -1; }
  .field, textarea.field {
    width: 100%;
    min-height: 40px;
    margin: 0;
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 9px;
    color: var(--ink);
    background: rgba(255,255,255,0.68);
    font-size: 0.82rem;
    outline: none;
  }
  textarea.field { min-height: 62px; resize: vertical; }
  .field:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(201,85,55,0.1); }
  .form-actions { margin-top: 12px; }
  .medicine-row, .vaccine-form { padding: 10px; border-radius: 12px; background: rgba(255,255,255,0.42); }
  .add-row { width: 100%; margin-top: 9px; padding: 9px; border: 1px dashed rgba(57,45,62,.24); border-radius: 8px; background: transparent; cursor: pointer; font-size: .78rem; font-weight: 600; }
  .choice-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
  .choice { padding: 8px 12px; border: 1px solid var(--line); border-radius: 8px; background: rgba(255,255,255,.7); cursor: pointer; font-size: .77rem; font-weight: 600; }
  .choice.active { color: #fff; border-color: var(--accent); background: var(--accent); }
  .help-text { margin: 8px 0 10px; color: var(--muted); font-size: .76rem; line-height: 1.45; }

  .modal-backdrop { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 20px; background: rgba(33,27,40,.3); backdrop-filter: blur(4px); }
  .modal-backdrop[hidden] { display: none; }
  .modal { position: relative; width: min(490px, 100%); max-height: min(720px, calc(100vh - 40px)); overflow: auto; padding: 24px; border: 1px solid rgba(255,255,255,.86); border-radius: 18px; background: #fbf3ed; box-shadow: 0 28px 70px rgba(39,25,37,.24); }
  .modal.wide { width: min(620px, 100%); }
  .icon-btn { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border: 1px solid var(--line); border-radius: 9px; background: rgba(255,255,255,.65); cursor: pointer; font-size: 1.15rem; }
  .modal h2 { padding-right: 34px; margin: 0 0 4px; font: 600 1.12rem/1.3 'Poppins', sans-serif; }
  .modal-lead { margin: 0 0 18px; color: var(--muted); font-size: .78rem; }
  .modal-pet { display: flex; align-items: center; gap: 10px; margin: -4px 0 17px; }
  .modal-pet img { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; }
  .detail-list { display: grid; gap: 8px; }
  .detail { padding: 11px 12px; border-radius: 10px; background: rgba(255,255,255,.7); }
  .detail-label { color: var(--soft); font-size: .66rem; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; }
  .detail-value { margin-top: 5px; font-size: .82rem; line-height: 1.42; }
  .detail-value.split { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .dropzone { min-height: 72px; display: grid; place-items: center; margin-top: 8px; border: 1px dashed rgba(57,45,62,.18); border-radius: 10px; color: var(--soft); background: rgba(57,45,62,.04); font-size: .75rem; text-align: center; }
  .modal-actions { display: flex; gap: 10px; margin-top: 16px; }
  .modal-actions .btn { flex: 1; min-height: 40px; }
  .record-tabs { grid-template-columns: 1fr 1fr; }
  .record-list { display: grid; gap: 14px; margin-top: 4px; }
  .record-list[hidden] { display: none; }
  .record-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-radius: 11px; background: rgba(255,255,255,.72); }
  .record-row-actions { display: flex; align-items: center; gap: 8px; }
  .toast { position: fixed; z-index: 150; right: 24px; bottom: 24px; padding: 13px 18px; border-radius: 11px; color: #fff; background: #2f6f49; box-shadow: 0 12px 28px rgba(30,70,47,.25); font-size: .85rem; font-weight: 600; }
  .toast[hidden] { display: none; }

  @media (max-width: 820px) {
    .container { width: min(100% - 28px, 640px); padding-top: 28px; }
    .dashboard-grid { grid-template-columns: 1fr; }
    .checkup-panel { min-height: 0; }
    .nav-inner { padding-inline: 16px; }
  }
  @media (max-width: 540px) {
    .brand span { font-size: 1.05rem; }
    .brand img { width: 38px; height: 38px; }
    .logout { padding: 8px 12px; font-size: .8rem; }
    .avatar { width: 34px; height: 34px; }
    .container { width: min(100% - 20px, 480px); }
    .panel { padding: 16px; border-radius: 15px; }
    .appointment-main, .record-row { align-items: flex-start; flex-direction: column; }
    .field-grid { grid-template-columns: 1fr; }
    .field-grid .span-2 { grid-column: auto; }
    .modal { padding: 20px 16px; }
  }
  .modal-backdrop { display: none; }
  .modal-backdrop:target { display: grid !important; }
  #prescriptionPanel:target, #vaccinationPanel:target { display: block !important; }
  .checkup-panel:has(#prescriptionPanel:target) #checkupPanel,
  .checkup-panel:has(#vaccinationPanel:target) #checkupPanel { display: none; }
  .checkup-panel:has(#prescriptionPanel:target) [href="#prescriptionPanel"],
  .checkup-panel:has(#vaccinationPanel:target) [href="#vaccinationPanel"] { background: #C9553D; color: #fff; }
  .checkup-panel:has(#prescriptionPanel:target) [href="#checkupPanel"],
  .checkup-panel:has(#vaccinationPanel:target) [href="#checkupPanel"] { background: transparent; color: #2E2A33; }
</style>
<style>
  .modal-backdrop.open { display: grid !important; }
  .actions form, .modal-actions form { margin: 0; }
  .modal-actions form { flex: 1; display: flex; }
  .modal-actions form .btn { flex: 1; }
  a.btn { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
  .appointment-card.selected .item-title a { color: inherit; }
  .empty-panel { padding: 26px 8px; text-align: center; color: var(--muted); font-size: .84rem; line-height: 1.6; }
  fieldset.checkup-fields { border: 0; padding: 0; margin: 0; min-width: 0; }
  fieldset.checkup-fields:disabled { opacity: .6; }
  select.field { appearance: auto; }
  .row-tools { display: flex; justify-content: flex-end; margin-top: 6px; }
  .remove-row { border: 0; background: none; color: #8c3129; font-size: .74rem; font-weight: 600; cursor: pointer; padding: 2px 4px; }
  .medicine-row + .medicine-row, .vaccine-form + .vaccine-form { margin-top: 9px; }
  .field-wrap { display: flex; flex-direction: column; min-width: 0; }
  .field-grid .field-wrap.span-2 { grid-column: 1 / -1; }
  .checkup-panel .form-alert, .modal .form-alert { margin: 0 0 12px; }
  .history-type { font-size: .68rem; font-weight: 700; color: #A8532E; background: rgba(193,102,60,0.1); padding: 3px 9px; border-radius: 100px; white-space: nowrap; }
  .record-list .empty-note { padding: 4px 2px; }
  .detail-value a { font-weight: 600; }
  label.dropzone { cursor: pointer; }
</style>
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <?= flash_html() ?>
  <header class="nav">
    <div class="nav-inner">
      <a class="brand" href="index.php">
        <img src="assets/logo.png" alt="PawHome logo">
        <span>PawHome</span>
      </a>
      <div class="nav-actions">
        <?= logout_button('logout') ?>
        <div class="avatar" aria-label="<?= e($me['name']) ?> profile"><?= e(initials($me['name'])) ?></div>
      </div>
    </div>
  </header>

  <main class="container">
    <h1 class="page-title"><?= e($me['name']) ?></h1>
    <p class="subhead">Today's appointments and patient records.</p>

    <div class="dashboard-grid">
      <div class="left-stack">
        <section class="panel" aria-labelledby="appointmentsTitle">
          <h2 class="panel-title" id="appointmentsTitle">Appointments</h2>
          <div class="appointment-list" id="appointmentList">
            <?php foreach ($appointments as $ap): $aid = (int) $ap['appointment_id']; $isSel = $selected && $aid === (int) $selected['appointment_id']; ?>
            <article class="appointment-card <?= $isSel ? 'selected' : '' ?>">
              <div class="appointment-main"><div><div class="item-title"><a href="vet.php?appt=<?= $aid ?>"><?= e($ap['pet_name']) ?> · <?= e($ap['reason']) ?></a></div><div class="meta"><?= e($ap['adopter_name']) ?> — <?= fmt_date($ap['scheduled_at']) ?>, <?= fmt_time($ap['scheduled_at']) ?></div></div><span class="badge <?= $missed($ap) ? 'badge-overdue' : ($ap['status'] === 'Confirmed' ? 'badge-confirmed' : 'badge-pending') ?>"><?= $missed($ap) ? 'Missed' : e($ap['status']) ?></span></div>
              <div class="actions">
                <?php if ($ap['status'] === 'Confirmed' && !checkin_open($ap['scheduled_at'])): ?>
                  <span class="btn" aria-disabled="true" title="Checkups can be recorded from the day of the appointment" style="opacity:.55;cursor:not-allowed">Opens <?= fmt_date($ap['scheduled_at']) ?></span>
                <?php elseif ($ap['status'] === 'Confirmed'): ?>
                  <a class="btn btn-primary" href="vet.php?appt=<?= $aid ?>#checkupPanel">Start Checkup</a>
                <?php else: ?>
                  <form class="inline-form" method="post" action="vet.php?appt=<?= $aid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_appointment"><input type="hidden" name="appointment_id" value="<?= $aid ?>"><button class="btn btn-primary" type="submit">Confirm</button></form>
                <?php endif; ?>
                <form class="inline-form" method="post" action="vet.php"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_appointment"><input type="hidden" name="appointment_id" value="<?= $aid ?>"><button class="btn btn-danger" type="submit" data-confirm="Cancel <?= e($ap['pet_name']) ?>'s appointment on <?= e(fmt_datetime($ap['scheduled_at'])) ?>?">Cancel</button></form>
                <a class="btn" href="vet.php?appt=<?= $aid ?>#appt-<?= $aid ?>">Details</a>
              </div>
            </article>
            <?php endforeach; ?>
            <?php if (!$appointments): ?><p class="empty-note">No upcoming appointments. New bookings from adopters will appear here.</p><?php endif; ?>
          </div>
        </section>

        <section class="panel snapshot" aria-labelledby="snapshotTitle">
          <div id="snapshotContent">
            <?php if ($selected): $s = $snapshot; ?>
              <div class="snapshot-title" id="snapshotTitle">Patient Snapshot — <?= e($selected['pet_name']) ?></div>
              <div class="meta"><?= e($selected['breed']) ?> · <?= e($selected['category']) ?> · <?= e(fmt_age($selected['birth_date'])) ?> · <?= e($selected['reason']) ?> at <?= fmt_time($selected['scheduled_at']) ?></div>
              <?php if ($s['alert']): ?>
                <div class="alert-line"><span>Vaccination Alert: <?= e($s['alert']['record']['vaccine_name']) ?> — <?= e(lcfirst(preg_replace('/^Due [^—]+— ?/u', '', $s['alert']['sub']) ?: $s['alert']['sub'])) ?>.</span><span class="badge <?= $badgeFor[$s['alert']['state']] ?>"><?= e($s['alert']['badge']) ?></span></div>
              <?php else: ?>
                <div class="alert-line" style="border-color:#9fcfb1"><span>No vaccinations due.</span></div>
              <?php endif; ?>
              <p class="snapshot-note"><?= $s['lastCheckup'] ? 'Last checkup ' . fmt_date($s['lastCheckup']['created_at']) . ' — ' . e($s['lastCheckup']['diagnosis']) . '.' : 'No previous checkups on record.' ?></p>
              <a class="btn btn-wide" href="#recordsModal">View Full Medical Record</a>
            <?php else: ?>
              <div class="snapshot-title" id="snapshotTitle">Patient Snapshot</div>
              <p class="snapshot-note">Select an appointment to see the patient's details and records.</p>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <section class="panel checkup-panel" aria-labelledby="checkupTitle">
        <h2 class="panel-title" id="checkupTitle">Checkup form</h2>
        <?php if (!$selected): ?>
          <div class="empty-panel">No appointment selected.<br>Confirmed appointments can be checked in here.</div>
        <?php else: $aid = (int) $selected['appointment_id']; ?>
        <div class="patient-head">
          <div class="patient-id">
            <img src="<?= e(pet_photo_url($selected['photo_path'])) ?>" alt="<?= e($selected['pet_name']) ?>">
            <div>
              <div class="patient-name">Checkup Form — <?= e($selected['pet_name']) ?></div>
              <div class="patient-sub"><?= e($selected['reason']) ?> · <?= fmt_time($selected['scheduled_at']) ?> · <?= e($selected['adopter_name']) ?></div>
            </div>
          </div>
          <span class="badge <?= $missed($selected) ? 'badge-overdue' : ($canCheckup ? 'badge-confirmed' : 'badge-pending') ?>"><?= $missed($selected) ? 'Missed' : e($selected['status']) ?></span>
        </div>

        <div class="tabs" role="tablist" aria-label="Checkup sections">
          <a class="tab active" role="tab" href="#checkupPanel">Checkup</a>
          <a class="tab" role="tab" href="#prescriptionPanel">Prescription</a>
          <a class="tab" role="tab" href="#vaccinationPanel">Vaccination</a>
        </div>

        <form method="post" action="vet.php?appt=<?= $aid ?>" id="checkupForm" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_checkup">
          <input type="hidden" name="appointment_id" value="<?= $aid ?>">
          <?php if ($selected['status'] !== 'Confirmed'): ?><div class="form-alert">Confirm this appointment to start the checkup.</div>
          <?php elseif (!$canCheckup): ?><div class="form-alert">This appointment is on <?= fmt_date($selected['scheduled_at']) ?> — the checkup form opens on the day.</div><?php endif; ?>
          <?php if ($fe): ?><div class="form-alert">The record wasn't saved — please fix the highlighted fields<?= count($fe) > 1 ? ' (check every tab)' : '' ?>.</div><?php endif; ?>
          <fieldset class="checkup-fields" <?= $canCheckup ? '' : 'disabled' ?>>

        <div class="tab-panel" id="checkupPanel" role="tabpanel">
          <p class="section-label">Checkup details</p>
          <div class="field-grid">
            <div class="field-wrap"><input class="field <?= $inv('checkup', 'weight_kg') ?>" name="weight_kg" type="number" step="0.01" min="0" max="200" placeholder="Weight (kg)" value="<?= e($fo['weight_kg'] ?? '') ?>" aria-label="Weight in kg"><?= $err('checkup', 'weight_kg') ?></div>
            <div class="field-wrap"><input class="field <?= $inv('checkup', 'temperature_c') ?>" name="temperature_c" type="number" step="0.1" min="30" max="45" placeholder="Temperature (°C)" value="<?= e($fo['temperature_c'] ?? '') ?>" aria-label="Temperature in °C"><?= $err('checkup', 'temperature_c') ?></div>
            <input class="field span-2" name="symptoms" maxlength="255" placeholder="Symptoms (or None reported)" value="<?= e($fo['symptoms'] ?? '') ?>" aria-label="Symptoms">
            <div class="field-wrap span-2"><textarea class="field <?= $inv('checkup', 'findings') ?>" name="findings" maxlength="2000" placeholder="Clinical findings" aria-label="Clinical findings"><?= e($fo['findings'] ?? '') ?></textarea><?= $err('checkup', 'findings') ?></div>
            <div class="field-wrap span-2"><input class="field <?= $inv('checkup', 'diagnosis') ?>" name="diagnosis" maxlength="255" placeholder="Diagnosis" value="<?= e($fo['diagnosis'] ?? '') ?>" aria-label="Diagnosis"><?= $err('checkup', 'diagnosis') ?></div>
            <input class="field span-2" name="treatment" maxlength="255" placeholder="Treatment" value="<?= e($fo['treatment'] ?? '') ?>" aria-label="Treatment">
            <div class="field-wrap"><input class="field <?= $inv('checkup', 'follow_up_date') ?>" name="follow_up_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($fo['follow_up_date'] ?? '') ?>" aria-label="Follow-up date" title="Follow-up date (optional)"><?= $err('checkup', 'follow_up_date') ?></div>
            <input class="field" name="notes" maxlength="255" placeholder="Additional notes" value="<?= e($fo['notes'] ?? '') ?>" aria-label="Additional notes">
          </div>
          <div class="form-actions"><button class="btn btn-primary btn-wide save-checkup" type="submit">Save Checkup Record</button></div>
        </div>

        <div class="tab-panel" id="prescriptionPanel" role="tabpanel" hidden>
          <p class="section-label">Prescription / medicines</p>
          <?php if (isset($fe['prescription']['_general'])): ?><div class="form-alert"><?= e($fe['prescription']['_general']) ?></div><?php endif; ?>
          <label class="help-text"><input id="noMeds" name="no_medication" type="checkbox" <?= !empty($fo['no_medication']) ? 'checked' : '' ?>> No medication prescribed</label>
          <div id="medicineList">
            <?php foreach ($meds as $i => $m): $me_ = $fe['prescription']['meds'][$i] ?? []; ?>
            <div class="medicine-row">
              <div class="field-grid">
                <?php foreach (['name' => 'Medicine name', 'dosage' => 'Dosage (e.g. 50mg)', 'frequency' => 'Frequency (e.g. 2x daily)', 'duration' => 'Duration (e.g. 7 days)'] as $k => $ph): ?>
                  <div class="field-wrap"><input class="field <?= isset($me_[$k]) ? 'is-invalid' : '' ?>" name="meds[<?= $i ?>][<?= $k ?>]" maxlength="<?= $k === 'name' ? 100 : 50 ?>" placeholder="<?= $ph ?>" value="<?= e($m[$k]) ?>" aria-label="<?= $ph ?>"><?= isset($me_[$k]) ? '<span class="field-error">' . e($me_[$k]) . '</span>' : '' ?></div>
                <?php endforeach; ?>
                <input class="field span-2" name="meds[<?= $i ?>][instructions]" maxlength="255" placeholder="Instructions (e.g. give with food)" value="<?= e($m['instructions']) ?>" aria-label="Medicine instructions">
              </div>
              <div class="row-tools"><button type="button" class="remove-row" data-remove-row>Remove</button></div>
            </div>
            <?php endforeach; ?>
          </div>
          <button class="add-row" id="addMedicine" type="button">+ Add Another Medicine</button>
          <div class="form-actions"><button class="btn btn-primary btn-wide save-checkup" type="submit">Save Checkup Record</button></div>
        </div>

        <div class="tab-panel" id="vaccinationPanel" role="tabpanel" hidden>
          <p class="section-label">Vaccination assessment</p>
          <?php if ($snapshot['alert']): $al = $snapshot['alert']; ?>
            <div class="alert-line"><span>Vaccination Alert: <?= e($selected['pet_name']) ?>'s <?= e($al['record']['vaccine_name']) ?> <?= $al['record']['status'] === 'Due' ? 'is due ' . fmt_date($al['record']['due_date']) : 'booster was due ' . fmt_date($al['record']['next_due_date']) ?>.</span><span class="badge <?= $badgeFor[$al['state']] ?>"><?= e($al['badge']) ?></span></div>
          <?php else: ?>
            <div class="alert-line" style="border-color:#9fcfb1"><span><?= e($selected['pet_name']) ?> has no vaccinations due.</span></div>
          <?php endif; ?>
          <?php if (isset($fe['vaccination']['_general'])): ?><div class="form-alert" style="margin-top:12px"><?= e($fe['vaccination']['_general']) ?></div><?php endif; ?>
          <input type="hidden" name="vaccine_choice" id="vaccineChoice" value="<?= $vaccineChoice ?>">
          <div class="choice-row" role="group" aria-label="Vaccination assessment">
            <button class="choice <?= $vaccineChoice === 'prescribe' ? 'active' : '' ?>" type="button" data-vaccine-choice="prescribe" aria-pressed="<?= $vaccineChoice === 'prescribe' ? 'true' : 'false' ?>">Prescribe New Vaccine</button>
            <button class="choice <?= $vaccineChoice === 'none' ? 'active' : '' ?>" type="button" data-vaccine-choice="none" aria-pressed="<?= $vaccineChoice === 'none' ? 'true' : 'false' ?>">Not Needed</button>
          </div>
          <div id="vaccineSection" <?= $vaccineChoice === 'none' ? 'hidden' : '' ?>>
            <div id="vaccineList">
              <?php foreach ($vaxRows as $i => $v): $ve = $fe['vaccination']['vax'][$i] ?? []; ?>
              <div class="vaccine-form">
                <div class="field-grid">
                  <div class="field-wrap span-2"><input class="field <?= isset($ve['name']) ? 'is-invalid' : '' ?>" name="vax[<?= $i ?>][name]" maxlength="80" placeholder="Vaccine name" value="<?= e($v['name']) ?>" aria-label="Vaccine name"><?= isset($ve['name']) ? '<span class="field-error">' . e($ve['name']) . '</span>' : '' ?></div>
                  <select class="field span-2" name="vax[<?= $i ?>][priority]" aria-label="Clinical priority">
                    <?php foreach (['Routine', 'Priority', 'Urgent'] as $p): ?><option <?= $v['priority'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
                  </select>
                  <p class="help-text span-2">Set when it's due, or mark it administered today and set the next booster date.</p>
                  <div class="field-wrap"><input class="field <?= isset($ve['date']) ? 'is-invalid' : '' ?>" type="date" name="vax[<?= $i ?>][date]" value="<?= e($v['date'] ?? '') ?>" aria-label="Due date, or next booster date if administered today" title="Due date — or next booster date if administered today"><?= isset($ve['date']) ? '<span class="field-error">' . e($ve['date']) . '</span>' : '' ?></div>
                  <select class="field" name="vax[<?= $i ?>][administered]" aria-label="Administered today">
                    <option value="0" <?= ($v['administered'] ?? '0') === '0' ? 'selected' : '' ?>>Not given yet</option>
                    <option value="1" <?= ($v['administered'] ?? '0') === '1' ? 'selected' : '' ?>>Administered today</option>
                  </select>
                  <input class="field span-2" name="vax[<?= $i ?>][notes]" maxlength="255" placeholder="Additional notes" value="<?= e($v['notes']) ?>" aria-label="Vaccination notes">
                </div>
                <div class="row-tools"><button type="button" class="remove-row" data-remove-row>Remove</button></div>
              </div>
              <?php endforeach; ?>
            </div>
            <button class="add-row" id="addVaccine" type="button">+ Add Another Vaccine</button>
          </div>
          <div class="form-actions"><button class="btn btn-primary btn-wide save-checkup" type="submit">Save Checkup Record</button></div>
        </div>
          </fieldset>
        </form>
        <?php endif; ?>
      </section>
    </div>
  </main>

  <?= dev_footer() ?>

  <?php foreach ($appointments as $ap): $aid = (int) $ap['appointment_id']; $al = $alertFor((int) $ap['pet_id']); ?>
  <div class="modal-backdrop" id="appt-<?= $aid ?>">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="apptTitle<?= $aid ?>">
      <a class="icon-btn" href="#" aria-label="Close appointment details">×</a>
      <div class="modal-pet"><img src="<?= e(pet_photo_url($ap['photo_path'])) ?>" alt="<?= e($ap['pet_name']) ?>"><div><h2 id="apptTitle<?= $aid ?>"><?= e($ap['pet_name']) ?> · <?= e($ap['reason']) ?></h2><div class="modal-lead" style="margin:0"><?= e($ap['breed']) ?> · <?= e($ap['category']) ?> · <?= e(fmt_age($ap['birth_date'])) ?><br><?= e($ap['adopter_name']) ?> — <?= fmt_date($ap['scheduled_at']) ?> at <?= fmt_time($ap['scheduled_at']) ?></div></div></div>
      <div class="detail-list">
        <?= detail('Reason for visit', e($ap['reason'])) ?>
        <?= detail('Symptoms', 'None reported') ?>
        <?= detail('Status', e($ap['status'])) ?>
        <div class="detail"><div class="detail-label">Vaccination alert</div><div class="detail-value split"><?php if ($al): ?><span><?= e($al['record']['vaccine_name']) ?> — <?= e($al['sub']) ?></span><span class="badge <?= $badgeFor[$al['state']] ?>"><?= e($al['badge']) ?></span><?php else: ?><span>None — vaccinations up to date</span><?php endif; ?></div></div>
        <div><div class="section-label">Submitted document</div><div class="dropzone"><?= $ap['document_name'] ? '<a href="file.php?kind=appointment&amp;id=' . $aid . '" target="_blank" rel="noopener">' . e($ap['document_name']) . '</a>' : 'No document uploaded' ?></div></div>
      </div>
      <div class="modal-actions">
        <?php if ($ap['status'] === 'Confirmed' && !checkin_open($ap['scheduled_at'])): ?>
          <span class="btn" aria-disabled="true" style="opacity:.55;cursor:not-allowed">Opens <?= fmt_date($ap['scheduled_at']) ?></span>
        <?php elseif ($ap['status'] === 'Confirmed'): ?>
          <a class="btn btn-primary" href="vet.php?appt=<?= $aid ?>#checkupPanel">Start Checkup</a>
        <?php else: ?>
          <form method="post" action="vet.php?appt=<?= $aid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_appointment"><input type="hidden" name="appointment_id" value="<?= $aid ?>"><button class="btn btn-primary" type="submit">Confirm Appointment</button></form>
        <?php endif; ?>
        <a class="btn" href="#" data-close>Close</a>
      </div>
    </section>
  </div>
  <?php endforeach; ?>

  <?php if ($selected): $s = $snapshot; ?>
  <div class="modal-backdrop" id="recordsModal">
    <section class="modal wide" role="dialog" aria-modal="true" aria-labelledby="recordsTitle">
      <a class="icon-btn" href="#" aria-label="Close medical records">×</a>
      <h2 id="recordsTitle">Medical Records — <span id="recordsPetName"><?= e($selected['pet_name']) ?></span></h2>
      <div class="tabs record-tabs" role="tablist" aria-label="Medical records sections">
        <button class="tab active" type="button" role="tab" aria-selected="true" data-record-tab="due">Due Vaccinations</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-record-tab="history">Medical History</button>
      </div>
      <div id="dueRecords" class="record-list">
        <?php foreach ($s['dueList'] as $x): $over = $x['due_date'] && $x['due_date'] < date('Y-m-d'); ?>
          <article class="record-row"><div><div class="item-title"><?= e($x['vaccine_name']) ?></div><div class="meta">Due <?= fmt_date($x['due_date']) ?> · <?= e($x['priority']) ?></div></div><div class="record-row-actions"><span class="badge <?= $over ? 'badge-overdue' : 'badge-dueSoon' ?>"><?= $over ? 'Overdue' : 'Due' ?></span><a class="btn btn-primary" href="#recvax-<?= $x['vaccination_id'] ?>">Record Vaccination</a></div></article>
        <?php endforeach; ?>
        <?php if (!$s['dueList']): ?><p class="empty-note">No vaccinations are due for <?= e($selected['pet_name']) ?>.</p><?php endif; ?>
      </div>
      <div id="historyRecords" class="record-list" hidden>
        <?php foreach ($s['history'] as $h): ?>
          <article class="record-row"><div><div class="item-title"><?= e(preg_replace('/^[^·]+· /u', '', $h['title'])) ?></div><div class="meta"><?= fmt_date($h['date']) ?> — <?= e($h['sub']) ?></div></div><div class="record-row-actions"><span class="history-type"><?= e($h['type']) ?></span><a class="btn" href="#vhist-<?= e($h['key']) ?>">View</a></div></article>
        <?php endforeach; ?>
        <?php if (!$s['history']): ?><p class="empty-note">No checkups, prescriptions or vaccinations recorded yet.</p><?php endif; ?>
      </div>
    </section>
  </div>

  <?php foreach ($s['dueList'] as $x): $vid = (int) $x['vaccination_id']; $o = $openRecord === $vid ? $recordOld : []; $re = $openRecord === $vid ? $rErr : fn($f) => ''; ?>
  <div class="modal-backdrop <?= $openRecord === $vid ? 'open' : '' ?>" id="recvax-<?= $vid ?>">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="recvaxTitle<?= $vid ?>">
      <a class="icon-btn" href="#recordsModal" aria-label="Close vaccination form">×</a>
      <h2 id="recvaxTitle<?= $vid ?>">Record Vaccination</h2>
      <p class="modal-lead"><?= e($selected['pet_name']) ?> · <?= e($x['vaccine_name']) ?></p>
      <form method="post" action="vet.php?appt=<?= (int) $selected['appointment_id'] ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_vaccination">
        <input type="hidden" name="vaccination_id" value="<?= $vid ?>">
        <div class="field-grid">
          <label class="field-wrap"><span class="section-label">Vaccination date</span><input class="field" name="given_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($o['given_date'] ?? date('Y-m-d')) ?>" required><?= $re('given_date') ?></label>
          <label class="field-wrap"><span class="section-label">Dose</span><input class="field" name="dose" maxlength="30" placeholder="1 ml" value="<?= e($o['dose'] ?? '') ?>" required><?= $re('dose') ?></label>
          <label class="field-wrap"><span class="section-label">Batch / reference number</span><input class="field" name="batch_number" maxlength="50" placeholder="LOT-3042" value="<?= e($o['batch_number'] ?? '') ?>" required><?= $re('batch_number') ?></label>
          <label class="field-wrap"><span class="section-label">Veterinarian</span><input class="field" value="<?= e($me['name']) ?>" readonly></label>
          <label class="field-wrap span-2"><span class="section-label">Next vaccination due date</span><input class="field" name="next_due_date" type="date" value="<?= e($o['next_due_date'] ?? date('Y-m-d', strtotime('+1 year'))) ?>"><?= $re('next_due_date') ?></label>
          <label class="field-wrap span-2"><span class="section-label">Notes</span><textarea class="field" name="notes" maxlength="255" placeholder="Any observations..."><?= e($o['notes'] ?? '') ?></textarea></label>
        </div>
        <p class="section-label" style="margin-top:14px">Vaccination record document</p>
        <div class="dropzone" data-dropzone tabindex="0" role="button"><span data-dz-label>Drop the vaccination certificate</span><input type="file" name="document" accept="image/*,.pdf" hidden></div>
        <?= $re('document') ?>
        <div class="modal-actions">
          <button class="btn btn-primary" type="submit">Save Vaccination</button>
          <a class="btn" href="#recordsModal">Cancel</a>
        </div>
      </form>
    </section>
  </div>
  <?php endforeach; ?>

  <?php foreach ($s['history'] as $h): $r = $h['record']; ?>
  <div class="modal-backdrop" id="vhist-<?= e($h['key']) ?>">
    <section class="modal" role="dialog" aria-modal="true">
      <a class="icon-btn" href="#recordsModal" aria-label="Back to medical records">×</a>
      <?php if ($h['type'] === 'Checkup'): $rx = db_all('SELECT * FROM prescriptions WHERE checkup_id = ?', [$r['checkup_id']]); ?>
        <h2><?= e($r['pet_name']) ?> · Checkup</h2><p class="modal-lead"><?= fmt_date($r['created_at']) ?> · <?= e($r['vet_name']) ?></p>
        <div class="detail-list">
          <?= detail('Reason for visit', e($r['reason'])) ?>
          <?= detail('Weight · Temperature', fmt_weight($r['weight_kg']) . ' · ' . fmt_temp($r['temperature_c'])) ?>
          <?= detail('Symptoms', e($r['symptoms'] ?: 'None reported')) ?>
          <?= detail('Clinical findings', nl2br(e($r['findings']))) ?>
          <?= detail('Diagnosis', e($r['diagnosis'])) ?>
          <?= detail('Treatment', e($r['treatment'] ?: '—')) ?>
          <?= detail('Prescription', $rx ? implode('<br>', array_map(fn($m) => e($m['medicine_name'] . ' — ' . $m['dosage'] . ', ' . $m['frequency'] . ', ' . $m['duration']), $rx)) : 'No medication prescribed') ?>
          <?= detail('Follow-up', $r['follow_up_date'] ? fmt_date($r['follow_up_date']) : 'None') ?>
          <?php if ($r['notes'] !== ''): ?><?= detail('Notes', e($r['notes'])) ?><?php endif; ?>
        </div>
      <?php elseif ($h['type'] === 'Prescription'): $c = $r['checkup']; ?>
        <h2><?= e($c['pet_name']) ?> · <?= e($r['medicine_name']) ?></h2><p class="modal-lead">Prescribed <?= fmt_date($c['created_at']) ?> by <?= e($c['vet_name']) ?></p>
        <div class="detail-list">
          <?= detail('Dosage', e($r['dosage'])) ?><?= detail('Frequency', e($r['frequency'])) ?><?= detail('Duration', e($r['duration'])) ?>
          <?= detail('Instructions', e($r['instructions'] ?: '—')) ?><?= detail('Prescribed for', e($c['diagnosis'])) ?>
        </div>
      <?php else: ?>
        <h2><?= e($r['pet_name']) ?> · <?= e($r['vaccine_name']) ?></h2><p class="modal-lead">Given <?= fmt_date($r['given_date']) ?></p>
        <div class="detail-list">
          <?= detail('Dose', e($r['dose'] ?: '—')) ?><?= detail('Batch number', e($r['batch_number'] ?: '—')) ?>
          <?= detail('Veterinarian', e($r['vet_name'] ?? '—')) ?><?= detail('Next due date', $r['next_due_date'] ? fmt_date($r['next_due_date']) : 'Not required') ?>
          <?php if ($r['notes'] !== ''): ?><?= detail('Notes', e($r['notes'])) ?><?php endif; ?>
          <?php if ($r['document_name']): ?><?= detail('Vaccination certificate', '<a href="file.php?kind=vaccination&amp;id=' . (int) $r['vaccination_id'] . '" target="_blank" rel="noopener">' . e($r['document_name']) . '</a>') ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <script src="assets/js/ui.js"></script>
  <script src="assets/js/vet.js"></script>
</body>
</html>
