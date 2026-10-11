<?php
require_once __DIR__ . '/includes/auth.php';

$me = require_role('admin');
$catErrors = [];
$catOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_user' || $action === 'reject_user') {
        $u = db_one('SELECT user_id, name, role FROM users WHERE user_id = ? AND role IN ("shelter","vet") AND status = "pending"', [(int) ($_POST['user_id'] ?? 0)]);
        if (!$u) {
            flash('That registration was already reviewed.');
        } else {
            $approve = $action === 'approve_user';
            db_exec('UPDATE users SET status = ?, reviewed_at = NOW() WHERE user_id = ?', [$approve ? 'active' : 'rejected', $u['user_id']]);
            flash($u['name'] . ($approve ? ' was approved and can now sign in.' : '\'s registration was rejected.'));
        }
        redirect('admin.php');
    }

    if ($action === 'add_category') {
        $catOld = preg_replace('/\s+/', ' ', clean('name', 30));
        if (!preg_match("/^[\p{L}][\p{L} '\-]{1,29}$/u", $catOld)) {
            $catErrors['name'] = 'Category names must be 2–30 letters (spaces, apostrophes and hyphens allowed).';
        } elseif (db_value('SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?)', [$catOld])) {
            $catErrors['name'] = '"' . $catOld . '" already exists.';
        } else {
            $name = mb_convert_case($catOld, MB_CASE_TITLE);
            db_exec('INSERT INTO categories (name) VALUES (?)', [$name]);
            flash('Added the "' . $name . '" category. Shelters can now list ' . $name . ' pets.');
            redirect('admin.php#categories');
        }
    }

    if ($action === 'delete_category') {
        $cat = db_one('SELECT c.category_id, c.name, COUNT(p.pet_id) AS pets FROM categories c LEFT JOIN pets p ON p.category_id = c.category_id
                       WHERE c.category_id = ? GROUP BY c.category_id', [(int) ($_POST['category_id'] ?? 0)]);
        if (!$cat) {
            flash('That category no longer exists.');
        } elseif ($cat['pets'] > 0) {
            flash('"' . $cat['name'] . '" can\'t be removed while ' . $cat['pets'] . ' pet(s) use it.');
        } else {
            db_exec('DELETE FROM categories WHERE category_id = ?', [$cat['category_id']]);
            flash('Removed the "' . $cat['name'] . '" category.');
        }
        redirect('admin.php#categories');
    }
}

