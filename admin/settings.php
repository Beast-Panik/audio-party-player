<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\LogoProcessor;
use App\PlayerSession;
use App\Repositories\SettingRepository;
use App\Util;

Auth::requireLogin();
PlayerSession::requireMasterOrRedirect();

$settings = new SettingRepository();
$error = null;
$success = null;
$openSection = 'allgemein';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? 'allgemein';
    $openSection = $form === 'lock_pin' ? 'player_sperre' : ($form === 'qr_logo' ? 'wunsch_seite' : $form);

    if ($form === 'allgemein') {
        Csrf::requireValid();
        $appName = trim($_POST['app_name'] ?? '');
        if ($appName === '') {
            $error = 'Der Party-/App-Name darf nicht leer sein.';
        } else {
            $settings->set('app_name', $appName);
            $success = 'Einstellungen gespeichert.';
        }
    }

    if ($form === 'anzeige') {
        Csrf::requireValid();
        $countdownFontSize = (int) ($_POST['countdown_font_size'] ?? 22);
        if ($countdownFontSize < 12 || $countdownFontSize > 60) {
            $error = 'Die Countdown-Größe muss zwischen 12 und 60 Pixel liegen.';
        } else {
            $settings->set('ticker_enabled', !empty($_POST['ticker_enabled']) ? '1' : '0');
            $settings->set('countdown_enabled', !empty($_POST['countdown_enabled']) ? '1' : '0');
            $settings->set('countdown_font_size', (string) $countdownFontSize);
            $success = 'Einstellungen gespeichert.';
        }
    }

    if ($form === 'gaeste_wuensche') {
        Csrf::requireValid();
        $guestLimitCount = (int) ($_POST['guest_limit_count'] ?? 0);
        $guestLimitMinutes = (int) ($_POST['guest_limit_minutes'] ?? 0);
        $lockHours = (int) ($_POST['recent_played_lock_hours'] ?? 0);
        $previewSeconds = (int) ($_POST['preview_seconds'] ?? 0);
        $autoDjTargetCount = (int) ($_POST['auto_dj_target_count'] ?? 3);

        if ($guestLimitCount < 0 || $guestLimitMinutes < 0) {
            $error = 'Limit und Zeitraum duerfen nicht negativ sein.';
        } elseif ($guestLimitCount > 0 && $guestLimitMinutes < 1) {
            $error = 'Bei aktivem Limit muss der Zeitraum mindestens 1 Minute betragen.';
        } elseif ($lockHours < 0) {
            $error = 'Die Sperrfrist darf nicht negativ sein.';
        } elseif ($previewSeconds < 5 || $previewSeconds > 60) {
            $error = 'Die Vorhoerdauer muss zwischen 5 und 60 Sekunden liegen.';
        } elseif ($autoDjTargetCount < 0 || $autoDjTargetCount > 15) {
            $error = 'Die Auto-DJ-Zielanzahl muss zwischen 0 und 15 liegen.';
        } else {
            $settings->set('guest_limit_count', (string) $guestLimitCount);
            $settings->set('guest_limit_minutes', (string) max(1, $guestLimitMinutes));
            $settings->set('recent_played_lock_hours', (string) $lockHours);
            $settings->set('preview_seconds', (string) $previewSeconds);
            $settings->set('auto_dj_target_count', (string) $autoDjTargetCount);
            $success = 'Einstellungen gespeichert.';
        }
    }

    if ($form === 'player') {
        Csrf::requireValid();
        $crossfadeSeconds = (int) ($_POST['crossfade_seconds'] ?? 3);
        $masterVolume = (int) ($_POST['master_volume'] ?? 100);
        // Im Formular in Sekunden (mit Nachkommastellen) eingegeben, intern
        // weiterhin in Millisekunden gespeichert - praeziser fuer die
        // JS-seitige Fade-Berechnung (siehe fadeVolume() in app.js).
        $pauseFadeOutSeconds = (float) ($_POST['pause_fade_out_seconds'] ?? 0.3);
        $pauseFadeInSeconds = (float) ($_POST['pause_fade_in_seconds'] ?? 0.3);
        $pauseFadeOutMs = (int) round($pauseFadeOutSeconds * 1000);
        $pauseFadeInMs = (int) round($pauseFadeInSeconds * 1000);
        if ($crossfadeSeconds < 1 || $crossfadeSeconds > 15) {
            $error = 'Uebergangszeit muss zwischen 1 und 15 Sekunden liegen.';
        } elseif ($masterVolume < 0 || $masterVolume > 100) {
            $error = 'Lautstaerke muss zwischen 0 und 100% liegen.';
        } elseif ($pauseFadeOutMs < 0 || $pauseFadeOutMs > 5000 || $pauseFadeInMs < 0 || $pauseFadeInMs > 5000) {
            $error = 'Auf-/Abblendzeit muss zwischen 0 und 5 Sekunden liegen.';
        } else {
            $settings->set('crossfade_enabled', !empty($_POST['crossfade_enabled']) ? '1' : '0');
            $settings->set('crossfade_seconds', (string) $crossfadeSeconds);
            $settings->set('master_volume', (string) $masterVolume);
            $settings->set('pause_fade_out_ms', (string) $pauseFadeOutMs);
            $settings->set('pause_fade_in_ms', (string) $pauseFadeInMs);
            $success = 'Einstellungen gespeichert.';
        }
    }

    if ($form === 'lock_pin') {
        Csrf::requireValid();
        $lockAction = $_POST['lock_action'] ?? 'set';

        if ($lockAction === 'clear') {
            $settings->set('lock_pin_hash', null);
            $success = 'PIN-Sperre deaktiviert.';
        } else {
            $pin = trim($_POST['lock_pin'] ?? '');
            $pinConfirm = trim($_POST['lock_pin_confirm'] ?? '');
            if (!preg_match('/^\d{4}$/', $pin)) {
                $error = 'Die PIN muss genau 4 Ziffern haben.';
            } elseif ($pin !== $pinConfirm) {
                $error = 'Die beiden PINs stimmen nicht ueberein.';
            } else {
                $settings->set('lock_pin_hash', password_hash($pin, PASSWORD_DEFAULT));
                $success = 'PIN gespeichert. Der Player kann jetzt gesperrt werden.';
            }
        }
    }

    if ($form === 'wunsch_seite') {
        Csrf::requireValid();
        $requestUrlOverride = trim($_POST['request_url_override'] ?? '');
        $settings->set('request_url_override', $requestUrlOverride !== '' ? rtrim($requestUrlOverride, '/') : null);
        $success = 'Einstellungen gespeichert.';
    }

    if ($form === 'qr_logo') {
        Csrf::requireValid();
        $logoAction = $_POST['logo_action'] ?? 'save';
        $qrLogoDir = dirname(__DIR__) . '/data';
        $currentLogoExt = $settings->get('qr_logo_ext', '');

        if ($logoAction === 'remove') {
            if ($currentLogoExt !== '') {
                @unlink($qrLogoDir . '/qr_logo.' . $currentLogoExt);
            }
            $settings->set('qr_logo_ext', null);
            $success = 'Logo entfernt.';
        } else {
            $logoSizePercent = (int) ($_POST['qr_logo_size_percent'] ?? 20);
            $logoBorderPx = (int) ($_POST['qr_logo_border_px'] ?? 6);
            $allowUnsafeSize = !empty($_POST['qr_logo_allow_unsafe_size']);
            $maxSizePercent = $allowUnsafeSize ? 90 : 40;
            if ($logoSizePercent < 5 || $logoSizePercent > $maxSizePercent) {
                $error = "Logo-Groesse muss zwischen 5 und {$maxSizePercent}% liegen.";
            } elseif ($logoBorderPx < 0 || $logoBorderPx > 30) {
                $error = 'Weisser Rand muss zwischen 0 und 30 Pixel liegen.';
            } elseif (!empty($_FILES['qr_logo']['tmp_name']) && is_uploaded_file($_FILES['qr_logo']['tmp_name'])) {
                // Dateityp NICHT ueber die vom Browser gesendete Endung/den
                // MIME-Header vertrauen, sondern die Bilddaten selbst
                // pruefen (getimagesize() braucht dafuer keine GD-Extension).
                $info = @getimagesize($_FILES['qr_logo']['tmp_name']);
                $extByType = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
                $newExt = $info ? ($extByType[$info[2]] ?? null) : null;
                if ($newExt === null) {
                    $error = 'Ungueltige Bilddatei - bitte PNG, JPG oder WEBP verwenden.';
                } else {
                    if ($currentLogoExt !== '' && $currentLogoExt !== $newExt) {
                        @unlink($qrLogoDir . '/qr_logo.' . $currentLogoExt);
                    }
                    // Schneidet transparente Raender weg, bevor gespeichert
                    // wird - der weisse Rahmen im QR-Code soll sich an der
                    // sichtbaren Bildkontur orientieren, nicht an der reinen
                    // Leinwandgroesse der hochgeladenen Datei.
                    LogoProcessor::saveTrimmed($_FILES['qr_logo']['tmp_name'], $qrLogoDir . '/qr_logo.' . $newExt, $newExt);
                    $settings->set('qr_logo_ext', $newExt);
                    $settings->set('qr_logo_size_percent', (string) $logoSizePercent);
                    $settings->set('qr_logo_border_px', (string) $logoBorderPx);
                    $settings->set('qr_logo_allow_unsafe_size', $allowUnsafeSize ? '1' : '0');
                    $success = 'Einstellungen gespeichert.';
                }
            } else {
                $settings->set('qr_logo_size_percent', (string) $logoSizePercent);
                $settings->set('qr_logo_border_px', (string) $logoBorderPx);
                $settings->set('qr_logo_allow_unsafe_size', $allowUnsafeSize ? '1' : '0');
                $success = 'Einstellungen gespeichert.';
            }
        }
    }

    if ($form === 'display_screen') {
        Csrf::requireValid();
        $displayTheme = $_POST['display_theme'] ?? 'dark';
        $settings->set('display_theme', $displayTheme === 'light' ? 'light' : 'dark');
        $success = 'Einstellungen gespeichert.';
        $openSection = 'wunsch_seite';
    }
}

