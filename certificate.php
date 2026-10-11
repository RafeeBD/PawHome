<?php

require_once __DIR__ . '/includes/auth.php';

$me = require_role('adopter', 'shelter', 'admin');
$a = db_one('SELECT a.*, p.name AS pet_name, p.breed, p.photo_path, p.shelter_id, c.name AS category, s.name AS shelter_name, h.completed_at
             FROM applications a JOIN pets p ON p.pet_id = a.pet_id JOIN categories c ON c.category_id = p.category_id
             JOIN users s ON s.user_id = p.shelter_id JOIN handovers h ON h.application_id = a.application_id
             WHERE a.application_id = ? AND a.status = "Completed"', [(int) ($_GET['id'] ?? 0)]);
if (!$a || !($me['role'] === 'admin' || (int) $a['adopter_id'] === $me['user_id'] || (int) $a['shelter_id'] === $me['user_id'])) {
    http_response_code(404);
    exit('Certificate not found.');
}
$certNo = sprintf('PH-%s-%05d', date('Y', strtotime($a['completed_at'])), $a['application_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate of Adoption — <?= e($a['pet_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  @page { size: A4 landscape; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter', sans-serif; color: #2E2A33; background: #F5EAE3; }
  .sheet { max-width: 960px; margin: 32px auto; background: #FFFDFB; border-radius: 22px; padding: 22px; box-shadow: 0 20px 50px rgba(46,30,35,0.1); }
  .frame { border: 2px dashed rgba(193,102,60,0.45); border-radius: 16px; padding: 48px 40px; text-align: center; }
  .brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 26px; }
  .brand img { width: 42px; height: 42px; border-radius: 50%; }
  .brand span { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 20px; }
  .kicker { font-size: 12px; font-weight: 700; letter-spacing: 0.14em; color: #8A8390; text-transform: uppercase; margin-bottom: 26px; }
  .photo { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 22px; background: center/cover no-repeat; border: 4px solid #DCEEE2; }
  .pet { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 38px; margin-bottom: 6px; }
  .sub { font-size: 14px; color: #66606C; margin-bottom: 18px; }
  .line { font-size: 17px; color: #46414B; margin-bottom: 8px; }
  .adopter { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 26px; color: #C1663C; margin-bottom: 28px; }
  .meta { display: flex; justify-content: center; gap: 48px; font-size: 13px; color: #66606C; }
  .meta strong { display: block; color: #2E2A33; font-size: 14px; margin-top: 4px; }
  .actions { text-align: center; margin: 0 0 32px; }
  .actions button { padding: 13px 26px; border: none; border-radius: 12px; background: #C1663C; color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; font-family: inherit; }
  @media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; max-width: none; } .actions, .site-dev-footer { display: none; } }
</style>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="sheet">
    <div class="frame">
      <div class="brand"><img src="assets/logo.png" alt=""><span>PawHome</span></div>
      <div class="kicker">Certificate of Adoption</div>
      <div class="photo" style="background-image:url('<?= e(pet_photo_url($a['photo_path'])) ?>')"></div>
      <div class="pet"><?= e($a['pet_name']) ?></div>
      <div class="sub"><?= e($a['breed']) ?> · <?= e($a['category']) ?></div>
      <div class="line">has found a forever home with</div>
      <div class="adopter"><?= e($a['full_name']) ?></div>
      <div class="meta">
        <div>Issued by<strong><?= e($a['shelter_name']) ?></strong></div>
        <div>Adoption date<strong><?= fmt_date($a['completed_at']) ?></strong></div>
        <div>Certificate no.<strong><?= e($certNo) ?></strong></div>
      </div>
    </div>
  </div>
  <div class="actions"><button type="button" onclick="window.print()">Save as PDF / Print</button></div>

  <?= dev_footer() ?>

  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
