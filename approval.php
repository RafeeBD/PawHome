<?php

require_once __DIR__ . '/includes/auth.php';

$me = require_role('admin');
$approvalRole ??= 'shelter';
$isVet = $approvalRole === 'vet';

$u = db_one('SELECT u.*, ' . ($isVet
        ? 'vp.clinic, vp.licence_number, vp.qualification, vp.specialization, vp.years_experience, vp.bio AS about FROM users u JOIN vet_profiles vp ON vp.user_id = u.user_id'
        : 'sp.contact_person, sp.registration_number, sp.address, sp.description AS about FROM users u JOIN shelter_profiles sp ON sp.user_id = u.user_id')
    . ' WHERE u.user_id = ? AND u.role = ?', [(int) ($_GET['id'] ?? 0), $approvalRole]);
if (!$u) {
    flash('That registration could not be found.');
    redirect('admin.php');
}
$docs = db_all('SELECT document_id, original_name FROM user_documents WHERE user_id = ? ORDER BY document_id', [$u['user_id']]);
$badge = ['pending' => ['badge-pending', 'Pending'], 'active' => ['badge-approved', 'Approved'], 'rejected' => ['badge-rejected', 'Rejected']][$u['status']];
$info = fn(string $label, string $value, bool $wide = false) =>
    '<div class="info' . ($wide ? ' span-2' : '') . '"><div class="info-label">' . e($label) . '</div><div class="info-value">' . e($value) . '</div></div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — <?= e($u['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
<style>
  .decision-actions form { margin: 0; display: grid; }
  .document-preview { display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #8a8390; }
  .reviewed-note { margin-top: 22px; color: var(--muted); font-size: .82rem; }
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
      <div class="detail-heading">
        <div class="detail-title-wrap"><h1 class="detail-title"><?= e($u['name']) ?></h1><div class="detail-sub"><?= $isVet ? 'Veterinarian' : 'Shelter' ?> application · Submitted <?= fmt_date($u['created_at']) ?> at <?= fmt_time($u['created_at']) ?></div></div>
        <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
      </div>
      <p class="description"><?= nl2br(e($u['about'])) ?></p>
      <div class="info-grid">
        <?php if ($isVet): ?>
          <?= $info('Clinic / workplace', $u['clinic']) ?>
          <?= $info('Licence number', $u['licence_number']) ?>
          <?= $info('Qualification', $u['qualification']) ?>
          <?= $info('Specialization', $u['specialization']) ?>
          <?= $info('Years of experience', $u['years_experience'] . ($u['years_experience'] == 1 ? ' year' : ' years')) ?>
          <?= $info('Email', $u['email']) ?>
          <?= $info('Phone', $u['phone'], true) ?>
        <?php else: ?>
          <?= $info('Contact person', $u['contact_person']) ?>
          <?= $info('Registration number', $u['registration_number']) ?>
          <?= $info('Email', $u['email']) ?>
          <?= $info('Phone', $u['phone']) ?>
          <?= $info('Location', $u['address'], true) ?>
        <?php endif; ?>
      </div>
      <p class="section-label">Submitted documents</p>
      <div class="documents">
        <?php foreach ($docs as $d): ?>
          <article class="document"><div class="document-preview" aria-hidden="true">&#128196;</div><div class="document-name"><?= e($d['original_name']) ?></div><div class="document-actions"><a class="btn" href="file.php?kind=userdoc&amp;id=<?= $d['document_id'] ?>" target="_blank" rel="noopener">Preview</a><a class="btn btn-reject" href="file.php?kind=userdoc&amp;id=<?= $d['document_id'] ?>&amp;download=1">Download</a></div></article>
        <?php endforeach; ?>
        <?php if (!$docs): ?><p class="empty-note">No documents were uploaded.</p><?php endif; ?>
      </div>
      <?php if ($u['status'] === 'pending'): ?>
        <div class="decision-actions">
          <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><button type="submit" class="btn btn-approve btn-wide" data-confirm="Approve <?= e($u['name']) ?>?">Approve</button></form>
          <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="reject_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><button type="submit" class="btn btn-reject btn-wide" data-confirm="Reject <?= e($u['name']) ?>'s registration?">Reject</button></form>
        </div>
      <?php else: ?>
        <p class="reviewed-note"><?= $u['status'] === 'active' ? 'Approved' : 'Rejected' ?><?= $u['reviewed_at'] ? ' on ' . fmt_date($u['reviewed_at']) : '' ?>.</p>
      <?php endif; ?>
    </article>
  </main>

  <?= dev_footer() ?>

  <script src="assets/js/ui.js"></script>
</body>
</html>
