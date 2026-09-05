<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\Repositories\SettingRepository;
use App\Util;

Auth::requireLogin();

$settings = new SettingRepository();
$error = null;
$success = null;
$openSection = 'allgemein';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? 'allgemein';
    $openSection = $form === 'lock_pin' ? 'player_sperre' : $form;

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
        $settings->set('ticker_enabled', !empty($_POST['ticker_enabled']) ? '1' : '0');
        $settings->set('countdown_enabled', !empty($_POST['countdown_enabled']) ? '1' : '0');
        $success = 'Einstellungen gespeichert.';
    }

    if ($form === 'gaeste_wuensche') {
        Csrf::requireValid();
        $guestLimitCount = (int) ($_POST['guest_limit_count'] ?? 0);
        $guestLimitMinutes = (int) ($_POST['guest_limit_minutes'] ?? 0);

        if ($guestLimitCount < 0 || $guestLimitMinutes < 0) {
            $error = 'Limit und Zeitraum duerfen nicht negativ sein.';
        } elseif ($guestLimitCount > 0 && $guestLimitMinutes < 1) {
            $error = 'Bei aktivem Limit muss der Zeitraum mindestens 1 Minute betragen.';
        } else {
            $settings->set('guest_limit_count', (string) $guestLimitCount);
            $settings->set('guest_limit_minutes', (string) max(1, $guestLimitMinutes));
            $success = 'Einstellungen gespeichert.';
        }
    }

    if ($form === 'player') {
        Csrf::requireValid();
        $crossfadeSeconds = (int) ($_POST['crossfade_seconds'] ?? 3);
        if ($crossfadeSeconds < 1 || $crossfadeSeconds > 15) {
            $error = 'Uebergangszeit muss zwischen 1 und 15 Sekunden liegen.';
        } else {
            $settings->set('crossfade_enabled', !empty($_POST['crossfade_enabled']) ? '1' : '0');
            $settings->set('crossfade_seconds', (string) $crossfadeSeconds);
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
}

$appName = $settings->get('app_name', 'Party Player - pan1k.de');
$requestUrlOverride = $settings->get('request_url_override', '');
$requestUrl = ($requestUrlOverride ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');
$guestLimitCount = (int) $settings->get('guest_limit_count', '3');
$guestLimitMinutes = (int) $settings->get('guest_limit_minutes', '60');
$tickerEnabled = $settings->get('ticker_enabled', '0') === '1';
$countdownEnabled = $settings->get('countdown_enabled', '0') === '1';
$crossfadeEnabled = $settings->get('crossfade_enabled', '0') === '1';
$crossfadeSeconds = (int) $settings->get('crossfade_seconds', '3');

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
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">🏷️</span> Allgemein</span>
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
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">📺</span> Anzeige</span>
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
      <label class="pnk-field-row" style="cursor:pointer;">
        <input class="pnk-checkbox" type="checkbox" name="countdown_enabled" value="1" <?= $countdownEnabled ? 'checked' : '' ?>>
        <span>Countdown bis zum nächsten Track im Menü anzeigen</span>
      </label>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('gaeste_wuensche', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">🎶</span> Gäste-Wünsche</span>
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
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('player', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">🎚️</span> Player</span>
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
      <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
        Der aktuelle Track wird ausgeblendet, waehrend der naechste eingeblendet
        und schon gestartet wird - kein harter Schnitt zwischen zwei Songs.
      </p>
      <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:16px;">Speichern</button>
    </form>
  </div>
</details>

<details class="pnk-card app-accordion"<?= settings_accordion_open('player_sperre', $openSection) ?>>
  <summary class="pnk-card__header">
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">🔒</span> Player-Sperre (PIN)</span>
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
    <span class="pnk-card__title app-accordion__title"><span aria-hidden="true">📱</span> Wunsch-Seite &amp; QR-Code</span>
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
      <img src="<?= app_url('api/qr.php') ?>" alt="QR-Code zur Wunsch-Seite" width="220" height="220">
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
  </div>
</details>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
