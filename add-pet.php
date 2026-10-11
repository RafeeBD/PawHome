<?php
require_once __DIR__ . '/includes/auth.php';

$me = require_role('shelter');
$uid = $me['user_id'];
$profile = db_one('SELECT address FROM shelter_profiles WHERE user_id = ?', [$uid]);
$categories = db_all('SELECT category_id, name FROM categories ORDER BY name');
$catIds = array_map('intval', array_column($categories, 'category_id'));

$editId = (int) ($_GET['id'] ?? 0);
$pet = $editId ? db_one('SELECT * FROM pets WHERE pet_id = ? AND shelter_id = ?', [$editId, $uid]) : null;
if ($editId && !$pet) {
    flash('That pet could not be found.');
    redirect('shelter.php');
}

$workflowLocked = $pet && db_value('SELECT COUNT(*) FROM applications WHERE pet_id = ? AND status IN ("Approved","Completed")', [$editId]) > 0;

$errors = [];
$old = $pet ? [
    'name' => $pet['name'], 'category_id' => (int) $pet['category_id'], 'breed' => $pet['breed'], 'gender' => $pet['gender'],
    'age_years' => $pet['dob_known'] ? '' : (string) round(age_months($pet['birth_date']) / 12, 1),
    'birth_date' => $pet['dob_known'] ? $pet['birth_date'] : '', 'size' => $pet['size'],
    'weight_kg' => $pet['weight_kg'] === null ? '' : rtrim(rtrim($pet['weight_kg'], '0'), '.'),
    'colour' => $pet['colour'], 'health_condition' => $pet['health_condition'], 'vaccinated' => (string) $pet['vaccinated'],
    'spayed_neutered' => (string) $pet['spayed_neutered'], 'special_needs' => $pet['special_needs'], 'behaviour' => $pet['behaviour'],
    'availability' => $pet['availability'], 'location' => $pet['location'], 'description' => $pet['description'],
] : [
    'name' => '', 'category_id' => $catIds[0] ?? 0, 'breed' => '', 'gender' => 'Male', 'age_years' => '', 'birth_date' => '', 'size' => 'Small',
    'weight_kg' => '', 'colour' => '', 'health_condition' => '', 'vaccinated' => '1', 'spayed_neutered' => '1', 'special_needs' => '',
    'behaviour' => '', 'availability' => 'Available', 'location' => trim($me['name'] . ', ' . ($profile['address'] ?? ''), ', '), 'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $errors['_general'] = 'The files you attached are too large. Photos and documents can be up to 5 MB each.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (['name' => 60, 'breed' => 80, 'colour' => 60, 'health_condition' => 120, 'special_needs' => 255, 'behaviour' => 255, 'location' => 255, 'description' => 2000] as $k => $max) {
        $old[$k] = clean($k, $max);
    }
    $old['category_id'] = (int) ($_POST['category_id'] ?? 0);
    $old['gender'] = $_POST['gender'] ?? '';
    $old['size'] = $_POST['size'] ?? '';
    $old['age_years'] = trim((string) ($_POST['age_years'] ?? ''));
    $old['birth_date'] = trim((string) ($_POST['birth_date'] ?? ''));
    $old['weight_kg'] = trim((string) ($_POST['weight_kg'] ?? ''));
    $old['vaccinated'] = ($_POST['vaccinated'] ?? '') === '1' ? '1' : '0';
    $old['spayed_neutered'] = ($_POST['spayed_neutered'] ?? '') === '1' ? '1' : '0';
    if (!$workflowLocked) {
        $old['availability'] = $_POST['availability'] ?? '';
    }

    if (!preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.'\-]{0,59}$/u", $old['name'])) {
        $errors['name'] = 'Name is required (letters, numbers, spaces, dots, apostrophes or hyphens).';
    }
    if (!in_array($old['category_id'], $catIds, true)) {
        $errors['category_id'] = 'Choose a pet type.';
    }
    if (mb_strlen($old['breed']) < 2) {
        $errors['breed'] = 'Breed is required (use "Mixed" if unknown).';
    }
    if (!in_array($old['gender'], ['Male', 'Female'], true)) {
        $errors['gender'] = 'Choose a gender.';
    }

    
    $birthDate = null;
    $dobKnown = 1;
    if ($old['birth_date'] !== '') {
        $birthDate = parse_date($old['birth_date']);
        if (!$birthDate) {
            $errors['birth_date'] = 'Enter a valid date of birth.';
        } elseif ($birthDate > date('Y-m-d')) {
            $errors['birth_date'] = 'Date of birth can\'t be in the future.';
        } elseif ($birthDate < date('Y-m-d', strtotime('-40 years'))) {
            $errors['birth_date'] = 'Date of birth must be within the last 40 years.';
        }
    } elseif ($old['age_years'] !== '') {
        if (!is_numeric($old['age_years']) || (float) $old['age_years'] < 0 || (float) $old['age_years'] > 40) {
            $errors['age_years'] = 'Age must be a number of years between 0 and 40 (e.g. 0.5 for six months).';
        } else {
            $months = max(1, (int) round((float) $old['age_years'] * 12));
            $birthDate = date('Y-m-d', strtotime("-$months months"));
            $dobKnown = 0;
        }
    } else {
        $errors['age_years'] = 'Enter the pet\'s age in years or its date of birth.';
    }

    if (!in_array($old['size'], ['Small', 'Medium', 'Large'], true)) {
        $errors['size'] = 'Choose a size.';
    }
    $weight = null;
    if ($old['weight_kg'] !== '') {
        if (!is_numeric($old['weight_kg']) || (float) $old['weight_kg'] <= 0 || (float) $old['weight_kg'] > 200) {
            $errors['weight_kg'] = 'Weight must be between 0.01 and 200 kg.';
        } else {
            $weight = round((float) $old['weight_kg'], 2);
        }
    }
    if (!$workflowLocked && !in_array($old['availability'], ['Available', 'Pending', 'Adopted'], true)) {
        $errors['availability'] = 'Choose availability.';
    }
    if (mb_strlen($old['behaviour']) < 3) {
        $errors['behaviour'] = 'Describe the pet\'s behaviour or temperament.';
    }
    if (mb_strlen($old['location']) < 3) {
        $errors['location'] = 'Shelter location is required.';
    }
    if (mb_strlen($old['description']) < 20) {
        $errors['description'] = 'Write a description of at least 20 characters to help adopters get to know the pet.';
    }

    $photo = uploaded_files('photo');
    if (!$photo && !$pet) {
        $errors['photo'] = 'Add a photo of the pet.';
    } elseif ($photo && ($problem = upload_problem($photo[0], IMAGE_MIMES))) {
        $errors['photo'] = $problem;
    }
    $doc = uploaded_files('medical_doc');
    if ($doc && ($problem = upload_problem($doc[0], DOC_MIMES))) {
        $errors['medical_doc'] = $problem;
    }

    if (!$errors) {
        $photoPath = $photo ? store_upload($photo[0], IMAGE_MIMES, 'pets') : $pet['photo_path'];
        $docPath = $doc ? store_upload($doc[0], DOC_MIMES, 'private/pets') : ($pet['medical_doc_path'] ?? null);
        $docName = $doc ? mb_substr(basename($doc[0]['name']), 0, 255) : ($pet['medical_doc_name'] ?? null);
        $fields = [
            $old['category_id'], $old['name'], $old['breed'], $old['gender'], $birthDate, $dobKnown, $old['size'], $weight,
            $old['colour'], $old['health_condition'] ?: 'Healthy', (int) $old['vaccinated'], (int) $old['spayed_neutered'],
            $old['special_needs'] ?: 'None', $old['behaviour'], $old['availability'], $old['location'], $old['description'],
            $photoPath, $docPath, $docName,
        ];
        $cols = 'category_id = ?, name = ?, breed = ?, gender = ?, birth_date = ?, dob_known = ?, size = ?, weight_kg = ?, colour = ?,
                 health_condition = ?, vaccinated = ?, spayed_neutered = ?, special_needs = ?, behaviour = ?, availability = ?,
                 location = ?, description = ?, photo_path = ?, medical_doc_path = ?, medical_doc_name = ?';
        if ($pet) {
            db_exec("UPDATE pets SET $cols WHERE pet_id = ? AND shelter_id = ?", array_merge($fields, [$editId, $uid]));
            
            if ($photo) delete_upload($pet['photo_path']);
            if ($doc) delete_upload($pet['medical_doc_path']);
            flash($old['name'] . '\'s details were updated.');
            redirect('shelter-pet.php?id=' . $editId);
        }
        db_exec("INSERT INTO pets SET shelter_id = ?, $cols", array_merge([$uid], $fields));
        redirect('add-pet.php#successBackdrop');
    }
}

