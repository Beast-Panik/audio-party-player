<?php

require __DIR__ . '/bootstrap.php';

use App\Repositories\SettingRepository;

// Oeffentliche, rein passive Anzeige-Seite fuer einen Bildschirm/Beamer bei
// der Party - kein Login, keine Interaktion, daher bewusst kein
// templates/public_header.php (dessen Topbar/Theme-Toggle/Lede-Text sind
// fuer die interaktive Wunsch-Seite gedacht). Theme kommt fest aus den
// Einstellungen (siehe admin/settings.php, Accordion "Wunsch-Seite & QR-Code"),
// da niemand einen Beamer manuell umschaltet.
$settings = new SettingRepository();
// Party ist "offline" geschaltet (siehe api/live_status.php, Sidebar-
// Schalter) - Seite zeigt dann nur noch eine leere "Offline"-Anzeige.
if ($settings->get('app_live', '1') === '0') {
    require __DIR__ . '/templates/offline.php';
    exit;
}
$appName = $settings->get('app_name', 'Party Player - pan1k.de');
$displayTheme = $settings->get('display_theme', 'dark') === 'light' ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="de" data-theme="<?= $displayTheme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Musikwünsche · <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app app-display-body">

<div class="app-display">
  <div class="app-display__hint">🎶 Musikwünsche über diesen QR-Code!</div>

  <div class="app-display__qr-card">
    <img src="<?= app_url('api/qr.php') ?>" alt="QR-Code zur Wunsch-Seite">
  </div>

  <div class="app-display__ticker" id="display-ticker">
    <div class="app-display__ticker-track" id="display-ticker-track">
      <span id="display-ticker-text">Gleich geht's los…</span>
    </div>
  </div>

  <div class="app-display__next" id="display-next">
    <span class="app-display__next-label">Als nächstes</span>
    <span class="app-display__next-title" id="display-next-title">–</span>
  </div>
</div>

<script>
  window.APP_BASE = <?= json_encode(app_url('')) ?>;
</script>
<script src="<?= app_url('assets/js/display.js') ?>"></script>
</body>
</html>
