<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;

Auth::requireLogin();

$pageTitle = 'Player';
$activeNav = 'player';
require __DIR__ . '/templates/admin_header.php';
?>

<div class="pnk-flex pnk-justify-between pnk-items-center" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;">Player</h2>
  <span class="pnk-badge pnk-badge--accent" id="pending-badge">0 offene Wünsche</span>
</div>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header">
    <span class="pnk-card__title">Playlist</span>
    <span class="pnk-text-muted" id="playlist-count" style="font-size:12px;"></span>
  </div>
  <div id="playlist-list"><div class="app-empty">Lade Playlist…</div></div>
</div>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header">
    <span class="pnk-card__title">Wunschliste</span>
    <a class="pnk-btn pnk-btn--ghost pnk-btn--sm" href="<?= app_url('admin/requests.php') ?>">Alle anzeigen</a>
  </div>
  <div id="queue-list"><div class="app-empty">Lade Wünsche…</div></div>
</div>

<div class="pnk-card" style="margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <div style="font-weight:600;">Auto-DJ</div>
    <div class="pnk-text-muted" style="font-size:12.5px; max-width:60ch;">
      Wenn aktiv: Gast-Wünsche landen direkt in der Playlist (keine manuelle Freigabe
      noetig), der Player spielt die Playlist automatisch durch und sie wird bei
      weniger als 3 Songs automatisch mit noch nicht gespielten Tracks aufgefüllt.
    </div>
  </div>
  <label class="pnk-field-row" style="cursor:pointer;">
    <input class="pnk-checkbox" type="checkbox" id="auto-dj-toggle">
    <span id="auto-dj-label">Aus</span>
  </label>
</div>

<div class="pnk-card">
  <div class="pnk-card__header">
    <span class="pnk-card__title">Bibliothek</span>
    <span class="pnk-text-muted" id="track-count" style="font-size:12px;"></span>
  </div>
  <input class="pnk-input pnk-search" id="search-input" placeholder="Titel, Interpret oder Album durchsuchen…" style="margin-bottom:12px;">
  <div class="app-track-list" id="track-list"><div class="app-empty">Lade Bibliothek…</div></div>
</div>

<?php require __DIR__ . '/templates/admin_footer.php'; ?>
