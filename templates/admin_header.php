<?php
/**
 * Erwartet (optional): $pageTitle, $activeNav
 * $activeNav eines von: dashboard, library, requests, users, settings, player
 */
use App\Auth;
use App\Csrf;
use App\PlayerSession;
use App\Repositories\PlaylistRepository;
use App\Repositories\SettingRepository;

$pageTitle = $pageTitle ?? 'Party Player';
$activeNav = $activeNav ?? '';
$settingsRepo = new SettingRepository();
$appName = $settingsRepo->get('app_name', 'Party Player');
$hasLockPin = (bool) $settingsRepo->get('lock_pin_hash');
$appLive = $settingsRepo->get('app_live', '1') !== '0';
$tickerEnabled = $settingsRepo->get('ticker_enabled', '0') === '1';
$countdownEnabled = $settingsRepo->get('countdown_enabled', '0') === '1';
$countdownFontSize = (int) $settingsRepo->get('countdown_font_size', '22');
$playlistCountForNav = Auth::isLoggedIn() ? (new PlaylistRepository())->count() : 0;
// Master/Slave: siehe PlayerSession - jede Admin-Seite haelt beim Laden den
// Heartbeat der eigenen Master-Rolle frisch bzw. uebernimmt sie, falls sie
// gerade frei/verwaist ist. $isSlave steuert unten, welche Menuepunkte und
// Bedienelemente eine zweite gleichzeitig eingeloggte Session zu sehen bekommt.
$isSlave = Auth::isLoggedIn() && !PlayerSession::touch();

if (!function_exists('app_icon_paths')) {
    /**
     * Strichzeichnungs-Pfade fuer einfarbige Icons (stroke=currentColor)
     * statt bunter Emoji - gemeinsame Quelle fuer die Sidebar-Navigation
     * (nav_icon_svg) UND normale Buttons ausserhalb der Sidebar (btn_icon_svg),
     * damit derselbe Strich-Stil ueberall im Adminbereich verwendet wird.
     */
    function app_icon_paths(string $name): string
    {
        $icons = [
            'player' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="2" y="14" width="5" height="7" rx="2"/><rect x="17" y="14" width="5" height="7" rx="2"/>',
            'wishlist' => '<path d="M9 17V4l10-2v13"/><circle cx="6" cy="17" r="3"/><circle cx="16" cy="15" r="3"/>',
            'dashboard' => '<line x1="4" y1="20" x2="4" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="20" y1="20" x2="20" y2="14"/>',
            'library' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>',
            'users' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/>',
            'settings' => '<line x1="5" y1="4" x2="5" y2="20"/><circle cx="5" cy="9" r="2" fill="currentColor" stroke="none"/><line x1="12" y1="4" x2="12" y2="20"/><circle cx="12" cy="15" r="2" fill="currentColor" stroke="none"/><line x1="19" y1="4" x2="19" y2="20"/><circle cx="19" cy="7" r="2" fill="currentColor" stroke="none"/>',
            'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'key' => '<circle cx="8" cy="14" r="4"/><path d="M11 11 20 2M17 5l2 2M14 8l2 2"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
            'upload' => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        ];
        return $icons[$name] ?? '';
    }
}

if (!function_exists('nav_icon_svg')) {
    function nav_icon_svg(string $name): string
    {
        return '<svg class="app-nav-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . app_icon_paths($name) . '</svg>';
    }
}

if (!function_exists('btn_icon_svg')) {
    /** Dieselben einfarbigen Strich-Icons wie in der Sidebar, aber fuer
     *  normale Buttons ausserhalb der Navigation - Farbe kommt hier direkt
     *  von der Textfarbe des jeweiligen Buttons (currentColor), keine
     *  eigene Akzentfarbe wie bei .app-nav-icon--*. */
    function btn_icon_svg(string $name): string
    {
        return '<svg class="app-btn-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . app_icon_paths($name) . '</svg>';
    }
}