$appName = $settings->get('app_name', 'Party Player');
$requestUrlOverride = $settings->get('request_url_override', '');
$displayTheme = $settings->get('display_theme', 'dark');
$qrLogoExt = $settings->get('qr_logo_ext', '');
$qrLogoSizePercent = (int) $settings->get('qr_logo_size_percent', '20');
$qrLogoBorderPx = (int) $settings->get('qr_logo_border_px', '6');
$qrLogoAllowUnsafeSize = $settings->get('qr_logo_allow_unsafe_size', '0') === '1';
$requestUrl = ($requestUrlOverride ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');
$guestLimitCount = (int) $settings->get('guest_limit_count', '3');
$guestLimitMinutes = (int) $settings->get('guest_limit_minutes', '60');
$recentPlayedLockHours = (int) $settings->get('recent_played_lock_hours', '4');
$autoDjTargetCount = (int) $settings->get('auto_dj_target_count', '3');
$previewSeconds = (int) $settings->get('preview_seconds', '20');
$tickerEnabled = $settings->get('ticker_enabled', '0') === '1';
$countdownEnabled = $settings->get('countdown_enabled', '0') === '1';
$countdownFontSize = (int) $settings->get('countdown_font_size', '22');
$crossfadeEnabled = $settings->get('crossfade_enabled', '0') === '1';
$crossfadeSeconds = (int) $settings->get('crossfade_seconds', '3');
$masterVolume = (int) $settings->get('master_volume', '100');
$pauseFadeOutMs = (int) $settings->get('pause_fade_out_ms', '300');
$pauseFadeInMs = (int) $settings->get('pause_fade_in_ms', '300');

if (!function_exists('settings_accordion_open')) {
    function settings_accordion_open(string $key, string $openSection): string
    {
        return $key === $openSection ? ' open' : '';
    }
}

$pageTitle = 'Einstellungen';
$activeNav = 'settings';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Einstellungen</h2>

<?php if ($error): ?><div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;"><?= Util::e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;"><?= Util::e($success) ?></div><?php endif; ?>

<details class="pnk-card app-accordion"<?= settings_accordion_open('allgemein', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Allgemein</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="allgemein">
      <label class="pnk-label">Party-/App-Name</label>
      <input class="pnk-input" type="text" name="app_name" value="<?= Util::e($appName) ?>">
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('anzeige', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Anzeige</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="anzeige">
      <label class="pnk-field-row" style="cursor:pointer; margin-bottom:12px;">
        <input class="pnk-checkbox" type="checkbox" name="ticker_enabled" value="1" <?= $tickerEnabled ? 'checked' : '' ?>>
        <span>Ticker mit aktuellem Song im Menü anzeigen</span>
      </label>
      <label class="pnk-field-row" style="cursor:pointer; margin-bottom:12px;">
        <input class="pnk-checkbox" type="checkbox" name="countdown_enabled" value="1" <?= $countdownEnabled ? 'checked' : '' ?>>
        <span>Countdown bis zum nächsten Track im Menü anzeigen</span>
      </label>
      <label class="pnk-label" for="countdown_font_size">Countdown-Größe im Menü (Schriftgröße in Pixel)</label>
      <div style="display:flex; align-items:center; gap:12px; max-width:360px;">
        <input type="range" id="countdown_font_size" name="countdown_font_size" min="12" max="60" step="1" value="<?= $countdownFontSize ?>" style="flex:1;" oninput="document.getElementById('countdown_font_size_value').textContent = this.value + ' px'; document.getElementById('countdown-size-preview').style.fontSize = this.value + 'px';">
        <span id="countdown_font_size_value" class="pnk-text-muted" style="min-width:48px;"><?= $countdownFontSize ?> px</span>
      </div>
      <p class="pnk-text-muted" style="font-size:12px; margin:8px 0 0;">Vorschau: <span id="countdown-size-preview" style="font-family:var(--pnk-font-mono); font-weight:700; color:var(--pnk-accent-warm); font-size:<?= $countdownFontSize ?>px;">-3:21</span></p>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('gaeste_wuensche', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Gäste-Wünsche</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="gaeste_wuensche">
      <div class="grid-2">
        <div>
          <label class="pnk-label">Max. Wünsche pro Gast</label>
          <input class="pnk-input" type="number" min="0" step="1" name="guest_limit_count" value="<?= (int) $guestLimitCount ?>">
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">0 = kein Limit.</p>
        </div>
        <div>
          <label class="pnk-label">Zeitraum (Minuten)</label>
          <input class="pnk-input" type="number" min="1" step="1" name="guest_limit_minutes" value="<?= (int) $guestLimitMinutes ?>">
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">z.B. 60 = pro Stunde.</p>
        </div>
      </div>
      <p class="pnk-text-muted" style="font-size:12px; margin:10px 0 0;">
        Gäste werden ueber ein Cookie wiedererkannt (kein Login noetig) - so laesst
        sich z.B. auf "max. 3 Wünsche pro Stunde und Gast" begrenzen.
      </p>
      <div class="grid-2" style="margin-top:16px;">
        <div>
          <label class="pnk-label">Sperrfrist für kürzlich gespielte Tracks (Stunden)</label>
          <input class="pnk-input" type="number" min="0" step="1" name="recent_played_lock_hours" value="<?= (int) $recentPlayedLockHours ?>">
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
            0 = keine Sperre. Gilt für Gastwünsche und den Auto-DJ, siehe "Kürzlich gespielt" auf der Player-Seite.
          </p>
        </div>
        <div>
          <label class="pnk-label">Vorhördauer (Sekunden)</label>
          <input class="pnk-input" type="number" min="5" max="60" step="1" name="preview_seconds" value="<?= (int) $previewSeconds ?>">
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
            Ausschnitt aus der Mitte des Tracks, den Gäste vor dem Wünschen anhören können.
          </p>
        </div>
      </div>
      <div style="margin-top:16px;">
        <label class="pnk-label">Auto-DJ: Ziel-Anzahl Tracks in der Playlist</label>
        <input class="pnk-input" type="number" min="0" max="15" step="1" name="auto_dj_target_count" value="<?= (int) $autoDjTargetCount ?>" style="max-width:120px;">
        <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
          Der Auto-DJ haelt die Playlist staendig auf dieser Anzahl (0-15): sobald sie
          durch Abspielen darunter faellt, wird sofort automatisch der naechste Track
          ergaenzt. 0 = Auto-DJ füllt nichts automatisch nach (Gastwünsche werden trotzdem
          angenommen, wenn Auto-DJ aktiv ist).
        </p>
      </div>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('player', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Player</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="player">
      <label class="pnk-field-row" style="cursor:pointer; margin-bottom:12px;">
        <input class="pnk-checkbox" type="checkbox" name="crossfade_enabled" value="1" <?= $crossfadeEnabled ? 'checked' : '' ?>>
        <span>Crossfade beim Trackwechsel</span>
      </label>
      <label class="pnk-label">Übergangszeit (Sekunden)</label>
      <input class="pnk-input" type="number" min="1" max="15" step="1" name="crossfade_seconds" value="<?= (int) $crossfadeSeconds ?>" style="max-width:120px;">
      <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0 0;">
        Der aktuelle Track wird ausgeblendet, waehrend der naechste eingeblendet
        und schon gestartet wird - kein harter Schnitt zwischen zwei Songs.
      </p>

      <label class="pnk-label" style="margin-top:20px;">Lautstärke (%)</label>
      <input class="pnk-input" type="number" min="0" max="100" step="1" name="master_volume" value="<?= (int) $masterVolume ?>" style="max-width:120px;">
      <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0 0;">
        Gilt fuer die gesamte Wiedergabe (auch waehrend Crossfade und Auf-/
        Abblenden) - z.B. 80%, wenn der Player nie ganz auf volle Lautstaerke
        gehen soll.
      </p>

      <label class="pnk-label" style="margin-top:20px;">Abblenden beim Pausieren (Sekunden)</label>
      <input class="pnk-input" type="number" min="0" max="5" step="0.1" name="pause_fade_out_seconds" value="<?= rtrim(rtrim(number_format($pauseFadeOutMs / 1000, 1, '.', ''), '0'), '.') ?: '0' ?>" style="max-width:120px;">

      <label class="pnk-label" style="margin-top:16px;">Aufblenden beim Fortsetzen (Sekunden)</label>
      <input class="pnk-input" type="number" min="0" max="5" step="0.1" name="pause_fade_in_seconds" value="<?= rtrim(rtrim(number_format($pauseFadeInMs / 1000, 1, '.', ''), '0'), '.') ?: '0' ?>" style="max-width:120px;">
      <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0 0;">
        Beim Klick auf Pause/Play blendet die Lautstaerke sanft statt hart
        abzuschneiden - 0 = kein Fade (sofortiges Stoppen/Starten).
      </p>

      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('player_sperre', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Player-Sperre (PIN)</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <p class="pnk-text-muted">
      Vierstellige PIN, mit der sich der Player-Bildschirm waehrend der Party sperren
      laesst (z.B. wenn der DJ kurz weg muss) - die Musik spielt dabei ungestoert weiter,
      nur die Bedienung ist bis zur Eingabe der PIN gesperrt.
    </p>
    <?php if ($settings->get('lock_pin_hash')): ?>
      <div class="pnk-alert pnk-alert--success" style="margin-bottom:12px; font-size:13px;">PIN ist eingerichtet.</div>
    <?php endif; ?>
    <form method="post" action="<?= app_url('admin/settings.php') ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="lock_pin">
      <input type="hidden" name="lock_action" value="set">
      <div>
        <label class="pnk-label">Neue PIN (4 Ziffern)</label>
        <input class="pnk-input" type="password" inputmode="numeric" pattern="\d{4}" maxlength="4" name="lock_pin" style="width:100px;" autocomplete="off">
      </div>
      <div>
        <label class="pnk-label">Wiederholen</label>
        <input class="pnk-input" type="password" inputmode="numeric" pattern="\d{4}" maxlength="4" name="lock_pin_confirm" style="width:100px;" autocomplete="off">
      </div>
      <button class="pnk-btn pnk-btn--primary" type="submit">PIN speichern</button>
    </form>
    <?php if ($settings->get('lock_pin_hash')): ?>
      <form method="post" action="<?= app_url('admin/settings.php') ?>" style="margin-top:10px;" onsubmit="return confirm('PIN-Sperre wirklich deaktivieren?');">
        <?= Csrf::field() ?>
        <input type="hidden" name="form" value="lock_pin">
        <input type="hidden" name="lock_action" value="clear">
        <button class="pnk-btn pnk-btn--ghost pnk-btn--sm" type="submit">PIN entfernen / Sperre deaktivieren</button>
      </form>
    <?php endif; ?>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('wunsch_seite', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title">Wunsch-Seite &amp; QR-Code</span>
    <span class="app-accordion__chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="app-accordion__body">
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="wunsch_seite">
      <label class="pnk-label">Oeffentliche URL fuer die Wunsch-Seite (optional)</label>
      <input class="pnk-input" type="text" name="request_url_override" value="<?= Util::e($requestUrlOverride) ?>" placeholder="z.B. https://wunsch.meine-domain.de">
      <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
        Leer lassen, um die in <code>config/config.php</code> hinterlegte <code>app_url</code>
        (<?= Util::e(Config::get('app_url', '')) ?>) zu verwenden. Wenn du eine eigene Subdomain
        wie <code>wunsch.deine-domain.de</code> beim Hoster/DNS-Provider auf dieses Verzeichnis
        zeigen laesst, kannst du sie hier eintragen - der QR-Code unten nutzt dann automatisch
        diese Adresse.
      </p>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>

    <hr style="border:0; border-top:1px solid var(--pnk-border); margin:20px 0;">

    <p class="pnk-text-muted">Ausdrucken oder auf einen Bildschirm werfen - Gäste scannen und landen direkt auf der Wunsch-Seite.</p>
    <div class="app-qr-box">
      <img id="qr-code-preview" src="<?= app_url('api/qr.php') ?>" alt="QR-Code zur Wunsch-Seite" width="220" height="220">
      <div>
        <label class="pnk-label">Link (falls Scannen nicht klappt)</label>
        <div class="install-box" style="display:flex; gap:8px; align-items:center; background:var(--pnk-surface-sunken); border:1px solid var(--pnk-border); border-radius:var(--pnk-radius); padding:10px 14px; max-width:420px;">
          <code style="font-family:var(--pnk-font-mono,monospace); font-size:12.5px; word-break:break-all;"><?= Util::e($requestUrl) ?></code>
        </div>
        <p class="pnk-text-muted" style="font-size:12px; margin-top:10px;">
          Rechtsklick auf den QR-Code → "Grafik speichern unter…", um ihn z.B. auszudrucken.
        </p>
      </div>
    </div>

    <hr style="border:0; border-top:1px solid var(--pnk-border); margin:20px 0;">

    <p class="pnk-text-muted" style="margin:0 0 12px;">
      Optionales Logo in der Mitte des QR-Codes (z.B. Party-/Vereinslogo). Der QR-Code wird dafuer
      automatisch mit maximaler Fehlertoleranz (30%) erzeugt, damit er trotz Logo zuverlaessig
      scannbar bleibt. Transparente Raender im Logo werden automatisch weggeschnitten - der weisse
      Rahmen orientiert sich am sichtbaren Bildinhalt, nicht an der Leinwandgroesse der Datei.
    </p>
    <form method="post" action="<?= app_url('admin/settings.php') ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="qr_logo">
      <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
        <div style="flex:0 0 auto;">
          <canvas id="qr-logo-live-preview" width="220" height="220" style="display:block; width:220px; height:220px; border-radius:var(--pnk-radius); background:#fff;"></canvas>
          <p class="pnk-text-muted" style="font-size:11px; margin:6px 0 0; max-width:220px;">Live-Vorschau - zeigt auch ein gerade erst ausgewaehltes, noch nicht gespeichertes Bild.</p>
        </div>
        <div style="flex:1 1 260px; min-width:220px;">
          <label class="pnk-label">Logo-Datei (PNG, JPG oder WEBP)</label>
          <input class="pnk-input" type="file" id="qr-logo-file" name="qr_logo" accept="image/png,image/jpeg,image/webp">
          <?php if ($qrLogoExt !== ''): ?>
            <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
              Aktuell ist ein Logo hinterlegt - neue Datei waehlen zum Ersetzen, oder unten entfernen.
            </p>
          <?php endif; ?>

          <label class="pnk-label" style="margin-top:16px;">Logo-Größe (<span id="qr-logo-size-value"><?= (int) $qrLogoSizePercent ?></span>%)</label>
          <input type="range" id="qr-logo-size-slider" name="qr_logo_size_percent" min="5" max="<?= $qrLogoAllowUnsafeSize ? 90 : 40 ?>" step="1" value="<?= (int) $qrLogoSizePercent ?>" style="width:100%; max-width:320px; display:block;">

          <label class="pnk-label" style="margin-top:16px;">Weißer Rand um Logo (<span id="qr-logo-border-value"><?= (int) $qrLogoBorderPx ?></span>px)</label>
          <input type="range" id="qr-logo-border-slider" name="qr_logo_border_px" min="0" max="30" step="1" value="<?= (int) $qrLogoBorderPx ?>" style="width:100%; max-width:320px; display:block;">
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
            Datei waehlen oder Schieberegler bewegen aktualisiert die Vorschau links sofort - erst
            "Speichern" uebernimmt die Aenderung dauerhaft (dabei werden transparente Raender
            serverseitig zugeschnitten, die Vorschau ist bis dahin eine Annaeherung).
          </p>

          <label class="pnk-field-row" style="cursor:pointer; margin-top:16px;">
            <input class="pnk-checkbox" type="checkbox" id="qr-logo-allow-unsafe" name="qr_logo_allow_unsafe_size" value="1" <?= $qrLogoAllowUnsafeSize ? 'checked' : '' ?>>
            <span>Sicherheitsgrenze überschreiten (auf eigenes Risiko)</span>
          </label>
          <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
            Größere Logos verdecken mehr vom QR-Code und können ihn schlechter scannbar
            machen - diese Option hebt die getestete Sicherheitsgrenze (15% der Fläche)
            auf Wunsch bis auf 90% an. Nach dem Aktivieren unbedingt selbst mit mehreren
            Handys testen, ob der Code noch zuverlässig scannt.
          </p>

          <div style="display:flex; gap:10px; margin-top:16px;">
            <button class="pnk-btn pnk-btn--primary" type="submit">Speichern</button>
            <?php if ($qrLogoExt !== ''): ?>
              <button class="pnk-btn pnk-btn--ghost" type="submit" name="logo_action" value="remove" formnovalidate>Logo entfernen</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>

    <hr style="border:0; border-top:1px solid var(--pnk-border); margin:20px 0;">

    <p class="pnk-text-muted" style="margin:0 0 12px;">
      Eigene, großflächige Anzeige-Seite für einen Bildschirm/Beamer: QR-Code, laufender Ticker
      des aktuellen Tracks und Ankündigung des nächsten Songs.
      <a href="<?= app_url('display.php') ?>" target="_blank" rel="noopener">Anzeige-Bildschirm öffnen ↗</a>
    </p>
    <form method="post" action="<?= app_url('admin/settings.php') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="form" value="display_screen">
      <label class="pnk-label">Theme der Anzeige-Seite</label>
      <div style="display:flex; gap:16px;">
        <label class="pnk-field-row" style="cursor:pointer;">
          <input class="pnk-checkbox" type="radio" name="display_theme" value="dark" <?= $displayTheme !== 'light' ? 'checked' : '' ?>>
          <span>Dunkel</span>
        </label>
        <label class="pnk-field-row" style="cursor:pointer;">
          <input class="pnk-checkbox" type="radio" name="display_theme" value="light" <?= $displayTheme === 'light' ? 'checked' : '' ?>>
          <span>Hell</span>
        </label>
      </div>
      <p class="pnk-text-muted" style="font-size:12px; margin:8px 0 0;">
        Der QR-Code selbst bleibt in jedem Fall dunkel auf hellem Grund, damit er zuverlässig
        scannbar bleibt - nur der Rest der Seite wechselt das Theme.
      </p>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