$err = fn(string $f) => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$inv = fn(string $f) => isset($errors[$f]) ? 'is-invalid' : '';
$sel = fn(string $f, string $v) => (string) $old[$f] === $v ? 'selected' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — <?= $pet ? 'Edit ' . e($pet['name']) : 'Add a Pet' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); }
  a { color: #C1663C; text-decoration: none; }
  button { font-family: 'Inter', sans-serif; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
  .btn-logout { padding: 9px 20px; border-radius: 100px; border: none; background: #E8792E; color: #fff; font-weight: 700; font-size: 13.5px; cursor: pointer; box-shadow: 0 6px 14px rgba(232,121,46,0.3); }
  .btn-logout:hover { background: #D2691E; }

  .container { max-width: 700px; margin: 0 auto; padding: 32px 32px 88px; }
  .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }

  .card { background: rgba(255,255,255,0.6); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,0.75); border-radius: 22px; padding: 32px; box-shadow: 0 20px 50px rgba(46,30,35,0.08); }
  h2 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 21px; margin: 0 0 20px; }

  .drop-zone {
    border: 2px dashed rgba(46,38,46,0.25); border-radius: 16px; background: rgba(46,38,46,0.04);
    height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px; color: #66606C; font-size: 14px; font-weight: 500; margin-bottom: 18px; cursor: pointer;
  }
  .drop-zone:hover { background: rgba(46,38,46,0.06); }
  .drop-zone svg { opacity: 0.6; }

  label.field-label { display: block; font-size: 11.5px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  input[type=text], input[type=number], input[type=date], textarea, select {
    width: 100%; padding: 13px 15px; border-radius: 12px; border: 1px solid rgba(46,38,46,0.15);
    background: rgba(255,255,255,0.8); font-size: 14.5px; outline: none; font-family: 'Inter', sans-serif;
    appearance: none;
  }
  select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9'%3E%3Cpath d='M1 1l6 6 6-6' stroke='%2366606C' stroke-width='1.6' fill='none' fill-rule='evenodd'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 15px center; padding-right: 36px; }
  textarea { min-height: 90px; resize: vertical; }
  input:focus, select:focus, textarea:focus { border-color: #C1663C; }

  .field { margin-bottom: 14px; }
  .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
  .row-2 .field { margin-bottom: 0; }

  .dropzone-doc { border: 1.5px dashed rgba(46,38,46,0.25); border-radius: 14px; background: rgba(46,38,46,0.03); padding: 22px 16px; text-align: center; margin-bottom: 22px; cursor: pointer; }
  .dropzone-doc:hover { background: rgba(46,38,46,0.05); }
  .dropzone-doc .dz-main { font-size: 13.5px; font-weight: 600; color: #2E2A33; }
  .dropzone-doc .dz-sub { font-size: 12.5px; color: #66606C; margin-top: 4px; }
  .dropzone-doc .dz-sub a { font-weight: 600; text-decoration: underline; }

  .btn-submit {
    width: 100%; padding: 16px; border: none; border-radius: 14px; background: #E8792E; color: #fff;
    font-weight: 700; font-size: 15.5px; cursor: pointer; box-shadow: 0 10px 24px rgba(232,121,46,0.3); margin-top: 6px;
  }
  .btn-submit:link, .btn-submit:visited, .btn-submit:hover, .btn-submit:active { color: #fff; }
  .btn-submit:hover { background: #D2691E; }

  
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(46,38,46,0.35); align-items: center; justify-content: center; padding: 24px; z-index: 100; }
  .modal-backdrop.open, .modal-backdrop:target { display: flex; }
  .success-modal { width: 100%; max-width: 420px; background: #C1663C; padding: 44px 32px; text-align: center; border-radius: 22px; box-shadow: 0 30px 70px rgba(46,30,35,0.25); position: relative; }
  .modal-close { position: absolute; top: 18px; right: 18px; width: 32px; height: 32px; border-radius: 10px; border: none; background: rgba(255,255,255,0.18); color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
  .modal-close:hover { background: rgba(255,255,255,0.3); }
  .success-text { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 18px; letter-spacing: 0.03em; text-transform: uppercase; color: #fff; margin: 0; }
</style>
<style>
  .drop-zone.has-photo > *:not(input) { display: none; }
  .drop-zone.has-photo { border-style: solid; }
  .drop-zone:focus-visible, .dropzone-doc:focus-visible { outline: 3px solid rgba(193,102,60,0.3); outline-offset: 2px; }
  .drop-zone + .field-error { margin: -10px 0 16px; }
  .dropzone-doc + .field-error { margin: -14px 0 18px; }
  .field-note { font-size: 12px; color: #8A8390; margin-top: 6px; }
  select:disabled { opacity: 0.7; }
  .current-doc { font-size: 12.5px; color: #66606C; margin: -12px 0 18px; }
  .current-doc a { font-weight: 600; }
</style>
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

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
    <a class="back-link" href="<?= $pet ? 'shelter-pet.php?id=' . $editId : 'shelter.php' ?>">&larr; <?= $pet ? 'Back to ' . e($pet['name']) : 'Back to Shelter Dashboard' ?></a>

    <form class="card" method="post" action="add-pet.php<?= $pet ? '?id=' . $editId : '' ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <h2><?= $pet ? 'Edit ' . e($pet['name']) : 'Add a New Pet' ?></h2>
      <?php if (isset($errors['_general'])): ?><div class="form-alert"><?= e($errors['_general']) ?></div>
      <?php elseif ($errors): ?><div class="form-alert">Please fix the highlighted fields below.</div><?php endif; ?>

      <div class="drop-zone <?= $pet ? 'has-photo' : '' ?> <?= $inv('photo') ?>" data-dropzone="photo" tabindex="0" role="button" aria-label="Pet photo"
           <?= $pet ? 'style="background-image:url(\'' . e(pet_photo_url($pet['photo_path'])) . '\');background-size:cover;background-position:center;" title="Click to replace the photo"' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
        <span data-dz-label>Drop a photo</span>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
      </div>
      <?= $err('photo') ?>

      <div class="row-2">
        <div class="field"><input type="text" name="name" placeholder="Name" maxlength="60" value="<?= e($old['name']) ?>" class="<?= $inv('name') ?>" aria-label="Name"><?= $err('name') ?></div>
        <div class="field">
          <select name="category_id" class="<?= $inv('category_id') ?>" aria-label="Type">
            <?php foreach ($categories as $c): ?><option value="<?= $c['category_id'] ?>" <?= (int) $old['category_id'] === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
          <?= $err('category_id') ?>
        </div>
      </div>
      <div class="row-2">
        <div class="field"><input type="text" name="breed" placeholder="Breed" maxlength="80" value="<?= e($old['breed']) ?>" class="<?= $inv('breed') ?>" aria-label="Breed"><?= $err('breed') ?></div>
        <div class="field">
          <select name="gender" class="<?= $inv('gender') ?>" aria-label="Gender">
            <option <?= $sel('gender', 'Male') ?>>Male</option>
            <option <?= $sel('gender', 'Female') ?>>Female</option>
          </select>
          <?= $err('gender') ?>
        </div>
      </div>
      <div class="row-2">
        <div class="field"><input type="number" name="age_years" placeholder="Age (years)" min="0" max="40" step="0.1" value="<?= e($old['age_years']) ?>" class="<?= $inv('age_years') ?>" aria-label="Age in years"><?= $err('age_years') ?></div>
        <div class="field"><input type="date" name="birth_date" max="<?= date('Y-m-d') ?>" value="<?= e($old['birth_date']) ?>" class="<?= $inv('birth_date') ?>" aria-label="Date of birth" title="Date of birth (optional if you enter the age)"><?= $err('birth_date') ?></div>
      </div>
      <div class="row-2">
        <div class="field">
          <select name="size" class="<?= $inv('size') ?>" aria-label="Size">
            <option <?= $sel('size', 'Small') ?>>Small</option>
            <option <?= $sel('size', 'Medium') ?>>Medium</option>
            <option <?= $sel('size', 'Large') ?>>Large</option>
          </select>
          <?= $err('size') ?>
        </div>
        <div class="field"><input type="number" name="weight_kg" placeholder="Weight (kg)" min="0" max="200" step="0.01" value="<?= e($old['weight_kg']) ?>" class="<?= $inv('weight_kg') ?>" aria-label="Weight in kg"><?= $err('weight_kg') ?></div>
      </div>
      <div class="row-2">
        <div class="field"><input type="text" name="colour" placeholder="Colour" maxlength="60" value="<?= e($old['colour']) ?>" aria-label="Colour"></div>
        <div class="field"><input type="text" name="health_condition" placeholder="Health condition" maxlength="120" value="<?= e($old['health_condition']) ?>" aria-label="Health condition"></div>
      </div>
      <div class="row-2">
        <div class="field">
          <select name="vaccinated" aria-label="Vaccinated">
            <option value="1" <?= $sel('vaccinated', '1') ?>>Vaccinated</option>
            <option value="0" <?= $sel('vaccinated', '0') ?>>Not vaccinated</option>
          </select>
        </div>
        <div class="field">
          <select name="spayed_neutered" aria-label="Spayed or neutered">
            <option value="1" <?= $sel('spayed_neutered', '1') ?>>Spayed/Neutered</option>
            <option value="0" <?= $sel('spayed_neutered', '0') ?>>Not spayed/neutered</option>
          </select>
        </div>
      </div>
      <div class="field"><input type="text" name="special_needs" placeholder="Special needs (if any)" maxlength="255" value="<?= e($old['special_needs'] === 'None' ? '' : $old['special_needs']) ?>" aria-label="Special needs"></div>
      <div class="field"><input type="text" name="behaviour" placeholder="Behaviour / temperament" maxlength="255" value="<?= e($old['behaviour']) ?>" class="<?= $inv('behaviour') ?>" aria-label="Behaviour"><?= $err('behaviour') ?></div>
      <div class="row-2">
        <div class="field">
          <select name="availability" class="<?= $inv('availability') ?>" aria-label="Availability" <?= $workflowLocked ? 'disabled title="Set automatically by the adoption process"' : '' ?>>
            <option <?= $sel('availability', 'Available') ?>>Available</option>
            <option <?= $sel('availability', 'Pending') ?>>Pending</option>
            <option <?= $sel('availability', 'Adopted') ?>>Adopted</option>
          </select>
          <?php if ($workflowLocked): ?><div class="field-note">Managed by the adoption process.</div><?php endif; ?>
          <?= $err('availability') ?>
        </div>
        <div class="field"><input type="text" name="location" placeholder="Shelter location" maxlength="255" value="<?= e($old['location']) ?>" class="<?= $inv('location') ?>" aria-label="Shelter location"><?= $err('location') ?></div>
      </div>
      <div class="field"><textarea name="description" placeholder="Description" maxlength="2000" class="<?= $inv('description') ?>" aria-label="Description"><?= e($old['description']) ?></textarea><?= $err('description') ?></div>

      <label class="field-label">Vaccination / medical document</label>
      <div class="dropzone-doc <?= $inv('medical_doc') ?>" data-dropzone tabindex="0" role="button">
        <div class="dz-main"><?= $pet && $pet['medical_doc_name'] ? 'Drop a new document to replace the current one' : 'Drop a document (PDF/image)' ?></div>
        <div class="dz-sub">or <a href="#" onclick="return false">browse files</a></div>
        <input type="file" name="medical_doc" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
      </div>
      <?= $err('medical_doc') ?>
      <?php if ($pet && $pet['medical_doc_name']): ?><div class="current-doc">Current: <a href="file.php?kind=petdoc&amp;id=<?= $editId ?>" target="_blank" rel="noopener"><?= e($pet['medical_doc_name']) ?></a></div><?php endif; ?>

      <button type="submit" class="btn-submit" style="display:block;text-align:center;"><?= $pet ? 'Save Changes' : 'Add Pet' ?></button>
    </form>
  </div>

  <?= dev_footer() ?>

  <div class="modal-backdrop" id="successBackdrop">
    <div class="success-modal">
      <a class="modal-close" href="#">&#10005;</a>
      <p class="success-text">Pet Added Successfully</p>
    </div>
  </div>

  <script src="assets/js/ui.js"></script>
</body>
</html>
