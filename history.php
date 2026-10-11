<?php

require_once __DIR__ . '/includes/auth.php';

$me = require_role('admin');
$a = db_one('SELECT a.*, p.name AS pet_name, p.breed, p.photo_path, c.name AS category, s.name AS shelter_name,
             h.scheduled_at, h.location AS h_location, h.notes AS h_notes, h.status AS h_status, h.completed_at
             FROM applications a JOIN pets p ON p.pet_id = a.pet_id JOIN categories c ON c.category_id = p.category_id
             JOIN users s ON s.user_id = p.shelter_id LEFT JOIN handovers h ON h.application_id = a.application_id
             WHERE a.application_id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$a) {
    flash('That adoption record could not be found.');
    redirect('admin.php');
}
$badge = ['Completed' => 'badge-completed', 'Approved' => 'badge-approved', 'Pending' => 'badge-pending', 'Rejected' => 'badge-rejected', 'Cancelled' => 'badge-returned'][$a['status']];
$info = fn(string $label, string $value, bool $wide = false) =>
    '<div class="info' . ($wide ? ' span-2' : '') . '"><div class="info-label">' . e($label) . '</div><div class="info-value">' . e($value) . '</div></div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — <?= e($a['pet_name']) ?> Adoption</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
<style>
  .document-preview { display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #8a8390; }
</style>
</head>
<body>
  <header class="nav">
    <div class="nav-inner">
      <a class="brand" href="index.php"><img src="assets/logo.png" alt="PawHome logo"><span>PawHome</span></a>
      <div class="nav-actions"><?= logout_button('logout') ?><div class="avatar"><?= e(initials($me['name'])) ?></div></div>
    </div>
  </header>
  <main class="container detail-page">
    <a class="back-link" href="admin.php">← Back to admin dashboard</a>
    <article class="detail-card">
      <div class="detail-heading with-photo">
        <img src="<?= e(pet_photo_url($a['photo_path'])) ?>" alt="<?= e($a['pet_name']) ?>">
        <div class="detail-title-wrap"><h1 class="detail-title"><?= e($a['pet_name']) ?> → <?= e($a['full_name']) ?></h1><div class="detail-sub"><?= e($a['breed']) ?> · <?= e($a['category']) ?> · Application <?= fmt_date($a['submitted_at']) ?></div></div>
        <span class="badge <?= $badge ?>"><?= e($a['status']) ?></span>
      </div>
      <div class="info-grid">
        <?= $info('Shelter', $a['shelter_name']) ?>
        <?= $info('Adopter email', $a['email']) ?>
        <?= $info('Adopter phone', $a['phone']) ?>
        <?= $info('Handover date', $a['scheduled_at'] ? fmt_datetime($a['scheduled_at']) . ($a['h_status'] !== 'Scheduled' ? ' (' . strtolower($a['h_status']) . ')' : '') : 'Not scheduled') ?>
        <?= $info('Handover location', $a['h_location'] ?? '—', true) ?>
        <?= $info('Household', $a['household']) ?>
        <?= $info('Experience', $a['experience']) ?>
        <?= $info('Message from adopter', $a['message'], true) ?>
        <?= $info('Notes', $a['h_notes'] ?: '—', true) ?>
      </div>
      <p class="section-label">Submitted documents</p>
      <div class="documents two">
        <?php if ($a['document_name']): ?>
          <article class="document"><div class="document-preview" aria-hidden="true">&#128196;</div><div class="document-name"><?= e($a['document_name']) ?></div><div class="document-actions"><a class="btn" href="file.php?kind=application&amp;id=<?= $a['application_id'] ?>" target="_blank" rel="noopener">Preview</a><a class="btn btn-reject" href="file.php?kind=application&amp;id=<?= $a['application_id'] ?>&amp;download=1">Download</a></div></article>
        <?php else: ?>
          <p class="empty-note">No documents were uploaded.</p>
        <?php endif; ?>
      </div>
    </article>
  </main>

  <?= dev_footer() ?>

</body>
</html>
