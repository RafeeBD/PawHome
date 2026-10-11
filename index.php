<?php
require_once __DIR__ . '/includes/public.php';

$featured = public_pets(4);
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Home</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: linear-gradient(160deg, #FBF4EC 0%, #F5EAE3 45%, #EAE2ED 100%); }
  a { color: #C1663C; text-decoration: none; }
  a:hover { color: #A8532E; }

  .nav { position: sticky; top: 0; z-index: 50; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); background: rgba(255,255,255,0.55); border-bottom: 1px solid rgba(255,255,255,0.7); box-shadow: 0 1px 0 rgba(46,42,51,0.04); }
  .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 32px; gap: 24px; }
  .brand { display: flex; align-items: center; gap: 10px; cursor: pointer; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 18px; letter-spacing: -0.01em; }
  .nav-links { display: flex; align-items: center; gap: 2px; }
  .nav-links a { padding: 9px 15px; border-radius: 10px; font-size: 13.5px; font-weight: 500; color: #66606C; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; cursor: pointer; }

  .container { max-width: 1280px; margin: 0 auto; padding: 56px 32px 88px; }
  .hero { display: grid; grid-template-columns: 1.1fr 1fr; gap: 40px; align-items: center; background: rgba(255,255,255,0.5); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,0.75); border-radius: 24px; box-shadow: 0 20px 50px rgba(46,30,35,0.08); padding: 52px; }
  .eyebrow { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; letter-spacing: 0.08em; color: #A8532E; margin-bottom: 20px; text-transform: uppercase; }
  h1 { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 44px; line-height: 1.15; letter-spacing: -0.02em; margin: 0 0 18px; color: #2A262E; }
  .hero p { font-size: 16px; line-height: 1.7; color: #66606C; margin: 0 0 32px; max-width: 460px; }
  .btns { display: flex; gap: 12px; }
  .btn-primary { display: inline-flex; align-items: center; justify-content: center; padding: 14px 26px; border: none; border-radius: 12px; background: #C1663C; color: #fff; font-weight: 600; font-size: 15px; text-align: center; cursor: pointer; box-shadow: 0 8px 20px rgba(180,90,55,0.28); }
  .btn-secondary { display: inline-flex; align-items: center; justify-content: center; padding: 14px 26px; border: 1.5px solid rgba(46,38,46,0.15); border-radius: 12px; background: transparent; color: #2E2A33; font-weight: 600; font-size: 15px; text-align: center; cursor: pointer; }
  .hero-photo { width: 100%; height: 320px; border-radius: 18px; position: relative; overflow: hidden; box-shadow: 0 4px 24px rgba(46,30,35,0.1); background-size: cover; background-position: center; }

  .section { margin-top: 72px; }
  .section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 24px; }
  .section-head h2 { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 24px; letter-spacing: -0.01em; margin: 0; color: #2A262E; }
  .section-head a { font-size: 14px; font-weight: 600; }

  .pet-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
  .pet-card { display: block; color: inherit; background: rgba(255,255,255,0.5); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid rgba(255,255,255,0.75); border-radius: 16px; overflow: hidden; box-shadow: 0 8px 22px rgba(46,30,35,0.08); cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
  .pet-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(46,30,35,0.12); }
  .pet-photo { width: 100%; height: 170px; position: relative; background-size: cover; background-position: center; }
  .pet-badge { position: absolute; top: 10px; right: 10px; display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 100px; font-size: 10.5px; font-weight: 600; }
  .pet-info { padding: 15px 16px; }
  .pet-row { display: flex; align-items: baseline; justify-content: space-between; }
  .pet-name { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 15px; letter-spacing: -0.005em; color: #2A262E; }
  .pet-age { font-size: 12px; color: #8A8390; }
  .pet-breed { font-size: 12.5px; color: #66606C; margin-top: 4px; }

  .stats { margin-top: 64px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
  .stat-card { background: rgba(255,255,255,0.5); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.75); border-radius: 18px; padding: 26px; text-align: center; box-shadow: 0 10px 28px rgba(46,30,35,0.06); }
  .stat-card.highlight { background: #C1663C; border-color: transparent; }
  .stat-value { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 28px; color: #2A262E; }
  .stat-card.highlight .stat-value { color: #fff; }
  .stat-label { font-size: 13.5px; margin-top: 6px; color: #66606C; }
  .stat-card.highlight .stat-label { color: rgba(255,255,255,0.85); }

  .footer-note { margin-top: 32px; border-top: 1px solid rgba(46,38,46,0.1); padding-top: 32px; text-align: center; }
  .footer-note p { font-size: 14.5px; line-height: 1.7; color: #66606C; margin: 0 auto; max-width: 600px; }

  @media (max-width: 900px) { .hero { grid-template-columns: 1fr; padding: 32px; } .pet-grid { grid-template-columns: repeat(2, 1fr); } .stats { grid-template-columns: 1fr; } h1 { font-size: 34px; } }
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
        <div class="nav-links">
          <a href="index.php">Home</a>
          <a href="browse.php">Browse Pets</a>
        </div>
        <?= public_nav_cta() ?>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="hero">
      <div>
        <div class="eyebrow">A home for every paw</div>
        <h1>Find your new best friend, and give them a lifetime of care.</h1>
        <p>Browse adoptable pets from trusted local shelters, apply in minutes, and keep every vet visit organized in one warm, welcoming place.</p>
        <div class="btns">
          <a class="btn-primary" href="browse.php">Browse Pets</a>
          <?php if ($user): ?>
            <a class="btn-secondary" href="<?= dashboard_url($user['role']) ?>">My Dashboard</a>
          <?php else: ?>
            <a class="btn-secondary" href="signup.php">Sign Up</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-photo" style="background-image:url('assets/hero.png');"></div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Featured pets</h2>
        <a href="browse.php">See all &rarr;</a>
      </div>
      <div class="pet-grid">
        <?php foreach ($featured as $p): ?>
          <?= pet_card($p) ?>
        <?php endforeach; ?>
      </div>
      <?php if (!$featured): ?><p class="empty-note">No pets are listed right now — check back soon!</p><?php endif; ?>
    </div>

    <div class="stats">
      <div class="stat-card highlight">
        <div class="stat-value">500+</div>
        <div class="stat-label">Pets adopted</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">40+</div>
        <div class="stat-label">Partner shelters</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">24/7</div>
        <div class="stat-label">Vet support</div>
      </div>
    </div>

    <div class="footer-note">
      <p>PawHome partners with local shelters and licensed veterinarians so every adoption comes with a clear health record, ongoing checkups, and a shelter that's always reachable &mdash; because finding a home is just the beginning.</p>
    </div>

    <?= dev_footer() ?>
  </div>

</body>
</html>
