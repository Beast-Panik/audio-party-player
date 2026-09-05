<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;

Auth::logout();
header('Location: ' . app_url('login.php'));
