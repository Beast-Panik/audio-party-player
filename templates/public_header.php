<?php
$pageTitle = $pageTitle ?? 'Musikwunsch';
$titleSuffix = !empty($appName) ? ' · ' . $appName : '';
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
<title><?= htmlspecialchars($pageTitle . $titleSuffix, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app" style="display:block; min-height:100vh;">
<div class="app-guest-topbar">
  <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="theme-toggle" type="button" title="Theme wechseln" aria-label="Theme wechseln">🌙</button>
</div>
<div class="app-guest-page">
