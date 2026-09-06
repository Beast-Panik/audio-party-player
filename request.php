<?php

require __DIR__ . '/bootstrap.php';

use App\Csrf;
use App\GuestIdentity;
use App\Repositories\SettingRepository;

$settings = new SettingRepository();
// Party ist "offline" geschaltet (siehe api/live_status.php, Sidebar-
// Schalter) - Seite zeigt dann nur noch eine leere "Offline"-Anzeige, ganz
// ohne Gaeste-Interaktion/Cookies/Abfragen.
if ($settings->get('app_live', '1') === '0') {
    require __DIR__ . '/templates/offline.php';
    exit;
}

// Legt bei Bedarf gleich beim Seitenaufruf das Wiedererkennungs-Cookie an
// (nicht erst beim ersten Wunsch), damit das Limit zuverlaessig greift.
GuestIdentity::id();
$pageTitle = 'Musikwunsch';
$csrfToken = Csrf::token();
$previewSeconds = (int) $settings->get('preview_seconds', '20');

require __DIR__ . '/templates/public_header.php';
?>
<script>
  window.APP_CSRF = <?= json_encode($csrfToken) ?>;
  window.APP_BASE = <?= json_encode(app_url('')) ?>;
  window.APP_PREVIEW_SECONDS = <?= (int) $previewSeconds ?>;
</script>

<div class="app-now-playing-row">
  <div class="app-ticker" id="now-playing-ticker" hidden><span class="app-ticker__text" id="now-playing-text"></span></div>
  <button type="button" class="app-heart-btn" id="btn-react" hidden title="Gefällt mir!">❤ <span id="react-count">0</span></button>
</div>
<p class="lede">Song gesucht? Einfach suchen und wünschen – der DJ sieht deinen Wunsch sofort.</p>

<div class="pnk-card" id="name-card" style="margin-bottom:16px;">
  <label class="pnk-label" for="guest-name">Dein Name</label>
  <div style="display:flex; gap:8px;">
    <input class="pnk-input" type="text" id="guest-name" placeholder="z.B. Alex" maxlength="60" required style="flex:1;">
    <button class="pnk-btn pnk-btn--primary" id="btn-confirm-name" type="button">Weiter</button>
  </div>
</div>

<div id="guest-greeting" class="pnk-text-muted" style="margin-bottom:16px;" hidden></div>

<div class="pnk-card" id="search-card" style="margin-bottom:16px;" hidden>
  <label class="pnk-label" for="search-input">Song suchen</label>
  <input class="pnk-input pnk-search" type="text" id="search-input" placeholder="Titel, Interpret, Album oder Jahr…">
</div>

<div id="feedback"></div>

<div class="pnk-card" id="results-card" style="margin-bottom:16px;" hidden>
  <div class="pnk-card__header"><span class="pnk-card__title" id="results-title">Inspiration</span></div>
  <div id="results-list"></div>
</div>

<div class="pnk-card">
  <div class="pnk-card__header"><span class="pnk-card__title">Aktuelle Wunschliste</span></div>
  <div id="queue-list"><div class="app-empty">Lade…</div></div>
</div>

<audio id="preview-audio" hidden></audio>

<?php require __DIR__ . '/templates/public_footer.php'; ?>
