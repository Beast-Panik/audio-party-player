<?php

require __DIR__ . '/bootstrap.php';

use App\Csrf;
use App\GuestIdentity;
use App\Repositories\SettingRepository;

// Legt bei Bedarf gleich beim Seitenaufruf das Wiedererkennungs-Cookie an
// (nicht erst beim ersten Wunsch), damit das Limit zuverlaessig greift.
GuestIdentity::id();

$settings = new SettingRepository();
$appName = $settings->get('app_name', 'Party Player - pan1k.de');
$pageTitle = 'Musikwunsch';
$csrfToken = Csrf::token();

require __DIR__ . '/templates/public_header.php';
?>
<script>window.APP_CSRF = <?= json_encode($csrfToken) ?>; window.APP_BASE = <?= json_encode(app_url('')) ?>;</script>

<h1><?= htmlspecialchars($appName, ENT_QUOTES) ?> 🎶</h1>
<p class="lede">Song gesucht? Einfach suchen und wünschen – der DJ sieht deinen Wunsch sofort.</p>

<div class="pnk-card" style="margin-bottom:16px;">
  <label class="pnk-label" for="guest-name">Dein Name (optional)</label>
  <input class="pnk-input" type="text" id="guest-name" placeholder="z.B. Alex" maxlength="60" style="margin-bottom:12px;">

  <label class="pnk-label" for="search-input">Song suchen</label>
  <input class="pnk-input pnk-search" type="text" id="search-input" placeholder="Titel, Interpret oder Album…" autofocus>
</div>

<div id="feedback"></div>

<div class="pnk-card" id="results-card" style="margin-bottom:16px; display:none;">
  <div class="pnk-card__header"><span class="pnk-card__title">Ergebnisse</span></div>
  <div id="results-list"></div>
</div>

<div class="pnk-card">
  <div class="pnk-card__header"><span class="pnk-card__title">Aktuelle Wunschliste</span></div>
  <div id="queue-list"><div class="app-empty">Lade…</div></div>
</div>

<?php require __DIR__ . '/templates/public_footer.php'; ?>
