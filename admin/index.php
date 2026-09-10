<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\PlayerSession;
use App\Repositories\LibraryRepository;
use App\Repositories\RequestRepository;
use App\Repositories\TrackRepository;
use App\Repositories\UserRepository;
use App\Util;

Auth::requireLogin();
Auth::requireAdmin();
PlayerSession::requireMasterOrRedirect();

$pageTitle = 'Uebersicht';
$activeNav = 'dashboard';

$trackCount = (new TrackRepository())->countAll();
$libraries = (new LibraryRepository())->all();
$pendingCount = (new RequestRepository())->countPending();
$userCount = (new UserRepository())->count();

require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Uebersicht</h2>

<div class="app-stat-grid">
  <div class="app-stat">
    <div class="app-stat__value"><?= (int) $trackCount ?></div>
    <div class="app-stat__label">Songs in der Bibliothek</div>
  </div>
  <div class="app-stat">
    <div class="app-stat__value"><?= count($libraries) ?></div>
    <div class="app-stat__label">Bibliotheken (Verzeichnisse)</div>
  </div>
  <div class="app-stat">
    <div class="app-stat__value"><?= (int) $pendingCount ?></div>
    <div class="app-stat__label">Offene Musikwünsche</div>
  </div>
  <div class="app-stat">
    <div class="app-stat__value"><?= (int) $userCount ?></div>
    <div class="app-stat__label">Admin-Konten</div>
  </div>
</div>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header">
    <span class="pnk-card__title">Bibliotheken</span>
    <a class="pnk-btn pnk-btn--ghost pnk-btn--sm" href="<?= app_url('admin/library.php') ?>">Verwalten</a>
  </div>
  <?php if (empty($libraries)): ?>
    <div class="app-empty">Noch keine Bibliothek angelegt. <a href="<?= app_url('admin/library.php') ?>">Jetzt einrichten</a>.</div>
  <?php else: ?>
    <table class="pnk-table">
      <thead><tr><th>Name</th><th>Pfad</th><th>Songs</th><th>Zuletzt gescannt</th></tr></thead>
      <tbody>
        <?php foreach ($libraries as $lib): ?>
        <tr>
          <td><?= Util::e($lib['name']) ?></td>
          <td style="font-family:var(--pnk-font-mono, monospace); font-size:12px;"><?= Util::e($lib['path']) ?></td>
          <td><?= (int) $lib['track_count'] ?></td>
          <td><?= Util::e($lib['last_scanned_at'] ?? 'nie') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="pnk-card">
  <div class="pnk-card__header">
    <span class="pnk-card__title">Schnellzugriff</span>
  </div>
  <div style="display:flex; gap:12px; flex-wrap:wrap;">
    <a class="pnk-btn pnk-btn--primary" href="<?= app_url('player.php') ?>">Zum Player</a>
    <a class="pnk-btn" href="<?= app_url('admin/requests.php') ?>">Wunschliste (<?= (int) $pendingCount ?>)</a>
    <a class="pnk-btn" href="<?= app_url('admin/settings.php') ?>">QR-Code fuer Gäste</a>
  </div>
</div>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
