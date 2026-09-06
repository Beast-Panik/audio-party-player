<?php

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Csrf;

// POST + CSRF-Pflicht statt eines rohen GET-Links - sonst liesse sich der
// Logout per <img src="…/logout.php"> von einer Drittseite erzwingen
// (Logout-CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    Auth::logout();
}
header('Location: ' . app_url('login.php'));
