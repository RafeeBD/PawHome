<?php
require_once __DIR__ . '/includes/public.php';

$id = (int) ($_GET['id'] ?? 0);
$pet = db_one('SELECT p.*, c.name AS category, s.name AS shelter_name, s.user_id AS shelter_user, sp.address AS shelter_address
               FROM pets p JOIN categories c ON c.category_id = p.category_id
               JOIN users s ON s.user_id = p.shelter_id
               LEFT JOIN shelter_profiles sp ON sp.user_id = s.user_id
               WHERE p.pet_id = ? AND s.status = "active"', [$id]);
if (!$pet) {
    http_response_code(404);
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Pet not found</title><body style="font-family:sans-serif;text-align:center;padding:80px">'
        . '<h2>This pet could not be found.</h2><p><a href="browse.php">Back to browse</a></p></body>');
}

$user = current_user();
$isAdopter = $user && $user['role'] === 'adopter';
$existing = $isAdopter ? db_one('SELECT status FROM applications WHERE pet_id = ? AND adopter_id = ? AND status IN ("Pending","Approved","Completed")',
    [$id, $user['user_id']]) : null;

$errors = [];
$old = [];
$openApply = false;
if ($isAdopter) {
    $me = db_one('SELECT name, email, phone FROM users WHERE user_id = ?', [$user['user_id']]);
    $old = ['full_name' => $me['name'], 'email' => $me['email'], 'phone' => $me['phone'], 'household' => '', 'experience' => '', 'message' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $errors['_general'] = 'The attached file is too large. Please upload a document under 5 MB.';
    $openApply = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('adopter');
    verify_csrf();
    $openApply = true;
    $old = [
        'full_name' => clean('full_name', 100),
        'email' => strtolower(clean('email', 150)),
        'phone' => clean('phone', 30),
        'household' => clean('household', 255),
        'experience' => clean('experience', 255),
        'message' => clean('message', 2000),
    ];

    if ($pet['availability'] !== 'Available') {
        $errors['_general'] = $pet['name'] . ' is no longer available for adoption.';
    } elseif ($existing) {
        $errors['_general'] = 'You have already applied to adopt ' . $pet['name'] . '.';
    }
    if (!valid_name($old['full_name'])) {
        $errors['full_name'] = 'Enter your full name (letters, spaces, dots, apostrophes or hyphens).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (!valid_phone($old['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }
    if (mb_strlen($old['household']) < 5) {
        $errors['household'] = 'Describe your home in a few words (e.g. "Apartment, no other pets").';
    }
    if (mb_strlen($old['experience']) < 5) {
        $errors['experience'] = 'Tell the shelter about your experience with pets.';
    }
    if (mb_strlen($old['message']) < 20) {
        $errors['message'] = 'Tell the shelter why you\'d be a great fit (at least 20 characters).';
    }
    $files = uploaded_files('document');
    if (!$files) {
        $errors['document'] = 'Attach your ID, CV or a reference letter.';
    } elseif ($problem = upload_problem($files[0], DOC_MIMES)) {
        $errors['document'] = $problem;
    }

    if (!$errors) {
        $path = store_upload($files[0], DOC_MIMES, 'private/applications');
        db_exec('INSERT INTO applications (pet_id, adopter_id, full_name, email, phone, household, experience, message, document_path, document_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $user['user_id'], $old['full_name'], $old['email'], $old['phone'], $old['household'], $old['experience'],
            $old['message'], $path, mb_substr(basename($files[0]['name']), 0, 255),
        ]);
        redirect('pet.php?id=' . $id . '#successBackdrop');
    }
}

