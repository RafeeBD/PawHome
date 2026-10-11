<?php
require_once __DIR__ . '/includes/auth.php';

$me = require_role('shelter');
$pet = db_one('SELECT p.*, c.name AS category FROM pets p JOIN categories c ON c.category_id = p.category_id
               WHERE p.pet_id = ? AND p.shelter_id = ?', [(int) ($_GET['id'] ?? 0), $me['user_id']]);
if (!$pet) {
    flash('That pet could not be found.');
    redirect('shelter.php');
}
$adopter = db_value('SELECT a.full_name FROM applications a WHERE a.pet_id = ? AND a.status IN ("Approved","Completed") ORDER BY a.reviewed_at DESC LIMIT 1', [$pet['pet_id']]);
$box = fn(string $label, string $value, bool $full = false, bool $desc = false) =>
    '<div class="info-box' . ($full ? ' full' : '') . '"><div class="field-label">' . e($label) . '</div><div class="info-value' . ($desc ? ' desc' : '') . '">' . $value . '</div></div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — <?= e($pet['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); }
  a { color: #C1663C; text-decoration: none; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
  .btn-logout { padding: 9px 20px; border-radius: 100px; border: none; background: #E8792E; color: #fff; font-weight: 700; font-size: 13.5px; cursor: pointer; box-shadow: 0 6px 14px rgba(232,121,46,0.3); }
  .btn-logout:hover { background: #D2691E; }

  .container { max-width: 760px; margin: 0 auto; padding: 32px 32px 88px; }
  .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }

  .card { background: rgba(255,255,255,0.6); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,0.75); border-radius: 22px; padding: 30px; box-shadow: 0 20px 50px rgba(46,30,35,0.08); }

  .detail-head { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
  .detail-photo { width: 64px; height: 64px; border-radius: 16px; background-size: cover; background-position: center; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 22px; }
  .detail-head-info { flex: 1; }
  .detail-head h1 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 22px; margin: 0 0 4px; }
  .detail-sub { font-size: 13.5px; color: #66606C; }
  .badge { padding: 5px 13px; border-radius: 100px; font-size: 12px; font-weight: 600; background: #DCEEE2; color: #2C6B4A; white-space: nowrap; }

  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
  .info-box { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 14px 16px; }
  .info-box.full { grid-column: 1 / -1; }
  .field-label { font-size: 10.5px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #8A8390; margin-bottom: 6px; }
  .info-value { font-weight: 700; font-size: 15px; }
  .info-value.desc { font-weight: 500; line-height: 1.6; }

  .doc-placeholder { background: rgba(46,38,46,0.05); border-radius: 12px; padding: 20px; text-align: center; font-size: 13px; color: #8A8390; margin-bottom: 22px; }

  .btn-submit { width: 100%; padding: 15px; border: none; border-radius: 14px; background: #E8792E; color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 10px 24px rgba(232,121,46,0.3); }
  .btn-submit:hover { background: #D2691E; }
</style>
<style>
  .btn-submit { display: block; text-align: center; }
  .doc-placeholder a { font-weight: 600; }
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
        <?= logout_button('btn-logout') ?>
        <div class="avatar" title="<?= e($me['name']) ?>"><?= e(initials($me['name'])) ?></div>
      </div>
    </div>
  </div>

  <div class="container">
    <a class="back-link" href="shelter.php">&larr; Back to Shelter Dashboard</a>

    <div class="card">
      <div class="detail-head">
        <div class="detail-photo" style="background-image:url('<?= e(pet_photo_url($pet['photo_path'])) ?>')"></div>
        <div class="detail-head-info">
          <h1><?= e($pet['name']) ?></h1>
          <div class="detail-sub"><?= e($pet['breed']) ?> · <?= e($pet['category']) ?> · <?= e($pet['gender']) ?></div>
        </div>
        <span class="badge" style="<?= availability_style($pet['availability']) ?>"><?= e($pet['availability']) ?></span>
      </div>

      <div class="info-grid">
        <?= $box('Age', e(fmt_age($pet['birth_date']))) ?>
        <?= $box('Date of birth', $pet['dob_known'] ? fmt_date($pet['birth_date']) : 'Not recorded (age estimated)') ?>
        <?= $box('Size', e($pet['size'])) ?>
        <?= $box('Weight', $pet['weight_kg'] === null ? '—' : e(rtrim(rtrim($pet['weight_kg'], '0'), '.')) . ' kg') ?>
        <?= $box('Colour', e($pet['colour'] ?: '—')) ?>
        <?= $box('Health', e($pet['health_condition'])) ?>
        <?= $box('Vaccination', $pet['vaccinated'] ? 'Up to date' : 'Not vaccinated') ?>
        <?= $box('Spayed/Neutered', $pet['spayed_neutered'] ? 'Yes' : 'No') ?>
        <?= $box('Availability', e($pet['availability']) . ($adopter ? ' — ' . e($adopter) : ''), true) ?>
        <?= $box('Shelter location', e($pet['location']), true) ?>
        <?= $box('Special needs', e($pet['special_needs'] ?: 'None'), true) ?>
        <?= $box('Behaviour / Temperament', e($pet['behaviour']), true) ?>
        <?= $box('Description', nl2br(e($pet['description'])), true, true) ?>
      </div>

      <div class="field-label" style="margin-bottom:8px;">Medical document</div>
      <div class="doc-placeholder"><?= $pet['medical_doc_name']
          ? '<a href="file.php?kind=petdoc&amp;id=' . (int) $pet['pet_id'] . '" target="_blank" rel="noopener">' . e($pet['medical_doc_name']) . '</a>'
          : 'No document uploaded' ?></div>

      <a class="btn-submit" href="add-pet.php?id=<?= (int) $pet['pet_id'] ?>">Edit Pet Details</a>
    </div>
  </div>

  <?= dev_footer() ?>

</body>
</html>
