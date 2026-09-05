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
</div>

<div class="pnk-card">
  <div id="requests-list"><div class="app-empty">Lade…</div></div>
</div>

<script>window.APP_CSRF = <?= json_encode(Csrf::token()) ?>; window.APP_BASE = <?= json_encode(app_url('')) ?>;</script>
<script src="<?= app_url('assets/js/requests-admin.js') ?>"></script>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
