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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? 'general') === 'general') {
    Csrf::requireValid();
    $appName = trim($_POST['app_name'] ?? '');
    $requestUrlOverride = trim($_POST['request_url_override'] ?? '');
    $guestLimitCount = (int) ($_POST['guest_limit_count'] ?? 0);
    $guestLimitMinutes = (int) ($_POST['guest_limit_minutes'] ?? 0);

    if ($appName === '') {
        $error = 'Der Party-/App-Name darf nicht leer sein.';
    } elseif ($guestLimitCount < 0 || $guestLimitMinutes < 0) {
        $error = 'Limit und Zeitraum duerfen nicht negativ sein.';
    } elseif ($guestLimitCount > 0 && $guestLimitMinutes < 1) {
        $error = 'Bei aktivem Limit muss der Zeitraum mindestens 1 Minute betragen.';
    } else {
        $settings->set('app_name', $appName);
        $settings->set('request_url_override', $requestUrlOverride !== '' ? rtrim($requestUrlOverride, '/') : null);
        $settings->set('guest_limit_count', (string) $guestLimitCount);
        $settings->set('guest_limit_minutes', (string) max(1, $guestLimitMinutes));
        $success = 'Einstellungen gespeichert.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'lock_pin') {
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

$appName = $settings->get('app_name', 'Party Player - pan1k.de');
$requestUrlOverride = $settings->get('request_url_override', '');
$requestUrl = ($requestUrlOverride ?: rtrim(Config::get('app_url', ''), '/')) . app_url('request.php');
$guestLimitCount = (int) $settings->get('guest_limit_count', '3');
$guestLimitMinutes = (int) $settings->get('guest_limit_minutes', '60');

$pageTitle = 'Einstellungen';
$activeNav = 'settings';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Einstellungen</h2>

<?php if ($error): ?><div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;"><?= Util::e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;"><?= Util::e($success) ?></div><?php endif; ?>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header"><span class="pnk-card__title">Allgemein</span></div>
  <form method="post" action="<?= app_url('admin/settings.php') ?>">
    <?= Csrf::field() ?>
    <label class="pnk-label">Party-/App-Name</label>
    <input class="pnk-input" type="text" name="app_name" value="<?= Util::e($appName) ?>" style="margin-bottom:12px;">

    <label class="pnk-label">Oeffentliche URL fuer die Wunsch-Seite (optional)</label>
    <input class="pnk-input" type="text" name="request_url_override" value="<?= Util::e($requestUrlOverride) ?>" placeholder="z.B. https://wunsch.meine-domain.de">
    <p class="pnk-text-muted" style="font-size:12px; margin:6px 0 0;">
      Leer lassen, um die in <code>config/config.php</code> hinterlegte <code>app_url</code>
      (<?= Util::e(Config::get('app_url', '')) ?>) zu verwenden. Wenn du eine eigene Subdomain
      wie <code>wunsch.deine-domain.de</code> beim Hoster/DNS-Provider auf dieses Verzeichnis
      zeigen laesst, kannst du sie hier eintragen - der QR-Code unten nutzt dann automatisch
      diese Adresse.
    </p>
    <div class="grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:16px;">
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

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header"><span class="pnk-card__title">Player-Sperre (PIN)</span></div>
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

<div class="pnk-card">
  <div class="pnk-card__header"><span class="pnk-card__title">QR-Code fuer Gäste</span></div>
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

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
