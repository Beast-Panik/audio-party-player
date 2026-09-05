<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\SettingRepository;

if (Auth::isLoggedIn()) {
    header('Location: ' . app_url('player.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if ($username !== '' && $password !== '' && Auth::attempt($username, $password)) {
        header('Location: ' . app_url('player.php'));
        exit;
    }
    $error = 'Benutzername oder Passwort falsch.';
}

$appName = (new SettingRepository())->get('app_name', 'Party Player - pan1k.de');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
(function () {
  try {
    var theme = localStorage.getItem('pnk-theme');
    if (theme) document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {}
})();
</script>
<title>Anmelden · <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app app-login-wrap" style="display:flex;">
  <div class="pnk-card pnk-card--raised app-login-card">
    <div class="pnk-card__header">
      <span class="pnk-card__title"><?= htmlspecialchars($appName, ENT_QUOTES) ?> · Anmelden</span>
    </div>
    <?php if ($error): ?>
      <div class="pnk-alert pnk-alert--danger" style="margin-bottom:12px;"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= app_url('login.php') ?>">
      <?= Csrf::field() ?>
      <label class="pnk-label" for="username">Benutzername</label>
      <input class="pnk-input" type="text" name="username" id="username" autofocus required style="margin-bottom:12px;">
      <label class="pnk-label" for="password">Passwort</label>
      <input class="pnk-input" type="password" name="password" id="password" required style="margin-bottom:16px;">
      <button class="pnk-btn pnk-btn--primary" type="submit" style="width:100%;">Anmelden</button>
    </form>
  </div>
</body>
</html>