$stats = [
    'adoptions' => (int) db_value('SELECT COUNT(*) FROM applications WHERE status = "Completed"'),
    'shelters' => (int) db_value('SELECT COUNT(*) FROM users WHERE role = "shelter" AND status = "active"'),
    'vets' => (int) db_value('SELECT COUNT(*) FROM users WHERE role = "vet" AND status = "active"'),
    'pending' => (int) db_value('SELECT COUNT(*) FROM users WHERE role IN ("shelter","vet") AND status = "pending"'),
    'listed' => (int) db_value('SELECT COUNT(*) FROM pets WHERE availability = "Available"'),
    'thisMonth' => (int) db_value('SELECT COUNT(*) FROM handovers WHERE status = "Completed" AND completed_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'),
];

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
foreach (db_all('SELECT DATE_FORMAT(completed_at, "%Y-%m") AS ym, COUNT(*) AS n FROM handovers
                 WHERE status = "Completed" AND completed_at >= ? GROUP BY ym', [array_key_first($months) . '-01']) as $r) {
    if (isset($months[$r['ym']])) $months[$r['ym']] = (int) $r['n'];
}
$maxMonth = max(1, max($months));

$pending = db_all('SELECT user_id, name, role, created_at FROM users WHERE role IN ("shelter","vet") AND status = "pending" ORDER BY created_at');
$categories = db_all('SELECT c.category_id, c.name, COUNT(p.pet_id) AS pets FROM categories c LEFT JOIN pets p ON p.category_id = c.category_id
                      GROUP BY c.category_id ORDER BY c.name');
$history = db_all('SELECT a.application_id, a.full_name, a.status, a.submitted_at, p.name AS pet_name, s.name AS shelter_name, h.completed_at
                   FROM applications a JOIN pets p ON p.pet_id = a.pet_id JOIN users s ON s.user_id = p.shelter_id
                   LEFT JOIN handovers h ON h.application_id = a.application_id
                   ORDER BY COALESCE(h.completed_at, a.reviewed_at, a.submitted_at) DESC');
$statusBadge = ['Completed' => 'badge-completed', 'Approved' => 'badge-approved', 'Pending' => 'badge-pending', 'Rejected' => 'badge-rejected', 'Cancelled' => 'badge-returned'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PawHome — Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="brand-links.css">
<link rel="stylesheet" href="assets/app.css">
<style>
  .approval-actions form { margin: 0; display: grid; }
  .approval-actions form .btn { width: 100%; }
  .categories { margin-top: 18px; }
  .category-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .category-chip { display: inline-flex; align-items: center; gap: 8px; padding: 7px 8px 7px 13px; border-radius: 10px; background: var(--surface); font-size: .8rem; font-weight: 600; }
  .category-count { color: var(--soft); font-weight: 500; }
  .chip-remove { border: 0; background: none; color: var(--red); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0 4px; }
  .chip-remove:disabled { color: var(--soft); cursor: not-allowed; opacity: .5; }
  .category-form { display: flex; gap: 10px; }
  .category-form input { flex: 1; min-height: 38px; padding: 9px 12px; border: 1px solid var(--line); border-radius: 9px; background: rgba(255,255,255,.75); font: inherit; font-size: .82rem; outline: none; }
  .category-form input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(255,123,34,.12); }
  .category-hint { margin: 8px 0 0; color: var(--muted); font-size: .74rem; }
  .bar.zero { min-height: 24px; font-size: .8rem; opacity: .55; }
  .empty-note { font-size: .8rem; }
  @media (max-width: 650px) { .category-form { flex-direction: column; } }
</style>
</head>
<body>
  <?= flash_html() ?>
  <header class="nav">
    <div class="nav-inner">
      <a class="brand" href="index.php">
        <img src="assets/logo.png" alt="PawHome logo">
        <span>PawHome</span>
      </a>
      <div class="nav-actions">
        <?= logout_button('logout') ?>
        <div class="avatar" aria-label="Platform admin profile"><?= e(initials($me['name'])) ?></div>
      </div>
    </div>
  </header>

  <main id="app" class="container">
    <div class="page-header">
      <div><h1>Platform Admin</h1><p class="subhead">Oversight across shelters, vets, and adoptions.</p></div>
      <a class="btn btn-report" href="#reportModal">Generate Report</a>
    </div>
    <section class="stats" aria-label="Platform summary">
      <article class="stat"><div class="stat-value"><?= $stats['adoptions'] ?></div><div class="stat-label">Total adoptions</div></article>
      <article class="stat"><div class="stat-value"><?= $stats['shelters'] ?></div><div class="stat-label">Active shelters</div></article>
      <article class="stat"><div class="stat-value"><?= $stats['vets'] ?></div><div class="stat-label">Vet partners</div></article>
      <article class="stat"><div class="stat-value"><?= $stats['pending'] ?></div><div class="stat-label">Pending approvals</div></article>
    </section>
    <div class="overview">
      <section class="panel">
        <h2 class="panel-title">Monthly adoptions</h2>
        <div class="chart" aria-label="Completed adoptions over the last six months">
          <?php foreach ($months as $ym => $n): ?>
            <div class="chart-column"><div class="bar-wrap"><div class="bar <?= $n ? '' : 'zero' ?>" style="height:<?= $n ? round($n / $maxMonth * 100) : 0 ?>%" title="<?= $n ?> adoption<?= $n === 1 ? '' : 's' ?>"><?= $n ?></div></div><div class="month"><?= date('M', strtotime($ym . '-01')) ?></div></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="panel">
        <h2 class="panel-title">Pending approvals</h2>
        <div class="approval-list">
          <?php foreach ($pending as $u): ?>
            <article class="approval-card">
              <div class="approval-name"><?= e($u['name']) ?></div>
              <div class="approval-role"><?= $u['role'] === 'vet' ? 'Veterinarian application' : 'Shelter application' ?></div>
              <div class="approval-date">Submitted <?= fmt_date($u['created_at']) ?> at <?= fmt_time($u['created_at']) ?></div>
              <div class="approval-actions">
                <a class="btn" href="<?= $u['role'] === 'vet' ? 'vet-approval.php' : 'approval.php' ?>?id=<?= $u['user_id'] ?>">View Details</a>
                <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><button type="submit" class="btn btn-approve" data-confirm="Approve <?= e($u['name']) ?>? They will be able to sign in.">Approve</button></form>
                <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="reject_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><button type="submit" class="btn btn-reject" data-confirm="Reject <?= e($u['name']) ?>'s registration?">Reject</button></form>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if (!$pending): ?><p class="empty-note">No registrations waiting for approval.</p><?php endif; ?>
        </div>
      </section>
    </div>

    <section class="panel categories" id="categories">
      <h2 class="panel-title">Pet categories</h2>
      <div class="category-list">
        <?php foreach ($categories as $c): ?>
          <span class="category-chip"><?= e($c['name']) ?> <span class="category-count"><?= (int) $c['pets'] ?> pet<?= $c['pets'] == 1 ? '' : 's' ?></span>
            <form class="inline-form" method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?= $c['category_id'] ?>">
              <button type="submit" class="chip-remove" <?= $c['pets'] ? 'disabled title="In use by ' . (int) $c['pets'] . ' pet(s) — can\'t be removed"' : 'title="Remove ' . e($c['name']) . '" data-confirm="Remove the ' . e($c['name']) . ' category?"' ?> aria-label="Remove <?= e($c['name']) ?>">&times;</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
      <form class="category-form" method="post" action="admin.php#categories" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_category">
        <input type="text" name="name" maxlength="30" placeholder="New category, e.g. Bird" value="<?= e($catOld) ?>" aria-label="New category name" class="<?= $catErrors ? 'is-invalid' : '' ?>" required>
        <button type="submit" class="btn btn-orange">Add Category</button>
      </form>
      <?php if ($catErrors): ?><span class="field-error"><?= e($catErrors['name']) ?></span><?php endif; ?>
      <p class="category-hint">Categories appear as pet types on the Browse page filters and in the shelters' Add Pet form.</p>
    </section>

    <section class="panel history">
      <h2 class="panel-title">Adoption history</h2>
      <div class="history-head"><span>Pet → Adopter</span><span>Shelter</span><span>Date</span><span>Status</span></div>
      <div class="history-list">
        <?php foreach ($history as $h): ?>
          <a class="history-row" href="history.php?id=<?= $h['application_id'] ?>"><span class="history-name" data-label="Pet → Adopter"><?= e($h['pet_name']) ?> → <?= e($h['full_name']) ?></span><span class="history-muted" data-label="Shelter"><?= e($h['shelter_name']) ?></span><span class="history-muted" data-label="Date"><?= fmt_date($h['completed_at'] ?? $h['submitted_at']) ?></span><span class="badge <?= $statusBadge[$h['status']] ?>"><?= e($h['status']) ?></span></a>
        <?php endforeach; ?>
        <?php if (!$history): ?><p class="empty-note">No adoption applications yet.</p><?php endif; ?>
      </div>
    </section>
  </main>

  <?= dev_footer() ?>

  <div class="modal-backdrop" id="reportModal">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
      <a class="icon-btn" href="#" aria-label="Close report">×</a>
      <div class="report-sheet">
        <div class="report-kicker">Platform report — PDF preview</div>
        <h2 class="report-title" id="reportTitle">Adoption Summary, <?= date('F Y') ?></h2>
        <div class="report-row"><span>Total adoptions</span><strong><?= $stats['adoptions'] ?></strong></div>
        <div class="report-row"><span>Adoptions this month</span><strong><?= $stats['thisMonth'] ?></strong></div>
        <div class="report-row"><span>Pets available for adoption</span><strong><?= $stats['listed'] ?></strong></div>
        <div class="report-row"><span>Active shelters</span><strong><?= $stats['shelters'] ?></strong></div>
        <div class="report-row"><span>Vet partners</span><strong><?= $stats['vets'] ?></strong></div>
        <div class="report-row"><span>Pending approvals</span><strong><?= $stats['pending'] ?></strong></div>
      </div>
      <a class="btn btn-orange btn-wide report-download" id="downloadReport" href="#" onclick="window.print(); return false;">Download PDF</a>
    </section>
  </div>

  <script src="assets/js/ui.js"></script>
</body>
</html>