if (!function_exists('nav_item')) {
    function nav_item(string $key, string $href, string $label, string $active, string $icon = ''): string
    {
        $cls = 'pnk-nav-item app-nav-btn' . ($key === $active ? ' is-active' : '');
        $iconHtml = $icon !== '' ? '<span class="app-nav-icon app-nav-icon--' . htmlspecialchars($icon, ENT_QUOTES) . '">' . nav_icon_svg($icon) . '</span>' : '';
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
    </div>
    <div class="app-topbar-right">
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="theme-toggle" type="button" title="Theme wechseln" aria-label="Theme wechseln">🌙</button>
      <?php if (Auth::isLoggedIn()): ?>
        <?php if (!$isSlave): ?>
        <button class="pnk-btn pnk-btn--flat app-topbar-user" id="btn-account" type="button" style="font-size:13px; padding:6px 8px;" title="Konto verwalten">
          <?= htmlspecialchars(Auth::username() ?? '', ENT_QUOTES) ?>
        </button>
        <?php else: ?>
        <span class="pnk-text-muted app-topbar-user" style="font-size:13px; padding:6px 8px;" title="Fernsteuerung - die Wiedergabe laeuft auf einem anderen, bereits angemeldeten Geraet">
          <?= htmlspecialchars(Auth::username() ?? '', ENT_QUOTES) ?> (Fernsteuerung)
        </span>
        <?php endif; ?>
        <form method="post" action="<?= app_url('logout.php') ?>" style="display:inline;">
          <?= Csrf::field() ?>
          <button class="pnk-btn pnk-btn--ghost pnk-btn--sm" type="submit">Abmelden</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <?php if (Auth::isLoggedIn()): ?>
  <div class="app-player-bar<?= $isSlave ? ' app-player-bar--slave' : '' ?>" id="nowplaying" data-slave="<?= $isSlave ? '1' : '0' ?>" hidden>
    <audio id="audio-el" preload="auto"></audio>
    <audio id="audio-el-b" preload="auto"></audio>
    <div class="app-player-bar__controls">
      <button class="pnk-btn app-player-btn" id="btn-prev" title="Zurueck (Anfang)">⏮</button>
      <?php if (!$isSlave): ?>
      <button class="pnk-btn pnk-btn--primary app-player-btn" id="btn-playpause" title="Play/Pause">▶</button>
      <?php endif; ?>
      <button class="pnk-btn app-player-btn" id="btn-next" title="Naechster in der Playlist">⏭</button>
    </div>
    <div class="app-player-bar__info">
      <div class="app-player-bar__meta">
        <div class="app-player-bar__title-row">
          <span class="app-player-bar__title" id="np-title">-</span>
          <span class="app-reaction-badge" id="np-reactions" hidden>❤ <span id="np-reactions-count">0</span></span>
        </div>
        <div class="app-player-bar__artist" id="np-artist">-</div>
      </div>
      <div class="app-player-bar__next">
        <span class="pnk-text-muted">Als nächstes:</span>
        <span id="np-next">-</span>
      </div>
    </div>
    <?php if ($isSlave): ?>
    <div class="pnk-text-muted" style="font-size:12px;">Fernsteuerung - die Wiedergabe laeuft auf einem anderen Geraet.</div>
    <?php else: ?>
    <div class="app-player-bar__progress">
      <span id="np-current">0:00</span>
      <input type="range" id="np-seek" min="0" max="100" value="0" step="0.1">
      <span id="np-duration">0:00</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <aside class="pnk-sidebar app-sidebar" id="sidebar">
    <button class="app-sidebar-collapse-toggle" id="sidebar-collapse-toggle" title="Menü einklappen" aria-label="Menü einklappen">‹</button>
    <div class="app-sidebar-ticker" id="ticker-wrap" hidden>
      <div class="app-ticker__track" id="ticker-track"><span id="ticker-text"></span></div>
    </div>
    <div class="app-sidebar-countdown" id="countdown-wrap" hidden style="--app-countdown-size: <?= $countdownFontSize ?>px;">
      <div class="app-countdown" id="countdown-value">--:--</div>
    </div>
    <h6 class="app-sidebar-heading">Party</h6>
    <nav class="pnk-nav">
      <div class="app-nav-item-wrap">
        <?= nav_item('player', app_url('player.php'), 'Player', $activeNav, 'player') ?>
        <span class="app-nav-badge" id="nav-playlist-badge"<?= $playlistCountForNav > 0 ? '' : ' hidden' ?>><?= $playlistCountForNav ?></span>
      </div>
      <?php if (!$isSlave): ?>
      <?= nav_item('requests', app_url('admin/requests.php'), 'Wunschliste', $activeNav, 'wishlist') ?>
      <?php endif; ?>
    </nav>
    <?php if (!$isSlave): ?>
    <h6 class="app-sidebar-heading">Verwaltung</h6>
    <nav class="pnk-nav">
      <?= nav_item('dashboard', app_url('admin/index.php'), 'Uebersicht', $activeNav, 'dashboard') ?>
      <?= nav_item('library', app_url('admin/library.php'), 'Bibliothek', $activeNav, 'library') ?>
      <?= nav_item('users', app_url('admin/users.php'), 'Benutzer', $activeNav, 'users') ?>
      <?= nav_item('settings', app_url('admin/settings.php'), 'Einstellungen', $activeNav, 'settings') ?>
    </nav>
    <?php endif; ?>

    <?php if (Auth::isLoggedIn() && !$isSlave): ?>
    <div class="app-sidebar-bottom">
      <button class="pnk-nav-item app-nav-btn app-live-toggle-btn <?= $appLive ? 'is-live' : 'is-offline' ?>" id="btn-live-toggle" type="button" title="<?= $appLive ? 'Party beenden (offline gehen) - setzt Gäste-/Wunschdaten zurück' : 'Party starten (live gehen) - setzt Gäste-/Wunschdaten zurück' ?>">
        <span class="app-live-toggle-dot" aria-hidden="true"></span>
        <span class="app-nav-label"><?= $appLive ? 'ON AIR' : 'OFFLINE' ?></span>
      </button>
      <?php if ($hasLockPin): ?>
        <button class="pnk-nav-item app-nav-btn app-lock-nav-btn" id="btn-lock" type="button" title="Player sperren">
          <span class="app-nav-icon app-nav-icon--lock"><?= nav_icon_svg('lock') ?></span><span class="app-nav-label">Player sperren</span>
        </button>
      <?php else: ?>
        <a class="pnk-nav-item app-nav-btn app-lock-nav-btn" href="<?= app_url('admin/settings.php') ?>" title="PIN einrichten, um den Player sperren zu koennen">
          <span class="app-nav-icon app-nav-icon--key"><?= nav_icon_svg('key') ?></span><span class="app-nav-label">PIN einrichten</span>
        </a>
      <?php endif; ?>
      <button class="app-sidebar-version" id="btn-about" type="button" title="Über diese App">v<?= htmlspecialchars(ltrim(APP_VERSION, 'v')) ?></button>
    </div>
    <?php elseif ($isSlave): ?>
    <div class="app-sidebar-bottom">
      <p class="pnk-text-muted" style="font-size:11.5px; padding:8px 10px; margin:0;">
        Fernsteuerung: ein anderes Geraet ist bereits als Player angemeldet und
        spielt die Musik ab. Hier koennen nur Titel uebersprungen und zur
        Playlist hinzugefuegt werden.
      </p>
    </div>
    <?php endif; ?>
  </aside>

  <div class="pnk-modal-backdrop" id="about-modal-backdrop" hidden>
    <div class="pnk-modal" style="width:480px;">
      <div class="pnk-modal__header">
        <span class="pnk-card__title">Über diese App</span>
        <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="about-modal-close" type="button" aria-label="Schließen">✕</button>
      </div>
      <div style="text-align:center; margin-bottom:12px;">
        <img src="<?= app_url('assets/img/logo.png') ?>" alt="Logo" style="width:96px; height:96px; object-fit:contain;">
      </div>
      <div id="about-modal-body"><div class="app-empty">Lade…</div></div>
      <div class="pnk-modal__footer">
        <button class="pnk-btn" id="about-modal-close-2" type="button">Schließen</button>
      </div>
    </div>
  </div>

  <div class="pnk-modal-backdrop" id="account-modal-backdrop" hidden>
    <div class="pnk-modal" style="width:420px;">
      <div class="pnk-modal__header">
        <span class="pnk-card__title">Konto verwalten</span>
        <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="account-modal-close" type="button" aria-label="Schließen">✕</button>
      </div>
      <div id="account-modal-feedback"></div>

      <p class="pnk-label" style="margin-bottom:4px;">Benutzernamen ändern</p>
      <input class="pnk-input" type="text" id="account-username" style="margin-bottom:8px;">
      <label class="pnk-label">Aktuelles Passwort (zur Bestätigung)</label>
      <input class="pnk-input" type="password" id="account-username-current-password" autocomplete="current-password" style="margin-bottom:10px;">
      <button class="pnk-btn pnk-btn--primary pnk-btn--sm" id="account-username-save" type="button">Benutzernamen speichern</button>

      <hr style="border:0; border-top:1px solid var(--pnk-border); margin:20px 0;">

      <p class="pnk-label" style="margin-bottom:4px;">Passwort ändern</p>
      <label class="pnk-label">Neues Passwort (min. 8 Zeichen)</label>
      <input class="pnk-input" type="password" id="account-password-new" autocomplete="new-password" style="margin-bottom:8px;">
      <label class="pnk-label">Neues Passwort wiederholen</label>
      <input class="pnk-input" type="password" id="account-password-confirm" autocomplete="new-password" style="margin-bottom:8px;">
      <label class="pnk-label">Aktuelles Passwort (zur Bestätigung)</label>
      <input class="pnk-input" type="password" id="account-password-current-password" autocomplete="current-password" style="margin-bottom:10px;">
      <button class="pnk-btn pnk-btn--primary pnk-btn--sm" id="account-password-save" type="button">Passwort speichern</button>

      <div class="pnk-modal__footer">
        <button class="pnk-btn" id="account-modal-close-2" type="button">Schließen</button>
      </div>
    </div>
  </div>

  <main class="pnk-main app-content" id="app-main" style="grid-area:main;">
