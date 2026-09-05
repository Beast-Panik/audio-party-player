<?php
/**
 * Erwartet (optional): $pageTitle, $activeNav
 * $activeNav eines von: dashboard, library, requests, users, settings, player
 */
use App\Auth;
use App\Repositories\PlaylistRepository;
use App\Repositories\SettingRepository;

$pageTitle = $pageTitle ?? 'Party Player';
$activeNav = $activeNav ?? '';
$settingsRepo = new SettingRepository();
$appName = $settingsRepo->get('app_name', 'Party Player - pan1k.de');
$hasLockPin = (bool) $settingsRepo->get('lock_pin_hash');
$tickerEnabled = $settingsRepo->get('ticker_enabled', '0') === '1';
$countdownEnabled = $settingsRepo->get('countdown_enabled', '0') === '1';
$playlistCountForNav = Auth::isLoggedIn() ? (new PlaylistRepository())->count() : 0;

if (!function_exists('nav_item')) {
    function nav_item(string $key, string $href, string $label, string $active, string $icon = ''): string
    {
        $cls = 'pnk-nav-item app-nav-btn' . ($key === $active ? ' is-active' : '');
        $iconHtml = $icon !== '' ? '<span class="app-nav-icon" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES) . '</span>' : '';
        return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '">' . $iconHtml . '<span class="app-nav-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span></a>';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
// Vor dem ersten Rendern gespeichertes Theme + Sidebar-Zustand anwenden,
// damit es beim Laden nicht kurz aufblitzt (falsches Theme/Sidebar-Breite).
(function () {
  try {
    var theme = localStorage.getItem('pnk-theme');
    if (theme) document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {}
  try {
    if (localStorage.getItem('app_sidebar_collapsed') === '1') {
      document.documentElement.classList.add('app-sidebar-preload-collapsed');
    }
  } catch (e) {}
})();
</script>
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> · <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app" style="display:block;">
<div class="pnk-app app-shell" id="app-shell">
  <div class="app-sidebar-backdrop" id="sidebar-backdrop"></div>

  <header class="pnk-topbar app-topbar">
    <div class="app-topbar-left">
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon app-sidebar-toggle" id="sidebar-toggle" title="Menü ein-/ausklappen" aria-label="Menü ein-/ausklappen">☰</button>
      <div class="app-topbar-brand"><span class="dot"></span> <span class="app-topbar-brand__text"><?= htmlspecialchars($appName, ENT_QUOTES) ?></span></div>
    </div>
    <div class="app-topbar-center" id="countdown-wrap" hidden>
      <div class="app-countdown" id="countdown-value">--:--</div>
    </div>
    <div class="app-topbar-right">
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="theme-toggle" type="button" title="Theme wechseln" aria-label="Theme wechseln">🌙</button>
      <?php if (Auth::isLoggedIn()): ?>
        <span class="pnk-text-muted app-topbar-user" style="font-size:13px;"><?= htmlspecialchars(Auth::username() ?? '', ENT_QUOTES) ?></span>
        <a class="pnk-btn pnk-btn--ghost pnk-btn--sm" href="<?= app_url('logout.php') ?>">Abmelden</a>
      <?php endif; ?>
    </div>
  </header>

  <div class="app-ticker" id="ticker-wrap" hidden>
    <div class="app-ticker__track" id="ticker-track"><span id="ticker-text"></span></div>
  </div>

  <?php if (Auth::isLoggedIn()): ?>
  <div class="app-player-bar" id="nowplaying" hidden>
    <audio id="audio-el" preload="auto"></audio>
    <audio id="audio-el-b" preload="auto"></audio>
    <div class="app-player-bar__controls">
      <button class="pnk-btn app-player-btn" id="btn-prev" title="Zurueck (Anfang)">⏮</button>
      <button class="pnk-btn pnk-btn--primary app-player-btn" id="btn-playpause" title="Play/Pause">▶</button>
      <button class="pnk-btn app-player-btn" id="btn-next" title="Naechster in der Playlist">⏭</button>
    </div>
    <div class="app-player-bar__meta">
      <div class="app-player-bar__title" id="np-title">-</div>
      <div class="app-player-bar__artist" id="np-artist">-</div>
    </div>
    <div class="app-player-bar__progress">
      <span id="np-current">0:00</span>
      <input type="range" id="np-seek" min="0" max="100" value="0" step="0.1">
      <span id="np-duration">0:00</span>
    </div>
    <div class="app-player-bar__next">
      <span class="pnk-text-muted">Als nächstes:</span>
      <span id="np-next">-</span>
    </div>
  </div>
  <?php endif; ?>

  <aside class="pnk-sidebar app-sidebar" id="sidebar">
    <button class="app-sidebar-collapse-toggle" id="sidebar-collapse-toggle" title="Menü einklappen" aria-label="Menü einklappen">‹</button>
    <h6 class="app-sidebar-heading">Party</h6>
    <nav class="pnk-nav">
      <div class="app-nav-item-wrap">
        <?= nav_item('player', app_url('player.php'), 'Player', $activeNav, '🎧') ?>
        <span class="app-nav-badge" id="nav-playlist-badge"<?= $playlistCountForNav > 0 ? '' : ' hidden' ?>><?= $playlistCountForNav ?></span>
      </div>
      <?= nav_item('requests', app_url('admin/requests.php'), 'Wunschliste', $activeNav, '🎶') ?>
    </nav>
    <h6 class="app-sidebar-heading">Verwaltung</h6>
    <nav class="pnk-nav">
      <?= nav_item('dashboard', app_url('admin/index.php'), 'Uebersicht', $activeNav, '📊') ?>
      <?= nav_item('library', app_url('admin/library.php'), 'Bibliothek', $activeNav, '💿') ?>
      <?= nav_item('users', app_url('admin/users.php'), 'Benutzer', $activeNav, '👤') ?>
      <?= nav_item('settings', app_url('admin/settings.php'), 'Einstellungen', $activeNav, '⚙️') ?>
    </nav>

    <?php if (Auth::isLoggedIn()): ?>
    <div class="app-sidebar-bottom">
      <?php if ($hasLockPin): ?>
        <button class="pnk-nav-item app-nav-btn app-lock-nav-btn" id="btn-lock" type="button" title="Player sperren">
          <span class="app-nav-icon" aria-hidden="true">🔒</span><span class="app-nav-label">Player sperren</span>
        </button>
      <?php else: ?>
        <a class="pnk-nav-item app-nav-btn app-lock-nav-btn" href="<?= app_url('admin/settings.php') ?>" title="PIN einrichten, um den Player sperren zu koennen">
          <span class="app-nav-icon" aria-hidden="true">🔑</span><span class="app-nav-label">PIN einrichten</span>
        </a>
      <?php endif; ?>
      <button class="app-sidebar-version" id="btn-about" type="button" title="Über diese App">v<?= htmlspecialchars(ltrim(APP_VERSION, 'v')) ?></button>
    </div>
    <?php endif; ?>
  </aside>

  <div class="pnk-modal-backdrop" id="about-modal-backdrop" hidden>
    <div class="pnk-modal" style="width:480px;">
      <div class="pnk-modal__header">
        <span class="pnk-card__title">Über diese App</span>
        <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="about-modal-close" type="button" aria-label="Schließen">✕</button>
      </div>
      <div id="about-modal-body"><div class="app-empty">Lade…</div></div>
      <div class="pnk-modal__footer">
        <button class="pnk-btn" id="about-modal-close-2" type="button">Schließen</button>
      </div>
    </div>
  </div>

  <main class="pnk-main app-content" id="app-main" style="grid-area:main;">