$photo = pet_photo_url($pet['photo_path']);
$err = fn(string $f) => isset($errors[$f]) ? '<span class="field-error">' . e($errors[$f]) . '</span>' : '';
$inv = fn(string $f) => isset($errors[$f]) ? 'is-invalid' : '';
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
  a:hover { color: #A8532E; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; gap: 24px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; }
  .nav-links { display: flex; align-items: center; gap: 2px; }
  .nav-links a { padding: 9px 15px; border-radius: 10px; font-size: 13.5px; font-weight: 500; color: #66606C; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }

  .container { max-width: 1180px; margin: 0 auto; padding: 32px 32px 88px; }
  .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }

  .top-grid { display: grid; grid-template-columns: 1.05fr 1fr; gap: 28px; align-items: start; }
  .photo { width: 100%; height: 520px; border-radius: 20px; object-fit: cover; box-shadow: 0 20px 50px rgba(46,30,35,0.12); background: #EAE2ED center/cover no-repeat; }
  .more-photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 12px; }
  .more-photo-tile { height: 84px; border-radius: 14px; background: rgba(193,102,60,0.12); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #A8532E; }

  .card { background: rgba(255,255,255,0.5); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,0.75); border-radius: 22px; padding: 30px; box-shadow: 0 20px 50px rgba(46,30,35,0.08); }
  .badges { display: flex; gap: 8px; margin-bottom: 18px; }
  .badge { padding: 5px 13px; border-radius: 100px; font-size: 12px; font-weight: 600; background: #DCEEE2; color: #2C6B4A; }
  h1 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 32px; margin: 0 0 4px; }
  .breed { font-size: 15px; color: #66606C; margin: 0 0 22px; }

  .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
  .stat { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 14px 16px; }
  .stat-label { font-size: 10.5px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #8A8390; margin-bottom: 6px; }
  .stat-value { font-weight: 700; font-size: 16px; }
  .stat-wide { margin-bottom: 22px; }

  .shelter-box { background: rgba(255,255,255,0.6); border-radius: 14px; padding: 16px 18px; margin-bottom: 22px; }
  .shelter-box .stat-label { margin-bottom: 8px; }
  .shelter-box .shelter-name { font-weight: 700; font-size: 15px; margin-bottom: 4px; }
  .shelter-box .shelter-note { font-size: 13.5px; color: #66606C; line-height: 1.5; }

  .desc { font-size: 14.5px; line-height: 1.7; color: #46414B; margin: 0 0 24px; }
  .btn-row { display: flex; gap: 12px; }
  .btn-primary { flex: 1; display: inline-flex; align-items: center; justify-content: center; padding: 15px; border: none; border-radius: 12px; background: #C1663C; color: #fff; font-weight: 600; font-size: 15px; text-align: center; cursor: pointer; box-shadow: 0 8px 20px rgba(180,90,55,0.25); }
  .btn-primary.is-disabled { background: #B9AEB3; box-shadow: none; cursor: default; }
  .btn-secondary { flex: 1; display: inline-flex; align-items: center; justify-content: center; padding: 15px; border: 1.5px solid rgba(46,38,46,0.15); border-radius: 12px; background: transparent; color: #2E2A33; font-weight: 600; font-size: 15px; cursor: pointer; }

  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(46,38,46,0.35); align-items: center; justify-content: center; padding: 24px; z-index: 100; }
  .modal-backdrop.open, .modal-backdrop:target { display: flex; }
  .modal { width: 100%; max-width: 560px; background: rgba(255,255,255,0.96); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.8); border-radius: 22px; padding: 34px; box-shadow: 0 30px 70px rgba(46,30,35,0.25); position: relative; max-height: 90vh; overflow-y: auto; }
  .modal-close { position: absolute; top: 20px; right: 20px; width: 32px; height: 32px; border-radius: 10px; border: none; background: rgba(46,38,46,0.08); color: #2E2A33; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
  .modal-close:hover { background: rgba(46,38,46,0.14); }

  .modal-head { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; padding-right: 40px; }
  .modal-head img { width: 56px; height: 56px; border-radius: 14px; object-fit: cover; flex-shrink: 0; }
  .modal-head h2 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 22px; margin: 0 0 4px; }
  .modal-head .lead { font-size: 13.5px; color: #66606C; margin: 0; }

  .modal label.field-label { display: block; font-size: 11.5px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  .modal input[type=text], .modal input[type=email], .modal input[type=tel], .modal textarea {
    width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(46,38,46,0.15);
    background: rgba(255,255,255,0.8); font-size: 14.5px; outline: none; font-family: 'Inter', sans-serif;
  }
  .modal input:focus, .modal textarea:focus { border-color: #C1663C; }
  .modal textarea { min-height: 90px; resize: vertical; }
  .modal .field { margin-bottom: 16px; }
  .modal .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
  .modal .row-2 .field { margin-bottom: 0; }

  .dropzone { border: 1.5px dashed rgba(46,38,46,0.25); border-radius: 14px; background: rgba(46,38,46,0.03); padding: 26px 16px; text-align: center; margin-bottom: 22px; cursor: pointer; }
  .dropzone:hover { background: rgba(46,38,46,0.05); }
  .dropzone .dz-icon { font-size: 18px; margin-bottom: 8px; }
  .dropzone .dz-main { font-size: 14px; font-weight: 600; color: #2E2A33; }
  .dropzone .dz-sub { font-size: 13px; color: #66606C; margin-top: 4px; }
  .dropzone .dz-sub a { font-weight: 600; text-decoration: underline; }
  .dropzone + .field-error { margin: -14px 0 18px; }

  .btn-submit { width: 100%; min-height: 52px; display: flex; align-items: center; justify-content: center; padding: 14px 20px; border: none; border-radius: 14px; background: #C1663C; color: #fff; font-weight: 700; font-size: 15.5px; line-height: 1.2; text-align: center; cursor: pointer; box-shadow: 0 10px 24px rgba(180,90,55,0.3); font-family: 'Inter', sans-serif; }
  .btn-submit:link, .btn-submit:visited, .btn-submit:hover, .btn-submit:active { color: #fff; }
  .btn-submit:hover { background: #A8532E; }

  .success-modal { max-width: 460px; background: #C1663C; padding: 46px 34px; text-align: center; }
  .success-modal .modal-close { background: rgba(255,255,255,0.18); color: #fff; }
  .success-modal .modal-close:hover { background: rgba(255,255,255,0.3); }
  .success-modal .success-text { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 19px; letter-spacing: 0.03em; text-transform: uppercase; color: #fff; margin: 0; }

  @media (max-width: 900px) { .top-grid { grid-template-columns: 1fr; } .photo { height: 320px; } }
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
        <?php if ($isAdopter): ?>
          <div class="nav-links">
            <a href="browse.php">Browse Pets</a>
            <a href="adopter.php">Adopter Dashboard</a>
          </div>
          <div class="avatar"><?= e(initials($user['name'])) ?></div>
        <?php else: ?>
          <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="browse.php">Browse Pets</a>
          </div>
          <?= public_nav_cta() ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="container">
    <a class="back-link" href="browse.php">&larr; Back to browse</a>

    <div class="top-grid">
      <div>
        <div class="photo" id="petPhoto" style="background-image:url('<?= e($photo) ?>')"></div>
        <div class="more-photos">
          <div class="more-photo-tile">More photos</div>
          <div class="more-photo-tile">More photos</div>
          <div class="more-photo-tile">More photos</div>
        </div>
      </div>

      <div class="card">
        <div class="badges">
          <span class="badge" style="<?= availability_style($pet['availability']) ?>"><?= e($pet['availability']) ?></span>
          <?php if ($pet['vaccinated']): ?><span class="badge" style="background:#E9E1F1;color:#6C4B7A">Vaccinated</span><?php endif; ?>
          <?php if ($pet['spayed_neutered']): ?><span class="badge" style="background:#E3ECF6;color:#35587A">Spayed/Neutered</span><?php endif; ?>
        </div>
        <h1><?= e($pet['name']) ?></h1>
        <p class="breed"><?= e($pet['breed']) ?> · <?= e($pet['category']) ?></p>

        <div class="stat-grid">
          <div class="stat"><div class="stat-label">Age</div><div class="stat-value"><?= e(fmt_age($pet['birth_date'], true)) ?></div></div>
          <div class="stat"><div class="stat-label">Gender</div><div class="stat-value"><?= e($pet['gender']) ?></div></div>
          <div class="stat"><div class="stat-label">Size</div><div class="stat-value"><?= e($pet['size']) ?></div></div>
          <div class="stat"><div class="stat-label">Health</div><div class="stat-value"><?= e($pet['health_condition']) ?></div></div>
        </div>

        <div class="stat stat-wide">
          <div class="stat-label">Behaviour / Temperament</div>
          <div class="stat-value"><?= e($pet['behaviour'] ?: 'Ask the shelter for details.') ?></div>
        </div>

        <p class="desc"><?= nl2br(e($pet['description'])) ?></p>

        <div class="shelter-box">
          <div class="stat-label">Shelter information</div>
          <div class="shelter-name"><?= e($pet['shelter_name']) ?></div>
          <div class="shelter-note"><?= e($pet['shelter_address'] ?: $pet['location']) ?> · Reach out via the chat button below for adoption questions.</div>
        </div>

        <div class="btn-row">
          <?php if (!$user): ?>
            <a class="btn-primary" href="login.php?next=<?= urlencode('pet.php?id=' . $id) ?>">Apply for Adoption</a>
            <a class="btn-secondary" href="login.php?next=<?= urlencode('adopter.php?chat=' . $pet['shelter_user']) ?>">Chat with Shelter</a>
          <?php elseif (!$isAdopter): ?>
            <span class="btn-primary is-disabled" title="Only adopter accounts can apply">Adopter accounts can apply</span>
          <?php elseif ($existing): ?>
            <a class="btn-primary" href="adopter.php">Application <?= $existing['status'] === 'Pending' ? 'submitted' : strtolower(e($existing['status'])) ?> — view</a>
            <a class="btn-secondary" href="adopter.php?chat=<?= (int) $pet['shelter_user'] ?>#messages">Chat with Shelter</a>
          <?php elseif ($pet['availability'] !== 'Available'): ?>
            <span class="btn-primary is-disabled"><?= $pet['availability'] === 'Adopted' ? 'Already adopted' : 'Adoption in progress' ?></span>
            <a class="btn-secondary" href="adopter.php?chat=<?= (int) $pet['shelter_user'] ?>#messages">Chat with Shelter</a>
          <?php else: ?>
            <a class="btn-primary" id="applyBtn" href="#applyBackdrop">Apply for Adoption</a>
            <a class="btn-secondary" href="adopter.php?chat=<?= (int) $pet['shelter_user'] ?>#messages">Chat with Shelter</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?= dev_footer() ?>

  <?php if ($isAdopter): ?>
  <div class="modal-backdrop <?= $openApply ? 'open' : '' ?>" id="applyBackdrop">
    <form class="modal" method="post" action="pet.php?id=<?= $id ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <a class="modal-close" href="#">&#10005;</a>
      <div class="modal-head">
        <img src="<?= e($photo) ?>" alt="<?= e($pet['name']) ?>">
        <div>
          <h2>Apply to adopt</h2>
          <p class="lead"><?= e($pet['name']) ?> · <?= e($pet['breed']) ?> · <?= e($pet['shelter_name']) ?></p>
        </div>
      </div>

      <?php if (isset($errors['_general'])): ?><div class="form-alert"><?= e($errors['_general']) ?></div><?php endif; ?>

      <div class="field">
        <label class="field-label" for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" maxlength="100" required value="<?= e($old['full_name'] ?? '') ?>" class="<?= $inv('full_name') ?>">
        <?= $err('full_name') ?>
      </div>
      <div class="row-2">
        <div class="field"><input type="email" name="email" placeholder="Email" maxlength="150" required value="<?= e($old['email'] ?? '') ?>" class="<?= $inv('email') ?>"><?= $err('email') ?></div>
        <div class="field"><input type="tel" name="phone" placeholder="Phone" maxlength="30" required value="<?= e($old['phone'] ?? '') ?>" class="<?= $inv('phone') ?>"><?= $err('phone') ?></div>
      </div>
      <div class="row-2">
        <div class="field"><input type="text" name="household" placeholder="Household (e.g. Apartment, no other pets)" maxlength="255" required value="<?= e($old['household'] ?? '') ?>" class="<?= $inv('household') ?>"><?= $err('household') ?></div>
        <div class="field"><input type="text" name="experience" placeholder="Experience with pets" maxlength="255" required value="<?= e($old['experience'] ?? '') ?>" class="<?= $inv('experience') ?>"><?= $err('experience') ?></div>
      </div>
      <div class="field">
        <textarea name="message" placeholder="Why would you be a great fit?" maxlength="2000" required class="<?= $inv('message') ?>"><?= e($old['message'] ?? '') ?></textarea>
        <?= $err('message') ?>
      </div>

      <label class="field-label">ID / CV / Reference document</label>
      <div class="dropzone <?= $inv('document') ?>" data-dropzone tabindex="0" role="button">
        <div class="dz-icon">&#128196;</div>
        <div class="dz-main">Drop your ID, CV, or reference letter</div>
        <div class="dz-sub">or <a href="#" onclick="return false">browse files</a> — PDF, JPG or PNG, max 5 MB</div>
        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
      </div>
      <?= $err('document') ?>

      <button type="submit" class="btn-submit">Submit Application</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="modal-backdrop" id="successBackdrop">
    <div class="modal success-modal">
      <a class="modal-close" href="#">&#10005;</a>
      <p class="success-text">Submit Successfully</p>
    </div>
  </div>

  <script src="assets/js/ui.js"></script>
</body>
</html>
