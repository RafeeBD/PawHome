<?php
require_once __DIR__ . '/includes/public.php';

$pets = public_pets();
$categories = db_all('SELECT name FROM categories ORDER BY name');
$selectedType = $_GET['type'] ?? 'All';
if ($selectedType !== 'All' && !in_array($selectedType, array_column($categories, 'name'), true)) {
    $selectedType = 'All';
}

$maxMonths = 12;
foreach ($pets as $p) {
    $maxMonths = max($maxMonths, age_months($p['birth_date']));
}
$maxYears = (int) ceil($maxMonths / 12);
$sizes = ['Small', 'Medium', 'Large'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Browse Pets</title>
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
  .nav-links a.active { background: rgba(46,38,46,0.06); color: #2E2A33; font-weight: 600; }
  .nav-links a:hover { background: rgba(46,38,46,0.06); color: #2E2A33; }
  .nav-right { display: flex; align-items: center; gap: 18px; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: #C1663C; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; cursor: pointer; }

  .container { max-width: 1280px; margin: 0 auto; padding: 40px 32px 88px; display: grid; grid-template-columns: 250px 1fr; gap: 28px; align-items: start; }

  .filters { background: rgba(255,255,255,0.5); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.75); border-radius: 18px; padding: 24px; position: sticky; top: 88px; box-shadow: 0 10px 30px rgba(46,30,35,0.06); }
  .filters h3 { font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 600; margin: 0 0 20px; }
  .filter-group { margin-bottom: 22px; }
  .filter-group:last-child { margin-bottom: 0; }
  .filter-label { font-size: 11.5px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #8A8390; margin-bottom: 10px; }
  .pill-row { display: flex; flex-wrap: wrap; gap: 8px; }
  .pill { padding: 7px 13px; border-radius: 10px; font-size: 12.5px; font-weight: 600; cursor: pointer; background: rgba(255,255,255,0.6); color: #4A4450; user-select: none; }
  .pill.active { background: #C1663C; color: #fff; }
  .pill:hover:not(.active) { background: rgba(255,255,255,0.85); }
  .pill:focus-visible { outline: 3px solid rgba(193,102,60,0.3); outline-offset: 2px; }

  
  .label-row { display: flex; align-items: baseline; justify-content: space-between; }
  .age-readout { font-size: 12.5px; font-weight: 600; color: #2E2A33; letter-spacing: 0; text-transform: none; }
  .range { position: relative; height: 22px; margin: 4px 2px 0; }
  .range-track { position: absolute; left: 0; right: 0; top: 9px; height: 4px; border-radius: 4px; background: rgba(46,38,46,0.12); }
  .range-fill { position: absolute; top: 0; bottom: 0; border-radius: 4px; background: #C1663C; }
  .range input[type=range] { position: absolute; left: 0; top: 0; width: 100%; height: 22px; margin: 0; background: none; pointer-events: none; -webkit-appearance: none; appearance: none; }
  .range input[type=range]::-webkit-slider-runnable-track { background: transparent; height: 22px; }
  .range input[type=range]::-moz-range-track { background: transparent; }
  .range input[type=range]::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; width: 18px; height: 18px; margin-top: 2px; border-radius: 50%; background: #fff; border: 2px solid #C1663C; box-shadow: 0 2px 6px rgba(46,30,35,0.2); cursor: pointer; }
  .range input[type=range]::-moz-range-thumb { pointer-events: auto; width: 14px; height: 14px; border-radius: 50%; background: #fff; border: 2px solid #C1663C; box-shadow: 0 2px 6px rgba(46,30,35,0.2); cursor: pointer; }
  .range input[type=range]:focus-visible::-webkit-slider-thumb { outline: 3px solid rgba(193,102,60,0.3); }
  .range-scale { display: flex; justify-content: space-between; font-size: 11px; color: #8A8390; margin-top: 6px; }

  .results-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 20px; }
  .results-head h2 { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 22px; letter-spacing: -0.01em; margin: 0; }

  .pet-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
  .pet-card { display: block; color: inherit; background: rgba(255,255,255,0.5); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid rgba(255,255,255,0.75); border-radius: 16px; overflow: hidden; box-shadow: 0 8px 22px rgba(46,30,35,0.08); cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
  .pet-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(46,30,35,0.12); }
  .pet-card[hidden] { display: none; }
  .pet-photo { width: 100%; height: 170px; position: relative; background-size: cover; background-position: center; }
  .pet-badge { position: absolute; top: 10px; right: 10px; display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 100px; font-size: 10.5px; font-weight: 600; }
  .pet-info { padding: 15px 16px; }
  .pet-row { display: flex; align-items: baseline; justify-content: space-between; }
  .pet-name { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 15px; letter-spacing: -0.005em; color: #2A262E; }
  .pet-age { font-size: 12px; color: #8A8390; }
  .pet-breed { font-size: 12.5px; color: #66606C; margin-top: 4px; }

  @media (max-width: 900px) {
    .container { grid-template-columns: 1fr; }
    .filters { position: static; }
    .pet-grid { grid-template-columns: repeat(2, 1fr); }
  }
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
          <a href="browse.php" class="active">Browse Pets</a>
        </div>
        <?= public_nav_cta() ?>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="filters">
      <h3>Filters</h3>
      <div class="filter-group">
        <div class="filter-label">Type</div>
        <div class="pill-row" data-filter="category">
          <div class="pill <?= $selectedType === 'All' ? 'active' : '' ?>" data-value="All" role="button" tabindex="0">All</div>
          <?php foreach ($categories as $c): ?>
            <div class="pill <?= $selectedType === $c['name'] ? 'active' : '' ?>" data-value="<?= e($c['name']) ?>" role="button" tabindex="0"><?= e($c['name']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="filter-group">
        <div class="filter-label label-row"><span>Age</span><span class="age-readout" id="ageReadout"></span></div>
        <div class="range" data-max="<?= $maxYears ?>">
          <div class="range-track"><div class="range-fill" id="ageFill"></div></div>
          <input type="range" id="ageMin" min="0" max="<?= $maxYears ?>" step="0.5" value="0" aria-label="Minimum age in years">
          <input type="range" id="ageMax" min="0" max="<?= $maxYears ?>" step="0.5" value="<?= $maxYears ?>" aria-label="Maximum age in years">
        </div>
        <div class="range-scale"><span>0</span><span><?= $maxYears ?> yrs</span></div>
      </div>
      <div class="filter-group">
        <div class="filter-label">Size</div>
        <div class="pill-row" data-filter="size">
          <div class="pill active" data-value="All" role="button" tabindex="0">All</div>
          <?php foreach ($sizes as $s): ?>
            <div class="pill" data-value="<?= $s ?>" role="button" tabindex="0"><?= $s ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div>
      <div class="results-head">
        <h2 id="resultCount"><?= count($pets) ?> pets available</h2>
      </div>
      <div class="pet-grid" id="petGrid">
        <?php foreach ($pets as $p): ?>
          <?= pet_card($p) ?>
        <?php endforeach; ?>
      </div>
      <p class="empty-note" id="emptyNote" <?= $pets ? 'hidden' : '' ?>>No pets match these filters. Try widening the age range or choosing "All".</p>
    </div>
  </div>

  <?= dev_footer() ?>

  <script src="assets/js/browse.js"></script>
</body>
</html>
