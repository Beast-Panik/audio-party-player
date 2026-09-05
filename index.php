<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;

header('Location: ' . app_url(Auth::isLoggedIn() ? 'player.php' : 'login.php'));
