<?php
require_once __DIR__ . '/includes/auth.php';

if ($u = current_user()) {
    redirect(dashboard_url($u['role']));
}

$roles = ['adopter' => 'Adopter', 'shelter' => 'Shelter', 'vet' => 'Vet'];
$errors = [];
$old = ['role' => $_GET['role'] ?? 'adopter'];
if (!isset($roles[$old['role']])) {
    $old['role'] = 'adopter';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $errors['_general'] = 'The files you attached are too large in total. Please upload smaller documents (max 5 MB each).';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $role = $_POST['role'] ?? '';
    $old = [
        'role' => isset($roles[$role]) ? $role : 'adopter',
        'name' => clean('name', 100),
        'email' => strtolower(clean('email', 150)),
        'phone' => clean('phone', 30),
        'contact_person' => clean('contact_person', 100),
        'registration_number' => clean('registration_number', 50),
        'address' => clean('address', 255),
        'description' => clean('description', 2000),
        'clinic' => clean('clinic', 150),
        'licence_number' => clean('licence_number', 50),
        'qualification' => clean('qualification', 150),
        'specialization' => clean('specialization', 100),
        'years_experience' => clean('years_experience', 3),
        'bio' => clean('bio', 2000),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!isset($roles[$role])) {
        $errors['_general'] = 'Choose whether you are signing up as an adopter, shelter or vet.';
        $role = 'adopter';
    }

    
    if ($old['name'] === '') {
        $errors['name'] = $role === 'shelter' ? 'Shelter name is required.' : 'Full name is required.';
    } elseif ($role === 'shelter') {
        if (!preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.,'&\-()]{1,99}$/u", $old['name'])) {
            $errors['name'] = 'Shelter name must be 2–100 characters (letters, numbers, spaces and . , \' & - ( ) only).';
        }
    } elseif (!valid_name($old['name'])) {
        $errors['name'] = 'Name must be 2–100 characters and contain only letters, spaces, dots, apostrophes or hyphens.';
    }

    if ($old['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address, e.g. name@example.com.';
    } elseif (db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$old['email']])) {
        $errors['email'] = 'An account with this email already exists. Try signing in instead.';
    }

    if ($old['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!valid_phone($old['phone'])) {
        $errors['phone'] = 'Enter a valid phone number (7–15 digits; +, spaces, dashes and brackets allowed).';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    }
    if ($confirm === '') {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    
    $codePattern = '/^[A-Za-z0-9][A-Za-z0-9\-\/]{2,49}$/';
    if ($role === 'shelter') {
        if (!valid_name($old['contact_person'])) {
            $errors['contact_person'] = 'Enter the full name of the contact person (letters, spaces, dots, apostrophes or hyphens).';
        }
        if (!preg_match($codePattern, $old['registration_number'])) {
            $errors['registration_number'] = 'Registration number must be 3–50 characters: letters, numbers, - or /.';
        }
        if (mb_strlen($old['address']) < 5) {
            $errors['address'] = 'Enter the shelter\'s full address.';
        }
        if (mb_strlen($old['description']) < 20) {
            $errors['description'] = 'Tell us about the shelter in at least 20 characters.';
        }
    } elseif ($role === 'vet') {
        if (mb_strlen($old['clinic']) < 2) {
            $errors['clinic'] = 'Clinic or workplace is required.';
        }
        if (!preg_match($codePattern, $old['licence_number'])) {
            $errors['licence_number'] = 'Licence number must be 3–50 characters: letters, numbers, - or /.';
        }
        if (mb_strlen($old['qualification']) < 2) {
            $errors['qualification'] = 'Qualification is required (e.g. DVM, university).';
        }
        if (mb_strlen($old['specialization']) < 2) {
            $errors['specialization'] = 'Specialization is required.';
        }
        if (!preg_match('/^\d{1,2}$/', $old['years_experience']) || (int) $old['years_experience'] > 60) {
            $errors['years_experience'] = 'Years of experience must be a whole number between 0 and 60.';
        }
        if (mb_strlen($old['bio']) < 20) {
            $errors['bio'] = 'Write a short professional bio of at least 20 characters.';
        }
    }

    $docs = [];
    if ($role !== 'adopter') {
        $docs = uploaded_files('documents');
        if (!$docs) {
            $errors['documents'] = 'Upload at least one verification document.';
        } elseif (count($docs) > 5) {
            $errors['documents'] = 'You can upload up to 5 documents.';
        } else {
            foreach ($docs as $doc) {
                if ($problem = upload_problem($doc, DOC_MIMES)) {
                    $errors['documents'] = $problem;
                    break;
                }
            }
        }
    }

    if (!$errors) {
        $db = get_db();
        $stored = [];
        mysqli_begin_transaction($db);
        try {
            $userId = db_exec('INSERT INTO users (role, name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)', [
                $role, $old['name'], $old['email'], $old['phone'], password_hash($password, PASSWORD_DEFAULT),
                $role === 'adopter' ? 'active' : 'pending',
            ]);
            if ($role === 'shelter') {
                db_exec('INSERT INTO shelter_profiles (user_id, contact_person, registration_number, address, description) VALUES (?, ?, ?, ?, ?)',
                    [$userId, $old['contact_person'], strtoupper($old['registration_number']), $old['address'], $old['description']]);
            } elseif ($role === 'vet') {
                db_exec('INSERT INTO vet_profiles (user_id, clinic, licence_number, qualification, specialization, years_experience, bio) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$userId, $old['clinic'], strtoupper($old['licence_number']), $old['qualification'], $old['specialization'], (int) $old['years_experience'], $old['bio']]);
            }
            foreach ($docs as $doc) {
                $path = store_upload($doc, DOC_MIMES, 'private/users');
                $stored[] = $path;
                db_exec('INSERT INTO user_documents (user_id, original_name, stored_path) VALUES (?, ?, ?)',
                    [$userId, mb_substr(basename($doc['name']), 0, 255), $path]);
            }
            mysqli_commit($db);
        } catch (Throwable $ex) {
            mysqli_rollback($db);
            foreach ($stored as $p) {
                delete_upload($p);
            }
            
            if ($ex instanceof mysqli_sql_exception && $ex->getCode() === 1062) {
                $errors['email'] = 'An account with this email already exists. Try signing in instead.';
            } else {
                throw $ex;
            }
        }

        if (!$errors) {
            if ($role === 'adopter') {
                login_user(db_one('SELECT user_id, role, name, email FROM users WHERE user_id = ?', [$userId]));
                flash('Welcome to PawHome, ' . $old['name'] . '! Your account is ready.');
                redirect('adopter.php');
            }
            redirect('login.php?registered=pending');
        }
    }
}

function err(array $errors, string $field): string {
    return isset($errors[$field]) ? '<span class="field-error" id="err-' . $field . '">' . e($errors[$field]) . '</span>' : '';
}
function inv(array $errors, string $field): string {
    return isset($errors[$field]) ? 'is-invalid' : '';
}
$v = fn(string $k) => e($old[$k] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Create an Account</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); min-height: 100vh; }
  a { color: #C1663C; text-decoration: none; }
  a:hover { color: #A8532E; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; }

  .wrap { max-width: 640px; margin: 0 auto; padding: 56px 24px 24px; }
  .panel { background: #FBF3EA; border: 1px solid rgba(46,38,46,0.06); border-radius: 28px; padding: 44px 40px; box-shadow: 0 24px 60px rgba(46,30,35,0.07); }

  h1 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 26px; margin: 0 0 8px; }
  .lead { font-size: 14.5px; color: #6B6570; margin: 0 0 28px; }
  .section-title { font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 600; margin: 8px 0 14px; }

  label.field-label { display: block; font-size: 11.5px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  input[type=text], input[type=email], input[type=password], input[type=tel], input[type=number], textarea {
    width: 100%; padding: 14px 16px; border-radius: 11px; border: 1px solid rgba(46,38,46,0.12);
    background: #FFFFFF; font-size: 14.5px; outline: none; font-family: 'Inter', sans-serif;
  }
  textarea { min-height: 96px; resize: vertical; }
  input:focus, textarea:focus { border-color: #E07B3D; }
  .field { margin-bottom: 18px; }
  .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .hint-text { font-size: 12px; color: #8A8390; margin-top: 6px; }

  .role-label { margin-bottom: 12px; font-size: 15px; font-weight: 500; color: #2E2A33; text-transform: none; letter-spacing: normal; }
  .role-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 26px; }
  .role-option { position: relative; }
  .role-option input { position: absolute; opacity: 0; }
  .role-option label {
    display: block; padding: 16px 14px; border-radius: 13px; border: 1.5px solid rgba(46,38,46,0.12);
    background: #FFFFFF; font-size: 14.5px; font-weight: 700; color: #2E2A33; cursor: pointer; text-align: center;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }
  .role-option label:hover { border-color: rgba(224,123,61,0.5); }
  .role-option input:checked + label { border-color: #E07B3D; background: #F7E3D4; color: #C1662E; }
  .role-option input:focus-visible + label { outline: 3px solid rgba(224,123,61,0.3); outline-offset: 2px; }

  
  .role-section, .label-shelter { display: none; }
  .panel:has(#r-shelter:checked) .shelter-section,
  .panel:has(#r-vet:checked) .vet-section,
  .panel:has(#r-shelter:checked) .docs-section,
  .panel:has(#r-vet:checked) .docs-section { display: block; }
  .panel:has(#r-shelter:checked) .label-person { display: none; }
  .panel:has(#r-shelter:checked) .label-shelter { display: inline; }

  .dropzone { border: 1.5px dashed rgba(46,38,46,0.25); border-radius: 14px; background: #FFFFFF; padding: 22px 16px; text-align: center; cursor: pointer; display: block; }
  .dropzone:hover { background: #FFFDFB; }
  .dropzone .dz-main { font-size: 14px; font-weight: 600; color: #2E2A33; }
  .dropzone .dz-sub { font-size: 12.5px; color: #66606C; margin-top: 4px; }
  .dropzone input { display: none; }
  .file-list { margin: 8px 0 0; padding: 0; list-style: none; font-size: 12.5px; color: #4A4450; }
  .file-list li { padding: 3px 0; }

  .note-box { background: #F7E3D4; color: #8A4A22; border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.5; margin-bottom: 20px; }

  .btn-primary { width: 100%; padding: 16px; border: none; border-radius: 14px; background: #E8792E; color: #fff; font-weight: 700; font-size: 16px; cursor: pointer; box-shadow: 0 10px 24px rgba(232,121,46,0.3); font-family: 'Inter', sans-serif; margin-top: 6px; }
  .btn-primary:hover { background: #D2691E; }
  .switch { text-align: center; font-size: 14px; color: #6B6570; margin-top: 20px; }
  .switch a { font-weight: 600; }

  @media (max-width: 560px) { .panel { padding: 32px 22px; } .row-2 { grid-template-columns: 1fr; gap: 0; } .role-grid { grid-template-columns: 1fr; } }
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
    </div>
  </div>

  <div class="wrap">
    <form class="panel" id="signupForm" method="post" action="signup.php" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <h1>Create your PawHome account</h1>
      <p class="lead">Adopt a pet, list your shelter's animals, or offer veterinary care.</p>

      <?php if (isset($errors['_general'])): ?><div class="form-alert" role="alert"><?= e($errors['_general']) ?></div><?php endif; ?>
      <?php if ($errors && !isset($errors['_general'])): ?><div class="form-alert" role="alert">Please fix the highlighted fields below.</div><?php endif; ?>

      <div class="role-label field-label">I am signing up as</div>
      <div class="role-grid">
        <?php foreach ($roles as $value => $label): ?>
          <div class="role-option"><input type="radio" name="role" id="r-<?= $value ?>" value="<?= $value ?>" <?= $old['role'] === $value ? 'checked' : '' ?>><label for="r-<?= $value ?>"><?= $label ?></label></div>
        <?php endforeach; ?>
      </div>

      <div class="field">
        <label class="field-label" for="name"><span class="label-person">Full name</span><span class="label-shelter">Shelter name</span></label>
        <input type="text" id="name" name="name" maxlength="100" required autocomplete="name" value="<?= $v('name') ?>" class="<?= inv($errors, 'name') ?>">
        <?= err($errors, 'name') ?>
      </div>
      <div class="row-2">
        <div class="field">
          <label class="field-label" for="email">Email</label>
          <input type="email" id="email" name="email" maxlength="150" required autocomplete="email" placeholder="you@example.com" value="<?= $v('email') ?>" class="<?= inv($errors, 'email') ?>">
          <?= err($errors, 'email') ?>
        </div>
        <div class="field">
          <label class="field-label" for="phone">Phone</label>
          <input type="tel" id="phone" name="phone" maxlength="30" required autocomplete="tel" placeholder="+880 1711-234567" value="<?= $v('phone') ?>" class="<?= inv($errors, 'phone') ?>">
          <?= err($errors, 'phone') ?>
        </div>
      </div>
      <div class="row-2">
        <div class="field">
          <label class="field-label" for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8" class="<?= inv($errors, 'password') ?>">
          <div class="hint-text">At least 8 characters, with a letter and a number.</div>
          <?= err($errors, 'password') ?>
        </div>
        <div class="field">
          <label class="field-label" for="confirm_password">Confirm password</label>
          <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" class="<?= inv($errors, 'confirm_password') ?>">
          <?= err($errors, 'confirm_password') ?>
        </div>
      </div>

      <fieldset class="role-section shelter-section" data-role="shelter" style="border:0;padding:0;margin:0;">
        <div class="section-title">Shelter details</div>
        <div class="row-2">
          <div class="field">
            <label class="field-label" for="contact_person">Contact person</label>
            <input type="text" id="contact_person" name="contact_person" maxlength="100" required value="<?= $v('contact_person') ?>" class="<?= inv($errors, 'contact_person') ?>">
            <?= err($errors, 'contact_person') ?>
          </div>
          <div class="field">
            <label class="field-label" for="registration_number">Registration number</label>
            <input type="text" id="registration_number" name="registration_number" maxlength="50" required placeholder="SH-DHK-12345" value="<?= $v('registration_number') ?>" class="<?= inv($errors, 'registration_number') ?>">
            <?= err($errors, 'registration_number') ?>
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="address">Address</label>
          <input type="text" id="address" name="address" maxlength="255" required placeholder="Street, area, city" value="<?= $v('address') ?>" class="<?= inv($errors, 'address') ?>">
          <?= err($errors, 'address') ?>
        </div>
        <div class="field">
          <label class="field-label" for="description">About the shelter</label>
          <textarea id="description" name="description" maxlength="2000" required minlength="20" placeholder="Capacity, years operating, the animals you care for..." class="<?= inv($errors, 'description') ?>"><?= $v('description') ?></textarea>
          <?= err($errors, 'description') ?>
        </div>
      </fieldset>

      <fieldset class="role-section vet-section" data-role="vet" style="border:0;padding:0;margin:0;">
        <div class="section-title">Professional details</div>
        <div class="row-2">
          <div class="field">
            <label class="field-label" for="clinic">Clinic / workplace</label>
            <input type="text" id="clinic" name="clinic" maxlength="150" required value="<?= $v('clinic') ?>" class="<?= inv($errors, 'clinic') ?>">
            <?= err($errors, 'clinic') ?>
          </div>
          <div class="field">
            <label class="field-label" for="licence_number">Licence number</label>
            <input type="text" id="licence_number" name="licence_number" maxlength="50" required placeholder="VET-BD-12345" value="<?= $v('licence_number') ?>" class="<?= inv($errors, 'licence_number') ?>">
            <?= err($errors, 'licence_number') ?>
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label class="field-label" for="qualification">Qualification</label>
            <input type="text" id="qualification" name="qualification" maxlength="150" required placeholder="DVM, university" value="<?= $v('qualification') ?>" class="<?= inv($errors, 'qualification') ?>">
            <?= err($errors, 'qualification') ?>
          </div>
          <div class="field">
            <label class="field-label" for="specialization">Specialization</label>
            <input type="text" id="specialization" name="specialization" maxlength="100" required placeholder="General & Preventive Care" value="<?= $v('specialization') ?>" class="<?= inv($errors, 'specialization') ?>">
            <?= err($errors, 'specialization') ?>
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="years_experience">Years of experience</label>
          <input type="number" id="years_experience" name="years_experience" min="0" max="60" step="1" required value="<?= $v('years_experience') ?>" class="<?= inv($errors, 'years_experience') ?>">
          <?= err($errors, 'years_experience') ?>
        </div>
        <div class="field">
          <label class="field-label" for="bio">Professional bio</label>
          <textarea id="bio" name="bio" maxlength="2000" required minlength="20" placeholder="Your experience, focus areas and certifications..." class="<?= inv($errors, 'bio') ?>"><?= $v('bio') ?></textarea>
          <?= err($errors, 'bio') ?>
        </div>
      </fieldset>

      <fieldset class="role-section docs-section" data-role="shelter vet" style="border:0;padding:0;margin:0;">
        <div class="field">
          <label class="field-label" for="documents">Verification documents</label>
          <label class="dropzone <?= inv($errors, 'documents') ?>" id="docDrop">
            <div class="dz-main">Drop registration / licence documents here</div>
            <div class="dz-sub">or click to browse — PDF, JPG, PNG or WEBP, up to 5 files, 5 MB each</div>
            <input type="file" id="documents" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
          </label>
          <ul class="file-list" id="fileList"></ul>
          <?= err($errors, 'documents') ?>
        </div>
        <div class="note-box">Shelter and vet accounts are reviewed by the PawHome admin team before they can sign in. We'll verify your documents and approve your account.</div>
      </fieldset>

      <button type="submit" class="btn-primary">Create Account</button>
      <p class="switch">Already have an account? <a href="login.php">Sign in</a></p>
    </form>
  </div>

  <?= dev_footer() ?>

  <script src="assets/js/signup.js"></script>
</body>
</html>
