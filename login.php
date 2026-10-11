<?php
require_once __DIR__ . '/includes/auth.php';

if ($u = current_user()) {
    redirect(dashboard_url($u['role']));
}

$roles = ['adopter' => 'Adopter', 'shelter' => 'Shelter', 'vet' => 'Vet', 'admin' => 'Admin'];
$errors = [];
$old = ['role' => 'adopter', 'email' => ''];
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $role = $_POST['role'] ?? '';
    $email = strtolower(clean('email', 150));
    $password = (string) ($_POST['password'] ?? '');
    $old = ['role' => isset($roles[$role]) ? $role : 'adopter', 'email' => $email];

    if (!isset($roles[$role])) {
        $errors['_general'] = 'Choose whether you are an adopter, shelter, vet or admin.';
    }
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    if (!$errors) {
        $user = db_one('SELECT user_id, role, name, email, password_hash, status FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors['_general'] = 'Email or password is incorrect.';
        } elseif ($user['role'] !== $role) {
            $errors['_general'] = 'This account is registered as ' . (in_array($user['role'], ['adopter', 'admin']) ? 'an ' : 'a ')
                . strtolower($roles[$user['role']]) . '. Select "' . $roles[$user['role']] . '" above and sign in again.';
        } elseif ($user['status'] === 'pending') {
            $errors['_general'] = 'Your registration is still awaiting admin approval. You can sign in once it has been approved.';
        } elseif ($user['status'] === 'rejected') {
            $errors['_general'] = 'Your registration was not approved by the PawHome admin team. Please contact support for details.';
        } else {
            login_user($user);
            redirect($next && $user['role'] === 'adopter' ? $next : dashboard_url($user['role']));
        }
    }
}
$registered = isset($_GET['registered']) ? $_GET['registered'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Sign In</title>
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
  .nav-links a { padding: 9px 15px; border-radius: 10px; font-size: 13.5px; font-weight: 500; color: #66606C; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }

  .wrap { max-width: 520px; margin: 0 auto; padding: 56px 24px 24px; }
  .panel { background: #FBF3EA; border: 1px solid rgba(46,38,46,0.06); border-radius: 28px; padding: 44px 40px; box-shadow: 0 24px 60px rgba(46,30,35,0.07); }

  h1 { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 26px; margin: 0 0 8px; }
  .lead { font-size: 14.5px; color: #6B6570; margin: 0 0 28px; }

  label.field-label { display: block; font-size: 11.5px; font-weight: 600; color: #8A8390; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  input[type=text], input[type=email], input[type=password] {
    width: 100%; padding: 14px 16px; border-radius: 11px; border: 1px solid rgba(46,38,46,0.12);
    background: #FFFFFF; font-size: 14.5px; margin-bottom: 18px; outline: none; font-family: 'Inter', sans-serif;
  }
  input:focus { border-color: #E07B3D; }
  .field-error { margin: -12px 0 16px; }

  .role-label { margin-bottom: 12px; font-size: 15px; font-weight: 500; color: #2E2A33; text-transform: none; letter-spacing: normal; }
  .role-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 12px; margin-bottom: 26px; }
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

  .btn-primary { width: 100%; padding: 16px; border: none; border-radius: 14px; background: #E8792E; color: #fff; font-weight: 700; font-size: 16px; cursor: pointer; box-shadow: 0 10px 24px rgba(232,121,46,0.3); font-family: 'Inter', sans-serif; }
  .btn-primary:hover { background: #D2691E; }

  .hint { text-align: center; font-size: 12.5px; color: #8A8390; margin-top: 18px; line-height: 1.6; }
  .switch { text-align: center; font-size: 14px; color: #6B6570; margin-top: 20px; }
  .switch a { font-weight: 600; }
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
    <form class="panel" method="post" action="login.php" novalidate>
      <?= csrf_field() ?>
      <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
      <h1>Sign in to PawHome</h1>
      <p class="lead">Choose your account type and sign in.</p>

      <?php if ($registered === 'adopter'): ?>
        <div class="form-alert ok">Your account is ready — sign in below.</div>
      <?php elseif ($registered === 'pending'): ?>
        <div class="form-alert ok">Registration received! An admin will review your documents. You can sign in once you're approved.</div>
      <?php endif; ?>
      <?php if (isset($errors['_general'])): ?><div class="form-alert" role="alert"><?= e($errors['_general']) ?></div><?php endif; ?>

      <div class="role-label field-label">I am a</div>
      <div class="role-grid">
        <?php foreach ($roles as $value => $label): ?>
          <div class="role-option"><input type="radio" name="role" id="r-<?= $value ?>" value="<?= $value ?>" <?= $old['role'] === $value ? 'checked' : '' ?>><label for="r-<?= $value ?>"><?= $label ?></label></div>
        <?php endforeach; ?>
      </div>

      <label class="field-label" for="email">Email</label>
      <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required
             value="<?= e($old['email']) ?>" class="<?= isset($errors['email']) ? 'is-invalid' : '' ?>">
      <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>

      <label class="field-label" for="password">Password</label>
      <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required
             class="<?= isset($errors['password']) ? 'is-invalid' : '' ?>">
      <?php if (isset($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>

      <button type="submit" class="btn-primary">Sign In</button>
      <p class="switch">New to PawHome? <a href="signup.php">Create an account</a></p>
      <p class="hint">Demo accounts — password <strong>Paw12345</strong><br>
        Adopter: sarah@pawhome.test<br>
        Shelters: shelter@pawhome.test (Hope Haven) · pawsafe@pawhome.test (PawSafe)<br>
        Vets: vet@pawhome.test (Dr. Amelia Ruiz) · marcus@pawhome.test (Dr. Marcus Webb)<br>
        Admin: admin@pawhome.test</p>
    </form>
  </div>

  <?= dev_footer() ?>

</body>
</html>
