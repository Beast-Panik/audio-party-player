<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\PlayerSession;

Auth::requireLogin();
PlayerSession::requireMasterOrRedirect();

$pageTitle = 'Playlists';
$activeNav = 'playlists';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Playlists</h2>
<p class="pnk-text-muted" style="max-width:70ch;">
  Gespeicherte Playlists ("Sets") sind unabhaengig von der Live-Playlist im
  Player - eine benannte, wiederverwendbare Zusammenstellung von Tracks, die
  sich per Klick komplett an das Ende der aktuellen Player-Playlist anhaengen
  laesst.
</p>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header"><span class="pnk-card__title">Neue Playlist anlegen</span></div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <input class="pnk-input" type="text" id="new-playlist-name" placeholder="Name, z.B. Warmup-Set" style="flex:1; min-width:200px;">
    <button class="pnk-btn pnk-btn--primary" id="btn-create-playlist" type="button">Anlegen</button>
  </div>
</div>

<div class="pnk-card" id="playlists-list-card">
  <div class="pnk-card__header"><span class="pnk-card__title">Gespeicherte Playlists</span></div>
  <div id="saved-playlists-list"><div class="app-empty">Lade…</div></div>
</div>

<div class="pnk-modal-backdrop" id="playlist-editor-backdrop" hidden>
  <div class="pnk-modal" style="width:640px; max-width:92vw;">
    <div class="pnk-modal__header">
      <input class="pnk-input" type="text" id="playlist-editor-name" style="font-weight:600; flex:1; margin-right:10px;">
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="playlist-editor-close" type="button" aria-label="Schließen">✕</button>
    </div>
    <div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
      <button class="pnk-btn pnk-btn--primary pnk-btn--sm" id="btn-load-into-player" type="button">▶ In Player-Playlist laden</button>
      <button class="pnk-btn pnk-btn--danger pnk-btn--sm" id="btn-delete-playlist" type="button">Playlist löschen</button>
    </div>
    <div class="pnk-text-muted" style="font-size:12px; margin-bottom:6px;">Tracks in dieser Playlist</div>
    <div id="playlist-editor-tracks" style="max-height:240px; overflow-y:auto; margin-bottom:16px; border:1px solid var(--pnk-border); border-radius:var(--pnk-radius);"></div>
    <div class="pnk-text-muted" style="font-size:12px; margin-bottom:6px;">Track hinzufügen</div>
    <input class="pnk-input" type="text" id="playlist-editor-search" placeholder="Titel oder Interpret durchsuchen…" style="margin-bottom:8px;">
    <div class="app-jumpbar" id="playlist-editor-jump-bar" style="margin-bottom:8px;"></div>
    <div id="playlist-editor-search-results" style="max-height:240px; overflow-y:auto;"></div>
  </div>
</div>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
