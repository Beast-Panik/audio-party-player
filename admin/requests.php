<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;

Auth::requireLogin();

$pageTitle = 'Wunschliste';
$activeNav = 'requests';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Wunschliste</h2>

<div class="pnk-tabs" id="status-tabs" style="margin-bottom:16px;">
  <div class="pnk-tab is-active" data-status="pending">Offen</div>
  <div class="pnk-tab" data-status="approved">Angenommen (Auto-DJ)</div>
  <div class="pnk-tab" data-status="played">Gespielt</div>
  <div class="pnk-tab" data-status="rejected">Abgelehnt</div>
  <div class="pnk-tab" data-status="">Alle</div>
  <div class="pnk-tab" data-status="guests">Gäste</div>
</div>

<div class="pnk-card" id="requests-card">
  <div id="requests-list"><div class="app-empty">Lade…</div></div>
</div>

<div class="pnk-card" id="guests-card" hidden>
  <p class="pnk-text-muted" style="font-size:12.5px; margin:0 0 12px;">
    Alle Gäste mit gültigem Namens-Lock (24 Std. nach der ersten Namenseingabe), mit verbleibendem
    Kontingent und Reset-Countdown. Auf einen Namen klicken zeigt den Wunsch-Verlauf. Über
    "Zurücksetzen" kann das Kontingent einer Person vorzeitig erneuert werden.
  </p>
  <div id="guests-list"><div class="app-empty">Lade…</div></div>
</div>

<div class="pnk-modal-backdrop" id="guest-history-backdrop" hidden>
  <div class="pnk-modal" style="width:480px;">
    <div class="pnk-modal__header">
      <span class="pnk-card__title" id="guest-history-title">Wunsch-Verlauf</span>
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="guest-history-close" type="button" aria-label="Schließen">✕</button>
    </div>
    <div id="guest-history-body"><div class="app-empty">Lade…</div></div>
    <div class="pnk-modal__footer">
      <button class="pnk-btn" id="guest-history-close-2" type="button">Schließen</button>
    </div>
  </div>
</div>

<script>window.APP_CSRF = <?= json_encode(Csrf::token()) ?>; window.APP_BASE = <?= json_encode(app_url('')) ?>;</script>
<script src="<?= app_url('assets/js/requests-admin.js') ?>"></script>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
