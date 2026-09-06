<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\UserRepository;
use App\Util;

Auth::requireLogin();

$repo = new UserRepository();
$error = null;
$success = null;

/**
 * Bestaetigt das AKTUELLE Passwort des gerade angemeldeten Admins - Pflicht
 * vor dem Anlegen eines neuen Admin-Kontos oder dem Aendern eines
 * (fremden ODER eigenen) Passworts. Ohne das koennte eine gekaperte Session
 * (z.B. gestohlenes Cookie) sich stillschweigend einen dauerhaften
 * Zugriffsweg schaffen bzw. einen anderen Admin uebernehmen, ohne dass der
 * eigentliche Login-Schutz (Passwort) noch einmal greift.
 */
function currentPasswordConfirmed(UserRepository $repo): bool
{
    $me = $repo->findById((int) Auth::userId());
    $current = (string) ($_POST['current_password'] ?? '');
    return $me && $current !== '' && password_verify($current, $me['password_hash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (!currentPasswordConfirmed($repo)) {
            $error = 'Zur Bestaetigung bitte dein aktuelles Passwort eingeben.';
        } elseif ($username === '' || strlen($password) < 8) {
            $error = 'Benutzername erforderlich, Passwort mindestens 8 Zeichen.';
        } elseif ($repo->usernameExists($username)) {
            $error = 'Dieser Benutzername existiert bereits.';
        } else {
            $repo->create($username, $password, 'admin');
            $success = "Admin-Konto „{$username}“ angelegt.";
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        if ($id === Auth::userId()) {
            $error = 'Du kannst dein eigenes Konto nicht loeschen.';
        } elseif ($repo->count() <= 1) {
            $error = 'Das letzte verbleibende Konto kann nicht geloescht werden.';
        } else {
            $repo->delete($id);
            $success = 'Konto geloescht.';
        }
    } elseif ($action === 'password') {
        $id = (int) $_POST['id'];
        $password = (string) ($_POST['password'] ?? '');
        if (!currentPasswordConfirmed($repo)) {
            $error = 'Zur Bestaetigung bitte dein aktuelles Passwort eingeben.';
        } elseif (strlen($password) < 8) {
            $error = 'Passwort muss mindestens 8 Zeichen haben.';
        } else {
            $repo->updatePassword($id, $password);
            $success = 'Passwort geaendert.';
        }
    }
}

$users = $repo->all();

$pageTitle = 'Benutzer';
$activeNav = 'users';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Benutzer</h2>
<p class="pnk-text-muted" style="max-width:70ch;">
  Es gibt hier nur Admin-Konten. Gäste benoetigen keinen Account - jeder,
  der die Wunsch-Seite oeffnet, ist automatisch "Gast" und kann Songs
  wünschen, ohne sich anzumelden.
</p>

<?php if ($error): ?><div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;"><?= Util::e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;"><?= Util::e($success) ?></div><?php endif; ?>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header"><span class="pnk-card__title">Neuen Admin anlegen</span></div>
  <form method="post" action="<?= app_url('admin/users.php') ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
      <div>
        <label class="pnk-label">Benutzername</label>
        <input class="pnk-input" type="text" name="username" required>
      </div>
      <div>
        <label class="pnk-label">Passwort (min. 8 Zeichen)</label>
        <input class="pnk-input" type="password" name="password" minlength="8" required>
      </div>
    </div>
    <label class="pnk-label" style="margin-top:12px;">Dein aktuelles Passwort (zur Bestätigung)</label>
    <input class="pnk-input" type="password" name="current_password" autocomplete="current-password" required style="max-width:320px;">
    <button class="pnk-btn pnk-btn--primary" type="submit" style="margin-top:12px;">Anlegen</button>
  </form>
</div>

<div class="pnk-card">
  <div class="pnk-card__header"><span class="pnk-card__title">Konten</span></div>
  <table class="pnk-table">
    <thead><tr><th>Benutzername</th><th>Angelegt</th><th>Letzte Anmeldung</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= Util::e($u['username']) ?><?= $u['id'] == Auth::userId() ? ' <span class="pnk-badge pnk-badge--accent">Du</span>' : '' ?></td>
        <td><?= Util::e($u['created_at']) ?></td>
        <td><?= Util::e($u['last_login_at'] ?? '-') ?></td>
        <td style="display:flex; gap:6px; justify-content:flex-end;">
          <details class="pnk-dropdown">
            <summary class="pnk-btn pnk-btn--ghost pnk-btn--sm">Passwort ändern ▾</summary>
            <div class="pnk-dropdown-menu">
              <form method="post" action="<?= app_url('admin/users.php') ?>" style="padding:8px; min-width:220px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <label class="pnk-label">Neues Passwort</label>
                <input class="pnk-input" type="password" name="password" minlength="8" required style="margin-bottom:8px;">
                <label class="pnk-label">Dein aktuelles Passwort (zur Bestätigung)</label>
                <input class="pnk-input" type="password" name="current_password" autocomplete="current-password" required style="margin-bottom:8px;">
                <button class="pnk-btn pnk-btn--primary pnk-btn--sm" type="submit" style="width:100%;">Speichern</button>
              </form>
            </div>
          </details>
          <form method="post" action="<?= app_url('admin/users.php') ?>" onsubmit="return confirm('Konto wirklich loeschen?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="pnk-btn pnk-btn--danger pnk-btn--sm" type="submit">Löschen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
