<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/medical.php';
require_once __DIR__ . '/includes/slots.php';
require_once __DIR__ . '/includes/messages.php';

$me = require_role('adopter');
$uid = $me['user_id'];

$myPets = db_all('SELECT p.*, c.name AS category FROM pets p
                  JOIN categories c ON c.category_id = p.category_id
                  JOIN applications a ON a.pet_id = p.pet_id AND a.adopter_id = ? AND a.status = "Completed"
                  ORDER BY p.name', [$uid]);
$petIds = array_map('intval', array_column($myPets, 'pet_id'));
$petsById = array_column($myPets, null, 'pet_id');
$vets = db_all('SELECT u.user_id, u.name, vp.clinic, vp.specialization, vp.bio FROM users u
                JOIN vet_profiles vp ON vp.user_id = u.user_id
                WHERE u.role = "vet" AND u.status = "active" ORDER BY u.name');
$vetIds = array_map('intval', array_column($vets, 'user_id'));

$bookErrors = [];
$noteErrors = [];
$openModal = null;
$book = ['vet_id' => $vetIds[0] ?? 0, 'pet_id' => (int) ($_GET['book_pet'] ?? 0), 'slot' => '', 'reason' => mb_substr(trim((string) ($_GET['reason'] ?? '')), 0, 255)];
$note = ['pet_id' => 0, 'note_date' => date('Y-m-d'), 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('That file is too large — attachments can be up to 5 MB.');
    redirect('adopter.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $openModal = 'bookVetBackdrop';
        $book = ['vet_id' => (int) ($_POST['vet_id'] ?? 0), 'pet_id' => (int) ($_POST['pet_id'] ?? 0),
            'slot' => (string) ($_POST['slot'] ?? ''), 'reason' => clean('reason', 255)];
        if (!in_array($book['vet_id'], $vetIds, true)) {
            $bookErrors['vet_id'] = 'Choose a vet from the list.';
        }
        if (!isset($petsById[$book['pet_id']])) {
            $bookErrors['pet_id'] = 'Choose one of your pets.';
        }
        if ($book['slot'] === '') {
            $bookErrors['slot'] = 'Choose an available time slot.';
        } elseif (!isset($bookErrors['vet_id']) && ($problem = slot_problem($book['vet_id'], $uid, $book['slot']))) {
            $bookErrors['slot'] = $problem;
        }
        if (mb_strlen($book['reason']) < 3) {
            $bookErrors['reason'] = 'Tell the vet the reason for the visit.';
        }
        $files = uploaded_files('document');
        if ($files && ($problem = upload_problem($files[0], DOC_MIMES))) {
            $bookErrors['document'] = $problem;
        }
        if (!$bookErrors) {
            $path = $files ? store_upload($files[0], DOC_MIMES, 'private/appointments') : null;
            try {
                db_exec('INSERT INTO appointments (pet_id, adopter_id, vet_id, scheduled_at, reason, document_path, document_name) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $book['pet_id'], $uid, $book['vet_id'], $book['slot'], $book['reason'], $path, $files ? mb_substr(basename($files[0]['name']), 0, 255) : null,
                ]);
                redirect('adopter.php#bookSuccessBackdrop');
            } catch (mysqli_sql_exception $ex) {
                
                
                if ($ex->getCode() !== 1062) {
                    throw $ex;
                }
                delete_upload($path);
                $bookErrors['slot'] = str_contains($ex->getMessage(), 'uniq_adopter_slot')
                    ? 'You already have an appointment at that time — please pick a different slot.'
                    : 'That time was just booked by someone else — please pick another slot.';
            }
        }
    } elseif ($action === 'cancel_appointment') {
        $id = (int) ($_POST['appointment_id'] ?? 0);
        $done = db_run('UPDATE appointments SET status = "Cancelled" WHERE appointment_id = ? AND adopter_id = ? AND status IN ("Pending","Confirmed")', [$id, $uid]);
        flash(mysqli_stmt_affected_rows($done) ? 'Appointment cancelled.' : 'That appointment can no longer be cancelled.');
        redirect('adopter.php');
    } elseif ($action === 'add_note') {
        $openModal = 'addNoteBackdrop';
        $note = ['pet_id' => (int) ($_POST['pet_id'] ?? 0), 'note_date' => (string) ($_POST['note_date'] ?? ''), 'body' => clean('body', 1000)];
        if (!isset($petsById[$note['pet_id']])) {
            $noteErrors['pet_id'] = 'Choose one of your pets.';
        }
        $d = parse_date($note['note_date']);
        if (!$d) {
            $noteErrors['note_date'] = 'Enter a valid date.';
        } elseif ($d > date('Y-m-d')) {
            $noteErrors['note_date'] = 'The date can\'t be in the future.';
        }
        if (mb_strlen($note['body']) < 5) {
            $noteErrors['body'] = 'Describe what you noticed (at least 5 characters).';
        }
        $files = uploaded_files('document');
        if ($files && ($problem = upload_problem($files[0], DOC_MIMES))) {
            $noteErrors['document'] = $problem;
        }
        if (!$noteErrors) {
            $path = $files ? store_upload($files[0], DOC_MIMES, 'private/notes') : null;
            db_exec('INSERT INTO health_notes (pet_id, adopter_id, note_date, body, document_path, document_name) VALUES (?, ?, ?, ?, ?, ?)', [
                $note['pet_id'], $uid, $d, $note['body'], $path, $files ? mb_substr(basename($files[0]['name']), 0, 255) : null,
            ]);
            flash('Health note saved.');
            redirect('adopter.php');
        }
    }
}

expire_stale_appointments();
$applications = db_all('SELECT a.*, p.name AS pet_name, p.photo_path, s.name AS shelter_name,
                        h.handover_id, h.scheduled_at, h.location AS h_location, h.notes AS h_notes, h.status AS h_status, h.completed_at
                        FROM applications a JOIN pets p ON p.pet_id = a.pet_id JOIN users s ON s.user_id = p.shelter_id
                        LEFT JOIN handovers h ON h.application_id = a.application_id
                        WHERE a.adopter_id = ? ORDER BY a.submitted_at DESC', [$uid]);
$appointments = db_all('SELECT ap.*, p.name AS pet_name, p.breed, p.photo_path, p.birth_date, c.name AS category, v.name AS vet_name
                        FROM appointments ap JOIN pets p ON p.pet_id = ap.pet_id JOIN categories c ON c.category_id = p.category_id
                        JOIN users v ON v.user_id = ap.vet_id
                        WHERE ap.adopter_id = ? AND ap.status IN ("Pending","Confirmed") AND ap.scheduled_at >= CURDATE()
                        ORDER BY ap.scheduled_at', [$uid]);
$nextApptByPet = [];
foreach ($appointments as $ap) {
    $nextApptByPet[$ap['pet_id']] ??= $ap;
}
$notes = db_all('SELECT n.*, p.name AS pet_name FROM health_notes n JOIN pets p ON p.pet_id = n.pet_id
                 WHERE n.adopter_id = ? ORDER BY n.note_date DESC, n.created_at DESC', [$uid]);
$reminders = vaccine_status($petIds);
$history = medical_history($petIds);

$chatId = isset($_GET['chat']) ? (int) $_GET['chat'] : null;
$contacts = chat_contacts($me, $chatId);
$activeChat = null;
foreach ($contacts as $c) {
    if ($c['id'] === $chatId) {
        $activeChat = $chatId;
    }
}

$appBadge = [
    'Pending' => ['badge-pending', 'Pending'], 'Approved' => ['badge-approved', 'Approved'], 'Completed' => ['badge-approved', 'Adopted'],
    'Rejected' => ['badge-rejected', 'Not approved'], 'Cancelled' => ['badge-rejected', 'Cancelled'],
];
$firstName = explode(' ', trim($me['name']))[0];
$bErr = fn(string $f) => isset($bookErrors[$f]) ? '<span class="field-error">' . e($bookErrors[$f]) . '</span>' : '';
$nErr = fn(string $f) => isset($noteErrors[$f]) ? '<span class="field-error">' . e($noteErrors[$f]) . '</span>' : '';

function info_box(string $label, string $valueHtml): string {
    return '<div class="info-box"><div class="field-label">' . e($label) . '</div><div class="info-value">' . $valueHtml . '</div></div>';
}
function file_link(string $kind, int $id, ?string $name): string {
    return $name ? '<a href="file.php?kind=' . $kind . '&amp;id=' . $id . '" target="_blank" rel="noopener">' . e($name) . '</a>' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>PawHome — Adopter Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); }
  a { color: #C1663C; text-decoration: none; }
  button { font-family: 'Inter', sans-serif; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); box-shadow: 0 1px 0 rgba(46,42,51,0.04); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; gap: 24px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; letter-spacing: -0.01em; }
  .nav-links { display: flex; align-items: center; gap: 2px; }
  .nav-links a { padding: 9px 15px; border-radius: 10px; font-size: 13.5px; font-weight: 500; color: #66606C; }
  .nav-links a.active { background: rgba(46,38,46,0.06); color: #2E2A33; font-weight: 600; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; flex-shrink: 0; }
  .btn-logout { padding: 9px 20px; border-radius: 100px; border: none; background: #E8792E; color: #fff; font-weight: 700; font-size: 13.5px; cursor: pointer; box-shadow: 0 6px 14px rgba(232,121,46,0.3); }
  .btn-logout:hover { background: #D2691E; }

  .container { max-width: 1180px; margin: 0 auto; padding: 40px 32px 88px; }
  .header-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 32px; }
  h1 { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 28px; letter-spacing: -0.01em; margin: 0 0 4px; }
  .subhead { font-size: 14px; color: #66606C; margin: 0; }
  .btn-apply { padding: 12px 22px; border: none; border-radius: 12px; background: #E8792E; color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 8px 20px rgba(232,121,46,0.3); white-space: nowrap; }
  .btn-apply:hover { background: #D2691E; }

  .card { background: rgba(255,255,255,0.5); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.75); border-radius: 18px; padding: 26px; box-shadow: 0 10px 30px rgba(46,30,35,0.06); }
  .card + .card { margin-top: 22px; }
  .two-col > .card { margin-top: 0; }
  .two-col + .card { margin-top: 22px; }
  .card h3 { font-family: 'Poppins', sans-serif; font-size: 16px; font-weight: 600; margin: 0 0 18px; }
  .card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
  .card-head h3 { margin: 0; }

  .row-list { display: flex; flex-direction: column; gap: 10px; }
  .row { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 16px 20px; }
  .row-flex { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .row-title { font-weight: 600; font-size: 14.5px; }
  .row-sub { font-size: 12.5px; color: #8A8390; margin-top: 4px; }
  .row-right { display: flex; align-items: center; gap: 10px; }
  .row-clickable { cursor: pointer; transition: background 0.15s; }
  .row-clickable:hover { background: rgba(255,255,255,0.9); }

  .badge { display: inline-flex; align-items: center; padding: 4px 11px; border-radius: 100px; font-size: 11px; font-weight: 600; letter-spacing: 0.02em; white-space: nowrap; }
  .badge-approved { background: #DCEEE2; color: #2C6B4A; }
  .badge-pending { background: #F3E6C9; color: #8A5A19; }
  .badge-rejected { background: #F5DCD6; color: #8A3B2E; }
  .badge-confirmed { background: #DCEEE2; color: #2C6B4A; }
  .badge-overdue { background: #F5DCD6; color: #8A3B2E; }
  .badge-upToDate { background: #DCEEE2; color: #2C6B4A; }

  .btn-outline { padding: 9px 14px; border: 1.5px solid rgba(46,38,46,0.15); border-radius: 10px; background: transparent; color: #2E2A33; font-weight: 600; font-size: 12.5px; cursor: pointer; }
  .btn-outline:hover { background: rgba(46,38,46,0.05); }
  .btn-small { display: inline-flex; align-items: center; justify-content: center; min-width: 62px; min-height: 30px; padding: 7px 12px; border: none; border-radius: 9px; background: #C1663C; color: #fff !important; font-weight: 600; font-size: 12px; line-height: 1; cursor: pointer; }
  .btn-small:hover { background: #A8532E; }

  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 22px; }
  .note-date { font-size: 12px; color: #8A8390; font-weight: 600; }
  .note-text { font-size: 13.5px; margin-top: 4px; line-height: 1.5; }
  .note-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
  .note-tag { font-size: 11px; font-weight: 700; color: #A8532E; background: rgba(193,102,60,0.1); padding: 3px 9px; border-radius: 100px; white-space: nowrap; }

  
  .messages-card { padding: 0; overflow: hidden; }
  .messages-grid { display: grid; grid-template-columns: 260px 1fr; height: 460px; }
  .msg-sidebar { border-right: 1px solid rgba(46,38,46,0.08); display: flex; flex-direction: column; }
  .msg-sidebar-head { padding: 20px 20px 12px; }
  .msg-sidebar-head h3 { margin: 0 0 12px; }
  .msg-search { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(46,38,46,0.12); background: rgba(255,255,255,0.7); font-size: 13px; outline: none; font-family: 'Inter', sans-serif; }
  .contact-list { flex: 1; overflow-y: auto; }
  .contact-item { display: flex; align-items: center; gap: 10px; padding: 12px 20px; cursor: pointer; }
  .contact-item:hover, .contact-item.active { background: rgba(255,255,255,0.6); }
  .contact-avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11.5px; flex-shrink: 0; }
  .contact-info { min-width: 0; flex: 1; }
  .contact-name { font-size: 13px; font-weight: 600; }
  .contact-preview { font-size: 12px; color: #8A8390; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .contact-time { font-size: 11px; color: #ADA7B0; flex-shrink: 0; }

  .chat-panel { display: flex; flex-direction: column; }
  .chat-panel-head { padding: 18px 22px; border-bottom: 1px solid rgba(46,38,46,0.08); font-weight: 600; font-size: 14.5px; }
  .chat-log { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 12px; }
  .msg { max-width: 62%; padding: 11px 15px; border-radius: 16px; font-size: 13.5px; line-height: 1.5; }
  .msg.them { background: rgba(255,255,255,0.75); align-self: flex-start; border-bottom-left-radius: 4px; }
  .msg.me { background: #C1663C; color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }
  .chat-input-row { display: flex; gap: 10px; padding: 16px 22px; border-top: 1px solid rgba(46,38,46,0.08); }
  .chat-input-row input { flex: 1; padding: 12px 16px; border-radius: 12px; border: 1px solid rgba(46,38,46,0.15); background: rgba(255,255,255,0.7); font-size: 13.5px; outline: none; font-family: 'Inter', sans-serif; }
  .chat-input-row input:focus { border-color: #C1663C; }
  .btn-send { padding: 0 20px; border: none; border-radius: 12px; background: #C1663C; color: #fff; font-weight: 600; font-size: 13.5px; cursor: pointer; }

  @media (max-width: 800px) { .two-col { grid-template-columns: 1fr; } .messages-grid { grid-template-columns: 1fr; height: auto; } .msg-sidebar { border-right: none; border-bottom: 1px solid rgba(46,38,46,0.08); max-height: 240px; } .chat-log { max-height: 300px; } }

  
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(46,38,46,0.35); align-items: center; justify-content: center; padding: 24px; z-index: 100; }
  .modal-backdrop.open, .modal-backdrop:target { display: flex; }
  .modal { width: 100%; max-width: 520px; background: rgba(255,255,255,0.97); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.8); border-radius: 22px; padding: 32px; box-shadow: 0 30px 70px rgba(46,30,35,0.25); position: relative; max-height: 88vh; overflow-y: auto; }
  .modal.modal-wide { max-width: 720px; }
  .modal-close { position: absolute; top: 18px; right: 18px; width: 32px; height: 32px; border-radius: 10px; border: none; background: rgba(46,38,46,0.08); color: #2E2A33; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; }
  .modal-close:hover { background: rgba(46,38,46,0.14); }
  .modal h2 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 21px; margin: 0 0 4px; padding-right: 36px; }
  .modal .lead { font-size: 13.5px; color: #66606C; margin: 0 0 22px; }

  .modal label.field-label, .modal .field-label { display: block; font-size: 11px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  .modal input[type=text], .modal input[type=date], .modal input[type=tel], .modal textarea, .modal select {
    width: 100%; padding: 13px 15px; border-radius: 12px; border: 1px solid rgba(46,38,46,0.15);
    background: rgba(255,255,255,0.8); font-size: 14px; outline: none; font-family: 'Inter', sans-serif; appearance: none;
  }
  .modal select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9'%3E%3Cpath d='M1 1l6 6 6-6' stroke='%2366606C' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 15px center; padding-right: 36px; }
  .modal input:focus, .modal textarea:focus, .modal select:focus { border-color: #C1663C; }
  .modal textarea { min-height: 80px; resize: vertical; }
  .modal .field { margin-bottom: 16px; }

  .dropzone { border: 1.5px dashed rgba(46,38,46,0.25); border-radius: 14px; background: rgba(46,38,46,0.03); padding: 22px 16px; text-align: center; margin-bottom: 4px; cursor: pointer; }
  .dropzone:hover { background: rgba(46,38,46,0.05); }
  .dropzone .dz-icon { font-size: 16px; margin-bottom: 6px; }
  .dropzone .dz-main { font-size: 13.5px; font-weight: 600; color: #2E2A33; }
  .dropzone .dz-sub { font-size: 12.5px; color: #66606C; margin-top: 4px; }
  .dropzone .dz-sub a { font-weight: 600; text-decoration: underline; }

  .btn-submit { display: flex; width: 100%; min-height: 46px; align-items: center; justify-content: center; padding: 13px 15px; border: none; border-radius: 14px; background: #C1663C; color: #fff !important; font-weight: 700; font-size: 15px; line-height: 1.2; text-align: center; cursor: pointer; box-shadow: 0 10px 24px rgba(180,90,55,0.3); margin-top: 16px; }
  .btn-submit:hover { background: #A8532E; }
  .btn-row-2 { display: flex; gap: 12px; margin-top: 8px; }
  .btn-row-2 button { flex: 1; padding: 13px; border-radius: 12px; font-weight: 600; font-size: 14px; cursor: pointer; }
  .timeline + .btn-submit { clear: both; position: relative; margin-top: 20px; }
  .btn-cancel-appt { border: 1.5px solid rgba(200,60,50,0.35); background: transparent; color: #A8342A; }
  .btn-cancel-appt:hover { background: rgba(200,60,50,0.06); }
  .btn-close-outline { border: 1.5px solid rgba(46,38,46,0.15); background: transparent; color: #2E2A33; }
  .btn-close-outline:hover { background: rgba(46,38,46,0.05); }

  .success-modal { max-width: 420px; background: #C1663C; padding: 44px 32px; text-align: center; }
  .success-modal .modal-close { background: rgba(255,255,255,0.18); color: #fff; }
  .success-modal .modal-close:hover { background: rgba(255,255,255,0.3); }
  .success-modal .success-text { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 18px; letter-spacing: 0.03em; text-transform: uppercase; color: #fff; margin: 0; }

  
  .timeline { margin: 4px 0 0; }
  .tl-item { position: relative; padding-left: 26px; padding-bottom: 22px; }
  .tl-item:last-child { padding-bottom: 0; }
  .tl-item::before { content: ''; position: absolute; left: 4px; top: 4px; width: 11px; height: 11px; border-radius: 50%; background: #C1663C; }
  .tl-item.pending::before { background: #D8D2DB; }
  .tl-item::after { content: ''; position: absolute; left: 9px; top: 17px; bottom: -2px; width: 1.5px; background: rgba(46,38,46,0.15); }
  .tl-item:last-child::after { display: none; }
  .tl-label { font-weight: 700; font-size: 14.5px; }
  .tl-sub { font-size: 12.5px; color: #66606C; margin-top: 2px; line-height: 1.5; }

  
  .info-box { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 14px 16px; margin-bottom: 12px; }
  .info-box .field-label { margin-bottom: 6px; }
  .info-box .info-value { font-size: 14.5px; font-weight: 600; }

  
  .certificate-frame { border: 1.5px dashed rgba(46,38,46,0.25); border-radius: 16px; padding: 30px 24px; text-align: center; }
  .cert-kicker { font-size: 11px; font-weight: 700; letter-spacing: 0.1em; color: #8A8390; text-transform: uppercase; margin-bottom: 20px; }
  .cert-photo { width: 84px; height: 84px; border-radius: 50%; margin: 0 auto 18px; background-size: cover; background-position: center; border: 3px solid #DCEEE2; }
  .cert-pet-name { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 21px; margin-bottom: 6px; }
  .cert-line { font-size: 14px; color: #46414B; margin-bottom: 4px; }
  .cert-adopter { font-weight: 700; font-size: 16px; margin-bottom: 16px; }
  .cert-issued { font-size: 12px; color: #8A8390; }

  
  .vet-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 22px; }
  .vet-list { display: flex; flex-direction: column; gap: 8px; max-height: 360px; overflow-y: auto; margin-top: 10px; }
  .vet-item { display: flex; gap: 10px; padding: 12px; border-radius: 13px; border: 1.5px solid rgba(46,38,46,0.1); cursor: pointer; background: rgba(255,255,255,0.5); }
  .vet-item:hover { border-color: rgba(193,102,60,0.4); }
  .vet-item.selected { border-color: #C1663C; background: rgba(193,102,60,0.06); }
  .vet-avatar { width: 32px; height: 32px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; flex-shrink: 0; }
  .vet-name { font-weight: 700; font-size: 13.5px; }
  .vet-sub { font-size: 11.5px; color: #8A8390; margin: 1px 0 4px; }
  .vet-bio { font-size: 12px; color: #46414B; line-height: 1.4; }
  @media (max-width: 700px) { .vet-grid { grid-template-columns: 1fr; } }

  
  .appt-head { display: flex; gap: 14px; align-items: center; margin-bottom: 22px; padding-right: 36px; }
  .appt-photo { width: 60px; height: 60px; border-radius: 14px; background-size: cover; background-position: center; flex-shrink: 0; }
  .appt-head h2 { padding-right: 0; margin-bottom: 2px; }
  .appt-sub1 { font-size: 13px; color: #66606C; }
  .appt-sub2 { font-size: 12.5px; color: #8A8390; margin-top: 2px; }
  .doc-placeholder { background: rgba(46,38,46,0.05); border-radius: 12px; padding: 20px; text-align: center; font-size: 13px; color: #8A8390; }
</style>
<style>
  .note-attach { display: inline-block; margin-top: 6px; font-size: 12.5px; font-weight: 600; }
  .vet-item:focus-visible { outline: 3px solid rgba(193,102,60,0.3); outline-offset: 2px; }
  .vet-item[hidden] { display: none; }
  .tl-item.rejected::before { background: #A8342A; }
  .rx-list { margin: 0; padding-left: 18px; font-weight: 500; line-height: 1.6; }
  .modal .empty-note { margin-bottom: 14px; }
  .modal .field-error { margin-top: 6px; }
  .dropzone + .field-error { margin: 2px 0 10px; }
</style>
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <?= flash_html() ?>

  <div class="nav">
    <div class="nav-inner">
      <a class="brand" href="index.php">
        <img src="assets/logo.png" alt="PawHome logo">
        <span>PawHome</span>
      </a>
      <div class="nav-right">
        <div class="nav-links">
          <a href="index.php">Home</a>
          <a href="browse.php">Browse Pets</a>
          <a href="adopter.php" class="active">Adopter Dashboard</a>
        </div>
        <?= logout_button('btn-logout') ?>
        <div class="avatar" title="<?= e($me['name']) ?>"><?= e(initials($me['name'])) ?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="header-row">
      <div>
        <h1>Welcome back, <?= e($firstName) ?></h1>
        <p class="subhead">Here's what's happening with your adoptions and pets.</p>
      </div>
      <a class="btn-apply" href="browse.php">Apply for Adoption</a>
    </div>

    <div class="card">
      <h3>Your applications</h3>
      <div class="row-list">
        <?php foreach ($applications as $a): [$cls, $label] = $appBadge[$a['status']]; ?>
          <div class="row row-flex">
            <div>
              <div class="row-title"><?= e($a['pet_name']) ?></div>
              <div class="row-sub">Submitted <?= fmt_date($a['submitted_at']) ?> · <?= e($a['shelter_name']) ?></div>
            </div>
            <div class="row-right">
              <span class="badge <?= $cls ?>"><?= $label ?></span>
              <?php if ($a['status'] === 'Completed'): ?><a class="btn-outline" href="#cert-<?= $a['application_id'] ?>">View Certificate</a><?php endif; ?>
              <a class="btn-outline" href="#app-<?= $a['application_id'] ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$applications): ?><p class="empty-note">You haven't applied for any pets yet. <a href="browse.php">Browse adoptable pets</a> to get started.</p><?php endif; ?>
      </div>
    </div>

    <div class="two-col">
      <div class="card">
        <div class="card-head">
          <h3>Upcoming vet appointments</h3>
          <a class="btn-small" href="#bookVetBackdrop">+ Book</a>
        </div>
        <div class="row-list">
          <?php foreach ($appointments as $ap): ?>
            <div class="row row-flex">
              <div>
                <div class="row-title"><?= e($ap['pet_name']) ?> &middot; <?= e($ap['reason']) ?></div>
                <div class="row-sub"><?= fmt_date($ap['scheduled_at']) ?> at <?= fmt_time($ap['scheduled_at']) ?> · <?= e($ap['vet_name']) ?></div>
              </div>
              <div class="row-right">
                <span class="badge <?= $ap['status'] === 'Confirmed' ? 'badge-confirmed' : 'badge-pending' ?>"><?= $ap['status'] === 'Confirmed' ? 'Confirmed' : 'Awaiting vet' ?></span>
                <a class="btn-outline" href="#appt-<?= $ap['appointment_id'] ?>">View</a>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$appointments): ?><p class="empty-note"><?= $myPets ? 'No upcoming appointments. Use "+ Book" to schedule a visit.' : 'Once you\'ve adopted a pet you can book vet visits here.' ?></p><?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head">
          <h3>Pet health notes</h3>
          <a class="btn-small" href="#addNoteBackdrop">+ Add Note</a>
        </div>
        <div class="row-list" id="healthNotesList">
          <?php foreach ($notes as $n): ?>
            <div class="row"><div class="note-row"><div>
              <div class="note-date"><?= fmt_date($n['note_date']) ?></div>
              <div class="note-text"><?= nl2br(e($n['body'])) ?></div>
              <?php if ($n['document_name']): ?><a class="note-attach" href="file.php?kind=note&amp;id=<?= $n['note_id'] ?>" target="_blank" rel="noopener">&#128206; <?= e($n['document_name']) ?></a><?php endif; ?>
            </div><span class="note-tag"><?= e($n['pet_name']) ?></span></div></div>
          <?php endforeach; ?>
          <?php if (!$notes): ?><p class="empty-note">No notes yet. Log changes in appetite, behaviour or health to share with your vet.</p><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Vaccination reminders</h3>
      <div class="row-list">
        <?php foreach ($reminders as $r): $x = $r['record']; ?>
          <div class="row row-flex">
            <div>
              <div class="row-title"><?= e($x['pet_name']) ?> &middot; <?= e($x['vaccine_name']) ?></div>
              <div class="row-sub"><?= e($r['sub']) ?></div>
            </div>
            <div class="row-right">
              <span class="badge badge-<?= $r['state'] === 'upToDate' ? 'upToDate' : ($r['state'] === 'overdue' ? 'overdue' : 'pending') ?>"><?= e($r['badge']) ?></span>
              <?php if ($x['status'] === 'Given'): ?>
                <a class="btn-outline" href="#hist-vax-<?= $x['vaccination_id'] ?>">View</a>
              <?php else: ?>
                <a class="btn-outline" href="#rem-<?= $x['vaccination_id'] ?>">View</a>
              <?php endif; ?>
              <?php if ($r['state'] !== 'upToDate'): ?>
                <a class="btn-small" href="adopter.php?book_pet=<?= $x['pet_id'] ?>&amp;reason=<?= urlencode($x['vaccine_name'] . ' vaccination') ?>#bookVetBackdrop">Book Appointment</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$reminders): ?><p class="empty-note">No vaccinations on record yet. Your vet will add them after a checkup.</p><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h3>Medical history</h3>
      <div class="row-list">
        <?php foreach ($history as $h): ?>
          <div class="row row-flex">
            <div>
              <div class="row-title"><?= e($h['title']) ?></div>
              <div class="row-sub"><?= fmt_date($h['date']) ?> — <?= e($h['sub']) ?></div>
            </div>
            <div class="row-right">
              <span class="note-tag"><?= e($h['type']) ?></span>
              <a class="btn-outline" href="#hist-<?= e($h['key']) ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$history): ?><p class="empty-note">Checkup results, prescriptions and vaccinations from your pets' vet visits will appear here.</p><?php endif; ?>
      </div>
    </div>

    <?= render_messages_panel($contacts, $activeChat, 'Search shelters...') ?>
  </div>

  <?= dev_footer() ?>

  <?php foreach ($applications as $a): $id = $a['application_id']; ?>
  <div class="modal-backdrop" id="app-<?= $id ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <h2><?= e($a['pet_name']) ?></h2>
      <p class="lead">Submitted <?= fmt_date($a['submitted_at']) ?> · <?= e($a['shelter_name']) ?></p>
      <div class="timeline">
        <div class="tl-item"><div class="tl-label">Submitted</div><div class="tl-sub"><?= fmt_datetime($a['submitted_at'], ' — ') ?></div></div>
        <div class="tl-item"><div class="tl-label">Under review</div><div class="tl-sub"><?= $a['status'] === 'Pending' ? 'Shelter is reviewing your application' : 'Reviewed by shelter' ?></div></div>
        <?php if ($a['status'] === 'Rejected'): ?>
          <div class="tl-item rejected"><div class="tl-label">Not approved</div><div class="tl-sub"><?= fmt_date($a['reviewed_at']) ?> — the shelter chose another home for <?= e($a['pet_name']) ?>. Keep browsing — your match is out there!</div></div>
        <?php elseif ($a['status'] === 'Pending'): ?>
          <div class="tl-item pending"><div class="tl-label">Approved</div><div class="tl-sub">Pending shelter decision</div></div>
        <?php else: ?>
          <div class="tl-item"><div class="tl-label">Approved</div><div class="tl-sub"><?= fmt_date($a['reviewed_at']) ?> — handover details will follow</div></div>
          <?php if ($a['handover_id']): ?>
            <div class="tl-item <?= $a['h_status'] === 'Cancelled' ? 'rejected' : '' ?>"><div class="tl-label">Handover <?= $a['h_status'] === 'Cancelled' ? 'Cancelled' : 'Scheduled' ?></div><div class="tl-sub"><?= fmt_datetime($a['scheduled_at'], ' — ') ?><?= $a['h_notes'] !== '' ? ' — ' . e($a['h_notes']) : '' ?></div></div>
          <?php endif; ?>
          <?php if ($a['status'] === 'Completed'): ?>
            <div class="tl-item"><div class="tl-label">Adoption complete</div><div class="tl-sub"><?= fmt_date($a['completed_at']) ?> — welcome home, <?= e($a['pet_name']) ?>!</div></div>
          <?php elseif ($a['status'] === 'Approved'): ?>
            <div class="tl-item pending"><div class="tl-label">Adoption complete</div><div class="tl-sub">After the handover</div></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if ($a['handover_id']): ?><a class="btn-submit" href="#handover-<?= $id ?>">View Handover Details</a><?php endif; ?>
    </div>
  </div>

  <?php if ($a['handover_id']): ?>
  <div class="modal-backdrop" id="handover-<?= $id ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <h2><?= e($a['pet_name']) ?></h2>
      <p class="lead"><?= fmt_datetime($a['scheduled_at']) ?></p>
      <?= info_box('Adopter', e($a['full_name'])) ?>
      <?= info_box('Location', e($a['h_location'])) ?>
      <?= info_box('Notes / Instructions', e($a['h_notes'] ?: 'None')) ?>
      <?= info_box('Status', e($a['h_status'])) ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($a['status'] === 'Completed'): ?>
  <div class="modal-backdrop" id="cert-<?= $id ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <div class="certificate-frame">
        <div class="cert-kicker">Certificate of Adoption &mdash; PDF Preview</div>
        <div class="cert-photo" style="background-image:url('<?= e(pet_photo_url($a['photo_path'])) ?>')"></div>
        <div class="cert-pet-name"><?= e($a['pet_name']) ?></div>
        <div class="cert-line">has found a forever home with</div>
        <div class="cert-adopter"><?= e($a['full_name']) ?></div>
        <div class="cert-issued">Issued by <?= e($a['shelter_name']) ?> · <?= fmt_date($a['completed_at']) ?></div>
      </div>
      <a class="btn-submit" href="certificate.php?id=<?= $id ?>" target="_blank" rel="noopener">Download Certificate</a>
    </div>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>

  
  <div class="modal-backdrop <?= $openModal === 'bookVetBackdrop' ? 'open' : '' ?>" id="bookVetBackdrop">
    <form class="modal modal-wide" method="post" action="adopter.php" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="book">
      <input type="hidden" name="vet_id" id="bookVetId" value="<?= (int) $book['vet_id'] ?>">
      <a class="modal-close" href="#">&#10005;</a>
      <h2>Book a vet appointment</h2>
      <?php if (!$myPets): ?>
        <p class="lead">You'll be able to book vet visits once you've adopted a pet through PawHome.</p>
        <a class="btn-submit" href="browse.php">Browse Pets</a>
      <?php else: ?>
      <div class="vet-grid">
        <div>
          <div class="field-label">Choose a vet</div>
          <input type="text" id="vetSearch" placeholder="Search by name or specialty..." aria-label="Search vets">
          <div class="vet-list" id="vetList" role="listbox" aria-label="Vets">
            <?php foreach ($vets as $v): ?>
              <div class="vet-item <?= (int) $v['user_id'] === (int) $book['vet_id'] ? 'selected' : '' ?>" data-vet-id="<?= $v['user_id'] ?>" role="option" tabindex="0"
                   aria-selected="<?= (int) $v['user_id'] === (int) $book['vet_id'] ? 'true' : 'false' ?>">
                <div class="vet-avatar"><?= e(initials($v['name'])) ?></div>
                <div><div class="vet-name"><?= e($v['name']) ?></div><div class="vet-sub"><?= e($v['specialization']) ?> · <?= e($v['clinic']) ?></div><div class="vet-bio"><?= e($v['bio']) ?></div></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?= $bErr('vet_id') ?>
        </div>
        <div>
          <div class="field">
            <div class="field-label">Pet</div>
            <select name="pet_id" id="bookPetSelect" class="<?= isset($bookErrors['pet_id']) ? 'is-invalid' : '' ?>">
              <?php foreach ($myPets as $p): ?>
                <option value="<?= $p['pet_id'] ?>" <?= (int) $p['pet_id'] === $book['pet_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $bErr('pet_id') ?>
          </div>
          <div class="field">
            <div class="field-label">Available time slot</div>
            <select name="slot" id="bookTimeSelect" data-selected="<?= e($book['slot']) ?>" class="<?= isset($bookErrors['slot']) ? 'is-invalid' : '' ?>">
              <option value="">Loading available times…</option>
            </select>
            <?= $bErr('slot') ?>
          </div>
          <div class="field">
            <input type="text" name="reason" placeholder="Reason for visit" maxlength="255" value="<?= e($book['reason']) ?>" class="<?= isset($bookErrors['reason']) ? 'is-invalid' : '' ?>">
            <?= $bErr('reason') ?>
          </div>
          <div class="field-label">Supporting image or document (optional)</div>
          <div class="dropzone <?= isset($bookErrors['document']) ? 'is-invalid' : '' ?>" data-dropzone tabindex="0" role="button">
            <div class="dz-icon">&#128206;</div>
            <div class="dz-main">Drop a photo or medical document</div>
            <div class="dz-sub">or <a href="#" onclick="return false">browse files</a></div>
            <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
          </div>
          <?= $bErr('document') ?>
          <button type="submit" class="btn-submit">Book Appointment</button>
        </div>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="modal-backdrop" id="bookSuccessBackdrop">
    <div class="modal success-modal">
      <a class="modal-close" href="#">&#10005;</a>
      <p class="success-text">Booked Successfully</p>
    </div>
  </div>

  <?php foreach ($appointments as $ap): ?>
  <div class="modal-backdrop" id="appt-<?= $ap['appointment_id'] ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <div class="appt-head">
        <div class="appt-photo" style="background-image:url('<?= e(pet_photo_url($ap['photo_path'])) ?>')"></div>
        <div>
          <h2><?= e($ap['pet_name']) ?> · <?= e($ap['reason']) ?></h2>
          <div class="appt-sub1"><?= e($ap['breed']) ?> · <?= e($ap['category']) ?> · <?= e(fmt_age($ap['birth_date'])) ?></div>
          <div class="appt-sub2"><?= e($ap['vet_name']) ?> — <?= fmt_date($ap['scheduled_at']) ?> at <?= fmt_time($ap['scheduled_at']) ?></div>
        </div>
      </div>
      <?= info_box('Reason for visit', e($ap['reason'])) ?>
      <?= info_box('Symptoms', 'None reported') ?>
      <?= info_box('Status', $ap['status'] === 'Confirmed' ? 'Confirmed' : 'Waiting for the vet to confirm') ?>
      <div class="field-label">Submitted document</div>
      <div class="doc-placeholder" style="margin-bottom:18px;"><?= $ap['document_name'] ? file_link('appointment', (int) $ap['appointment_id'], $ap['document_name']) : 'No document uploaded' ?></div>
      <div class="btn-row-2">
        <form method="post" action="adopter.php" style="flex:1;display:flex;margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel_appointment">
          <input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>">
          <button type="submit" class="btn-cancel-appt" style="flex:1" data-confirm="Cancel this appointment with <?= e($ap['vet_name']) ?>?">Cancel Appointment</button>
        </form>
        <button type="button" class="btn-close-outline" data-close>Close</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($reminders as $r): $x = $r['record']; if ($x['status'] !== 'Due') continue; $next = $nextApptByPet[$x['pet_id']] ?? null; ?>
  <div class="modal-backdrop" id="rem-<?= $x['vaccination_id'] ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <h2><?= e($x['pet_name']) ?> &middot; <?= e($x['vaccine_name']) ?></h2>
      <p class="lead">Due <?= fmt_date($x['due_date']) ?></p>
      <?= info_box('Status', e(preg_replace('/^Due [^—]+— ?/u', '', $r['sub']) ?: $r['badge'])) ?>
      <?= info_box('Priority', e($x['priority'])) ?>
      <?= info_box('Linked appointment', $next ? e($next['reason']) . ' on ' . fmt_date($next['scheduled_at']) : 'None yet — book one below') ?>
      <?= info_box('Notes', e(trim(($x['notes'] ? $x['notes'] . ' ' : '') . 'Bring ' . $x['pet_name'] . "'s vaccination record to the appointment so the vet can administer this vaccine."))) ?>
      <a class="btn-submit" href="adopter.php?book_pet=<?= $x['pet_id'] ?>&amp;reason=<?= urlencode($x['vaccine_name'] . ' vaccination') ?>#bookVetBackdrop">Book Appointment</a>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($history as $h): $r = $h['record']; ?>
  <div class="modal-backdrop" id="hist-<?= e($h['key']) ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <?php if ($h['type'] === 'Checkup'):
        $rx = db_all('SELECT * FROM prescriptions WHERE checkup_id = ?', [$r['checkup_id']]); ?>
        <h2><?= e($r['pet_name']) ?> &middot; Checkup</h2>
        <p class="lead"><?= fmt_date($r['created_at']) ?> · <?= e($r['vet_name']) ?></p>
        <?= info_box('Reason for visit', e($r['reason'])) ?>
        <?= info_box('Weight · Temperature', fmt_weight($r['weight_kg']) . ' · ' . fmt_temp($r['temperature_c'])) ?>
        <?= info_box('Symptoms', e($r['symptoms'] ?: 'None reported')) ?>
        <?= info_box('Clinical findings', nl2br(e($r['findings']))) ?>
        <?= info_box('Diagnosis', e($r['diagnosis'])) ?>
        <?= info_box('Treatment', e($r['treatment'] ?: '—')) ?>
        <?= info_box('Prescription', $rx ? '<ul class="rx-list">' . implode('', array_map(fn($m) => '<li>' . e($m['medicine_name'] . ' — ' . $m['dosage'] . ', ' . $m['frequency'] . ', ' . $m['duration']) . '</li>', $rx)) . '</ul>' : 'No medication prescribed') ?>
        <?= info_box('Follow-up', $r['follow_up_date'] ? fmt_date($r['follow_up_date']) : 'None') ?>
        <?php if ($r['notes'] !== ''): ?><?= info_box('Vet notes', e($r['notes'])) ?><?php endif; ?>
      <?php elseif ($h['type'] === 'Prescription'): $c = $r['checkup']; ?>
        <h2><?= e($c['pet_name']) ?> &middot; <?= e($r['medicine_name']) ?></h2>
        <p class="lead">Prescribed <?= fmt_date($c['created_at']) ?> by <?= e($c['vet_name']) ?></p>
        <?= info_box('Dosage', e($r['dosage'])) ?>
        <?= info_box('Frequency', e($r['frequency'])) ?>
        <?= info_box('Duration', e($r['duration'])) ?>
        <?= info_box('Instructions', e($r['instructions'] ?: '—')) ?>
        <?= info_box('Prescribed for', e($c['diagnosis'])) ?>
      <?php else: ?>
        <h2><?= e($r['pet_name']) ?> &middot; <?= e($r['vaccine_name']) ?></h2>
        <p class="lead">Given <?= fmt_date($r['given_date']) ?></p>
        <?= info_box('Dose', e($r['dose'] ?: '—')) ?>
        <?= info_box('Batch number', e($r['batch_number'] ?: '—')) ?>
        <?= info_box('Veterinarian', e($r['vet_name'] ?? '—')) ?>
        <?= info_box('Next due date', $r['next_due_date'] ? fmt_date($r['next_due_date']) : 'Not required') ?>
        <?php if ($r['notes'] !== ''): ?><?= info_box('Notes', e($r['notes'])) ?><?php endif; ?>
        <?php if ($r['document_name']): ?><?= info_box('Vaccination certificate', file_link('vaccination', (int) $r['vaccination_id'], $r['document_name'])) ?><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  
  <div class="modal-backdrop <?= $openModal === 'addNoteBackdrop' ? 'open' : '' ?>" id="addNoteBackdrop">
    <form class="modal" method="post" action="adopter.php" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_note">
      <a class="modal-close" href="#">&#10005;</a>
      <h2>Log a health note</h2>
      <?php if (!$myPets): ?>
        <p class="lead">Health notes are for pets you've adopted. Once your adoption is complete you can log notes here.</p>
      <?php else: ?>
      <div class="field">
        <div class="field-label">Pet</div>
        <select name="pet_id" class="<?= isset($noteErrors['pet_id']) ? 'is-invalid' : '' ?>">
          <?php foreach ($myPets as $p): ?><option value="<?= $p['pet_id'] ?>" <?= (int) $p['pet_id'] === $note['pet_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <?= $nErr('pet_id') ?>
      </div>
      <div class="field">
        <input type="date" name="note_date" max="<?= date('Y-m-d') ?>" value="<?= e($note['note_date']) ?>" aria-label="Date" class="<?= isset($noteErrors['note_date']) ? 'is-invalid' : '' ?>">
        <?= $nErr('note_date') ?>
      </div>
      <div class="field">
        <textarea name="body" placeholder="What did you notice?" maxlength="1000" class="<?= isset($noteErrors['body']) ? 'is-invalid' : '' ?>"><?= e($note['body']) ?></textarea>
        <?= $nErr('body') ?>
      </div>
      <div class="field-label">Attach document (optional)</div>
      <div class="dropzone <?= isset($noteErrors['document']) ? 'is-invalid' : '' ?>" data-dropzone tabindex="0" role="button">
        <div class="dz-main">Drop a vet report or photo</div>
        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
      </div>
      <?= $nErr('document') ?>
      <button type="submit" class="btn-submit">Save Note</button>
      <?php endif; ?>
    </form>
  </div>

  <script src="assets/js/ui.js"></script>
  <script src="assets/js/messages.js"></script>
  <script src="assets/js/booking.js"></script>
</body>
</html>
