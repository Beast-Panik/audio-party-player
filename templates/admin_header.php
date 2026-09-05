<?php
/**
 * Erwartet (optional): $pageTitle, $activeNav
 * $activeNav eines von: dashboard, library, requests, users, settings, player
 */
use App\Auth;
use App\Repositories\SettingRepository;

$pageTitle = $pageTitle ?? 'Party Player';
$activeNav = $activeNav ?? '';
$appName = (new SettingRepository())->get('app_name', 'Party Player - pan1k.de');

if (!function_exists('nav_item')) {
    function nav_item(string $key, string $href, string $label, string $active): string
    {
        $cls = 'pnk-nav-item' . ($key === $active ? ' is-active' : '');
        return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> · <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app" style="display:block;">
<div class="pnk-app" style="grid-template-columns:220px 1fr; grid-template-rows:auto 1fr; grid-template-areas:'sidebar topbar' 'sidebar main';">
  <header class="pnk-topbar" style="grid-column:1 / -1; justify-content:space-between;">
    <div class="app-topbar-brand"><span class="dot"></span> <?= htmlspecialchars($appName, ENT_QUOTES) ?></div>
    <div class="pnk-flex pnk-gap-2" style="display:flex; gap:8px; align-items:center; margin-left:auto;">
      <?php if (Auth::isLoggedIn()): ?>
        <span class="pnk-text-muted" style="font-size:13px;"><?= htmlspecialchars(Auth::username() ?? '', ENT_QUOTES) ?></span>
        <a class="pnk-btn pnk-btn--ghost pnk-btn--sm" href="<?= app_url('logout.php') ?>">Abmelden</a>
      <?php endif; ?>
    </div>
  </header>

  <aside class="pnk-sidebar">
    <h6 style="font-size:10.5px;text-transform:uppercase;letter-spacing:.09em;color:var(--pnk-text-dim);margin:4px 8px 4px;font-weight:600;">Party</h6>
    <nav class="pnk-nav">
      <?= nav_item('player', app_url('player.php'), 'Player', $activeNav) ?>
      <?= nav_item('requests', app_url('admin/requests.php'), 'Wunschliste', $activeNav) ?>
    </nav>
    <h6 style="font-size:10.5px;text-transform:uppercase;letter-spacing:.09em;color:var(--pnk-text-dim);margin:16px 8px 4px;font-weight:600;">Verwaltung</h6>
    <nav class="pnk-nav">
      <?= nav_item('dashboard', app_url('admin/index.php'), 'Uebersicht', $activeNav) ?>
      <?= nav_item('library', app_url('admin/library.php'), 'Bibliothek', $activeNav) ?>
      <?= nav_item('users', app_url('admin/users.php'), 'Benutzer', $activeNav) ?>
      <?= nav_item('settings', app_url('admin/settings.php'), 'Einstellungen', $activeNav) ?>
    </nav>
  </aside>

  <main class="pnk-main app-content" style="grid-area:main;">
