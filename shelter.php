<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/messages.php';

$me = require_role('shelter');
$uid = $me['user_id'];
$profile = db_one('SELECT * FROM shelter_profiles WHERE user_id = ?', [$uid]);

$reviewErrors = [];
$reviewOld = [];
$openReview = null;

function my_application(int $appId, int $shelterId): ?array {
    return db_one('SELECT a.*, p.name AS pet_name, p.pet_id, p.availability FROM applications a JOIN pets p ON p.pet_id = a.pet_id
                   WHERE a.application_id = ? AND p.shelter_id = ?', [$appId, $shelterId]);
}
function my_handover(int $handoverId, int $shelterId): ?array {
    return db_one('SELECT h.*, a.application_id, a.pet_id, p.name AS pet_name FROM handovers h
                   JOIN applications a ON a.application_id = h.application_id JOIN pets p ON p.pet_id = a.pet_id
                   WHERE h.handover_id = ? AND p.shelter_id = ?', [$handoverId, $shelterId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $db = get_db();

    if ($action === 'approve' || $action === 'reject') {
        $app = my_application((int) ($_POST['application_id'] ?? 0), $uid);
        if (!$app || $app['status'] !== 'Pending') {
            flash('That application has already been reviewed.');
            redirect('shelter.php');
        }
        if ($action === 'reject') {
            db_exec('UPDATE applications SET status = "Rejected", reviewed_at = NOW() WHERE application_id = ?', [$app['application_id']]);
            flash('Application from ' . $app['full_name'] . ' for ' . $app['pet_name'] . ' was declined.');
            redirect('shelter.php');
        }

        $date = parse_date((string) ($_POST['handover_date'] ?? ''));
        $time = (string) ($_POST['handover_time'] ?? '');
        $notes = clean('handover_notes', 500);
        $reviewOld = ['handover_date' => (string) ($_POST['handover_date'] ?? ''), 'handover_time' => $time, 'handover_notes' => $notes];
        $errs = [];
        if (!$date) {
            $errs['handover_date'] = 'Choose a handover date.';
        } elseif ($date < date('Y-m-d')) {
            $errs['handover_date'] = 'The handover date can\'t be in the past.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errs['handover_time'] = 'Choose a handover time.';
        } elseif ($date && strtotime("$date $time") < time()) {
            $errs['handover_time'] = 'That time has already passed.';
        }
        if ($app['availability'] !== 'Available') {
            $errs['_general'] = $app['pet_name'] . ' already has an approved adopter.';
        }
        if ($errs) {
            $reviewErrors[$app['application_id']] = $errs;
            $openReview = (int) $app['application_id'];
        } else {
            mysqli_begin_transaction($db);
            try {
                db_exec('UPDATE applications SET status = "Approved", reviewed_at = NOW() WHERE application_id = ?', [$app['application_id']]);
                db_exec('INSERT INTO handovers (application_id, scheduled_at, location, notes) VALUES (?, ?, ?, ?)',
                    [$app['application_id'], "$date $time:00", ($profile['address'] ?? '') ? $me['name'] . ', ' . $profile['address'] : $me['name'], $notes]);
                db_exec('UPDATE pets SET availability = "Pending" WHERE pet_id = ?', [$app['pet_id']]);
                
                db_exec('UPDATE applications SET status = "Rejected", reviewed_at = NOW() WHERE pet_id = ? AND status = "Pending" AND application_id <> ?',
                    [$app['pet_id'], $app['application_id']]);
                mysqli_commit($db);
            } catch (Throwable $ex) {
                mysqli_rollback($db);
                throw $ex;
            }
            flash('Approved! Handover with ' . $app['full_name'] . ' scheduled for ' . fmt_datetime("$date $time") . '.');
            redirect('shelter.php');
        }
    } elseif ($action === 'complete_handover' || $action === 'cancel_handover') {
        $h = my_handover((int) ($_POST['handover_id'] ?? 0), $uid);
        if (!$h || $h['status'] !== 'Scheduled') {
            flash('That handover was already updated.');
            redirect('shelter.php');
        }
        $complete = $action === 'complete_handover';
        mysqli_begin_transaction($db);
        try {
            db_exec('UPDATE handovers SET status = ?, completed_at = ? WHERE handover_id = ?',
                [$complete ? 'Completed' : 'Cancelled', $complete ? date('Y-m-d H:i:s') : null, $h['handover_id']]);
            db_exec('UPDATE applications SET status = ? WHERE application_id = ?', [$complete ? 'Completed' : 'Cancelled', $h['application_id']]);
            db_exec('UPDATE pets SET availability = ? WHERE pet_id = ?', [$complete ? 'Adopted' : 'Available', $h['pet_id']]);
            mysqli_commit($db);
        } catch (Throwable $ex) {
            mysqli_rollback($db);
            throw $ex;
        }
        flash($complete ? $h['pet_name'] . '\'s adoption is complete — the certificate is now available to the adopter.'
                        : 'Handover cancelled. ' . $h['pet_name'] . ' is available for adoption again.');
        redirect('shelter.php');
    }
}

$pending = db_all('SELECT a.*, p.name AS pet_name FROM applications a JOIN pets p ON p.pet_id = a.pet_id
                   WHERE p.shelter_id = ? AND a.status = "Pending" ORDER BY a.submitted_at', [$uid]);
$pets = db_all('SELECT * FROM pets WHERE shelter_id = ? ORDER BY FIELD(availability, "Available", "Pending", "Adopted"), name', [$uid]);
$handovers = db_all('SELECT h.*, a.full_name, p.name AS pet_name FROM handovers h
                     JOIN applications a ON a.application_id = h.application_id JOIN pets p ON p.pet_id = a.pet_id
                     WHERE p.shelter_id = ? AND h.status = "Scheduled" ORDER BY h.scheduled_at', [$uid]);
$contacts = chat_contacts($me);
$rErr = fn(int $appId, string $f) => isset($reviewErrors[$appId][$f]) ? '<span class="field-error">' . e($reviewErrors[$appId][$f]) . '</span>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>PawHome — Shelter Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); }
  a { color: #C1663C; text-decoration: none; }
  button { font-family: 'Inter', sans-serif; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; gap: 24px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; }
  .nav-links { display: flex; align-items: center; gap: 2px; }
  .nav-links a { padding: 9px 15px; border-radius: 10px; font-size: 13.5px; font-weight: 500; color: #66606C; }
  .nav-links a.active { background: rgba(46,38,46,0.06); color: #2E2A33; font-weight: 600; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; flex-shrink: 0; }
  .btn-logout { padding: 9px 20px; border-radius: 100px; border: none; background: #E8792E; color: #fff; font-weight: 700; font-size: 13.5px; cursor: pointer; box-shadow: 0 6px 14px rgba(232,121,46,0.3); }
  .btn-logout:hover { background: #D2691E; }

  .container { max-width: 1180px; margin: 0 auto; padding: 40px 32px 88px; }
  h1 { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 28px; letter-spacing: -0.01em; margin: 0 0 4px; }
  .subhead { font-size: 14px; color: #66606C; margin: 0 0 32px; }

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
  .row-right { display: flex; align-items: center; gap: 8px; }
  .row-clickable { cursor: pointer; transition: background 0.15s; }
  .row-clickable:hover { background: rgba(255,255,255,0.9); }

  .badge { display: inline-flex; align-items: center; padding: 4px 11px; border-radius: 100px; font-size: 11px; font-weight: 600; letter-spacing: 0.02em; white-space: nowrap; }
  .badge-approved { background: #DCEEE2; color: #2C6B4A; }
  .badge-pending { background: #F3E6C9; color: #8A5A19; }
  .badge-scheduled { background: #DCEEE2; color: #2C6B4A; }

  .btn-outline { padding: 8px 13px; border: 1.5px solid rgba(46,38,46,0.15); border-radius: 10px; background: transparent; color: #2E2A33; font-weight: 600; font-size: 12.5px; cursor: pointer; }
  .btn-outline:hover { background: rgba(46,38,46,0.05); }
  .btn-approve { padding: 8px 15px; border: none; border-radius: 10px; background: #4F9C6E; color: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer; }
  .btn-approve:hover { background: #3F8259; }
  .btn-reject { padding: 8px 15px; border: 1.5px solid rgba(200,60,50,0.35); border-radius: 10px; background: transparent; color: #A8342A; font-weight: 700; font-size: 12.5px; cursor: pointer; }
  .btn-reject:hover { background: rgba(200,60,50,0.06); }
  .btn-small { padding: 8px 14px; border: none; border-radius: 9px; background: #E8792E; color: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-block; }
  .btn-small:hover { background: #D2691E; }

  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 22px; }

  
  .pets-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .pet-tile { display: flex; align-items: center; gap: 10px; padding: 9px; border-radius: 13px; background: rgba(255,255,255,0.6); cursor: pointer; color: inherit; }
  .pet-tile:hover { background: rgba(255,255,255,0.95); }
  .pet-thumb { width: 42px; height: 42px; border-radius: 10px; background-size: cover; background-position: center; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
  .pet-tile-name { font-weight: 600; font-size: 13.5px; }
  .pet-tile-status { font-size: 11.5px; color: #8A8390; margin-top: 1px; }

  
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

  @media (max-width: 800px) { .two-col { grid-template-columns: 1fr; } .pets-grid { grid-template-columns: 1fr; } .messages-grid { grid-template-columns: 1fr; height: auto; } .msg-sidebar { border-right: none; border-bottom: 1px solid rgba(46,38,46,0.08); max-height: 240px; } .chat-log { max-height: 300px; } }

  
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(46,38,46,0.35); align-items: center; justify-content: center; padding: 24px; z-index: 100; }
  .modal-backdrop.open, .modal-backdrop:target { display: flex; }
  .modal { width: 100%; max-width: 520px; background: rgba(255,255,255,0.97); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.8); border-radius: 22px; padding: 32px; box-shadow: 0 30px 70px rgba(46,30,35,0.25); position: relative; max-height: 88vh; overflow-y: auto; }
  .modal.modal-wide { max-width: 700px; }
  .modal-close { position: absolute; top: 18px; right: 18px; width: 32px; height: 32px; border-radius: 10px; border: none; background: rgba(46,38,46,0.08); color: #2E2A33; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; }
  .modal-close:hover { background: rgba(46,38,46,0.14); }
  .modal h2 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 21px; margin: 0 0 4px; padding-right: 36px; }
  .modal .lead { font-size: 13.5px; color: #66606C; margin: 0 0 22px; }

  .modal label.field-label, .modal .field-label { display: block; font-size: 11px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  .modal input[type=text], .modal input[type=date], .modal input[type=time], .modal textarea {
    width: 100%; padding: 13px 15px; border-radius: 12px; border: 1px solid rgba(46,38,46,0.15);
    background: rgba(255,255,255,0.8); font-size: 14px; outline: none; font-family: 'Inter', sans-serif;
  }
  .modal input:focus, .modal textarea:focus { border-color: #C1663C; }
  .modal textarea { min-height: 80px; resize: vertical; }
  .modal .field { margin-bottom: 16px; }
  .row-2-inline { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }

  .info-box { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 14px 16px; margin-bottom: 12px; }
  .info-box .field-label { margin-bottom: 6px; }
  .info-box .info-value { font-size: 14.5px; font-weight: 600; }
  .info-box .info-value.quote { font-style: italic; font-weight: 500; }
  .doc-placeholder { background: rgba(46,38,46,0.05); border-radius: 12px; padding: 20px; text-align: center; font-size: 13px; color: #8A8390; }

  .review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  @media (max-width: 640px) { .review-grid { grid-template-columns: 1fr; } }
  .btn-row-2 { display: flex; gap: 12px; margin-top: 4px; }
  .btn-row-2 button,
  .btn-row-2 > .btn-approve,
  .btn-row-2 > .btn-outline { display: inline-flex; flex: 1; align-items: center; justify-content: center; padding: 13px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; }
</style>
<style>
  .btn-row-2 > form { flex: 1; display: flex; margin: 0; }
  .row-right form { margin: 0; }
  .row-link { display: block; color: inherit; }
  .row-clickable .row-link:hover { color: inherit; }
  .doc-placeholder a { font-weight: 600; }
  .modal .form-alert { margin-bottom: 14px; }
  .row-2-inline .field-error { grid-column: span 1; }
  .pet-thumb.no-photo { background: #C1663C; }
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
          <a href="shelter.php" class="active">Shelter Dashboard</a>
        </div>
        <?= logout_button('btn-logout') ?>
        <div class="avatar" title="<?= e($me['name']) ?>"><?= e(initials($me['name'])) ?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <h1><?= e($me['name']) ?></h1>
    <p class="subhead">Manage your listed pets and review adoption applications.</p>

    <div class="card">
      <h3>Pending applications</h3>
      <div class="row-list" id="applicationsList">
        <?php foreach ($pending as $a): $id = $a['application_id']; ?>
          <div class="row row-flex">
            <div><div class="row-title"><?= e($a['pet_name']) ?> → <?= e($a['full_name']) ?></div><div class="row-sub">Submitted <?= fmt_date($a['submitted_at']) ?></div></div>
            <div class="row-right">
              <a class="btn-outline" href="#review-<?= $id ?>">View</a>
              <a class="btn-approve" href="#review-<?= $id ?>" title="Approve and schedule the handover">Approve</a>
              <form class="inline-form" method="post" action="shelter.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="application_id" value="<?= $id ?>">
                <button type="submit" class="btn-reject" data-confirm="Decline <?= e($a['full_name']) ?>'s application for <?= e($a['pet_name']) ?>?">Reject</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$pending): ?><p class="empty-note">No applications waiting for review.</p><?php endif; ?>
      </div>
    </div>

    <div class="two-col">
      <div class="card">
        <div class="card-head">
          <h3>Your pets</h3>
          <a class="btn-small" href="add-pet.php">+ Add Pet</a>
        </div>
        <div class="pets-grid" id="petsGrid">
          <?php foreach ($pets as $p): ?>
            <a class="pet-tile" href="shelter-pet.php?id=<?= $p['pet_id'] ?>"><div class="pet-thumb" style="background-image:url('<?= e(pet_photo_url($p['photo_path'])) ?>')"></div><div><div class="pet-tile-name"><?= e($p['name']) ?></div><div class="pet-tile-status"><?= e($p['availability']) ?></div></div></a>
          <?php endforeach; ?>
        </div>
        <?php if (!$pets): ?><p class="empty-note">You haven't listed any pets yet. Use "+ Add Pet" to create your first listing.</p><?php endif; ?>
      </div>
      <div class="card">
        <h3>Upcoming handovers</h3>
        <div class="row-list" id="handoversList">
          <?php foreach ($handovers as $h): ?>
            <div class="row row-clickable">
              <a class="row-link" href="#handover-<?= $h['handover_id'] ?>"><div class="row-flex"><div><div class="row-title"><?= e($h['pet_name']) ?></div><div class="row-sub"><?= fmt_datetime($h['scheduled_at']) ?> · <?= e($h['full_name']) ?></div><?php if ($h['notes'] !== ''): ?><div class="row-sub"><?= e($h['notes']) ?></div><?php endif; ?></div><span class="badge badge-scheduled">Scheduled</span></div></a>
              <div class="btn-row-2" style="margin-top:12px;">
                <form method="post" action="shelter.php">
                  <?= csrf_field() ?><input type="hidden" name="action" value="complete_handover"><input type="hidden" name="handover_id" value="<?= $h['handover_id'] ?>">
                  <button type="submit" class="btn-approve" data-confirm="Mark <?= e($h['pet_name']) ?>'s handover to <?= e($h['full_name']) ?> as completed? This finalises the adoption.">Mark Completed</button>
                </form>
                <form method="post" action="shelter.php">
                  <?= csrf_field() ?><input type="hidden" name="action" value="cancel_handover"><input type="hidden" name="handover_id" value="<?= $h['handover_id'] ?>">
                  <button type="submit" class="btn-outline" data-confirm="Cancel this handover? <?= e($h['pet_name']) ?> will be listed as available again.">Cancel</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$handovers): ?><p class="empty-note">No handovers scheduled. Approving an application schedules one.</p><?php endif; ?>
        </div>
      </div>
    </div>

    <?= render_messages_panel($contacts, null, 'Search adopters...') ?>
  </div>

  <?= dev_footer() ?>

  <?php foreach ($pending as $a): $id = (int) $a['application_id']; $errs = $reviewErrors[$id] ?? []; $o = $openReview === $id ? $reviewOld : []; ?>
  <div class="modal-backdrop <?= $openReview === $id ? 'open' : '' ?>" id="review-<?= $id ?>">
    <form class="modal modal-wide" method="post" action="shelter.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="application_id" value="<?= $id ?>">
      <a class="modal-close" href="#">&#10005;</a>
      <h2><?= e($a['pet_name']) ?> → <?= e($a['full_name']) ?></h2>
      <p class="lead">Submitted <?= fmt_date($a['submitted_at']) ?> · <?= e($a['email']) ?> · <?= e($a['phone']) ?></p>
      <?php if (isset($errs['_general'])): ?><div class="form-alert"><?= e($errs['_general']) ?></div><?php endif; ?>
      <div class="review-grid">
        <div>
          <div class="info-box"><div class="field-label">Household</div><div class="info-value"><?= e($a['household']) ?></div></div>
          <div class="info-box"><div class="field-label">Experience</div><div class="info-value"><?= e($a['experience']) ?></div></div>
          <div class="info-box"><div class="field-label">Note from adopter</div><div class="info-value quote">“<?= e($a['message']) ?>”</div></div>
          <div class="field-label">Submitted documents</div>
          <div class="doc-placeholder"><?= $a['document_name'] ? '<a href="file.php?kind=application&amp;id=' . $id . '" target="_blank" rel="noopener">' . e($a['document_name']) . '</a>' : 'No document uploaded' ?></div>
        </div>
        <div>
          <div class="field-label">Handover</div>
          <div class="row-2-inline">
            <div><input type="date" name="handover_date" min="<?= date('Y-m-d') ?>" value="<?= e($o['handover_date'] ?? '') ?>" aria-label="Handover date" class="<?= isset($errs['handover_date']) ? 'is-invalid' : '' ?>"><?= $rErr($id, 'handover_date') ?></div>
            <div><input type="time" name="handover_time" value="<?= e($o['handover_time'] ?? '15:00') ?>" aria-label="Handover time" class="<?= isset($errs['handover_time']) ? 'is-invalid' : '' ?>"><?= $rErr($id, 'handover_time') ?></div>
          </div>
          <div class="field">
            <textarea name="handover_notes" maxlength="500" placeholder="Handover details (location, instructions...)"><?= e($o['handover_notes'] ?? '') ?></textarea>
          </div>
          <div class="btn-row-2">
            <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
            <button type="submit" name="action" value="reject" formnovalidate class="btn-reject" style="background:transparent;" data-confirm="Decline <?= e($a['full_name']) ?>'s application?">Reject</button>
          </div>
        </div>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <?php foreach ($handovers as $h): ?>
  <div class="modal-backdrop" id="handover-<?= $h['handover_id'] ?>">
    <div class="modal">
      <a class="modal-close" href="#">&#10005;</a>
      <h2><?= e($h['pet_name']) ?></h2>
      <p class="lead"><?= fmt_datetime($h['scheduled_at']) ?></p>
      <div class="info-box"><div class="field-label">Adopter</div><div class="info-value"><?= e($h['full_name']) ?></div></div>
      <div class="info-box"><div class="field-label">Location</div><div class="info-value"><?= e($h['location']) ?></div></div>
      <div class="info-box"><div class="field-label">Notes / Instructions</div><div class="info-value"><?= e($h['notes'] ?: 'None') ?></div></div>
      <div class="info-box"><div class="field-label">Status</div><div class="info-value"><?= e($h['status']) ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>

  <script src="assets/js/ui.js"></script>
  <script src="assets/js/messages.js"></script>
</body>
</html>
