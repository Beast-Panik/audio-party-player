<?php
$pageTitle = $pageTitle ?? 'Musikwunsch';
$titleSuffix = !empty($appName) ? ' · ' . $appName : '';
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle . $titleSuffix, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/panikdark.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/app.css') ?>">
</head>
<body class="pnk-app" style="display:block; min-height:100vh;">
<div class="app-guest-page">
