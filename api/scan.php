<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Scanner;

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();
Auth::requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::check($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges Formular. Bitte Seite neu laden.']);
    exit;
}

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php
// und api/upload.php): ein "step" verarbeitet bis zu 25 Dateien (Metadaten/
// Cover-Art) und kann spuerbar dauern - ohne das wuerde jede andere
// parallele Anfrage derselben Sitzung (z.B. eine Bibliothek loeschen,
// waehrend der automatische Scan nach einem Upload noch laeuft) darauf
// warten muessen.
session_write_close();

$action = $input['action'] ?? '';
$libraryId = (int) ($input['library_id'] ?? 0);

try {
    if ($action === 'start') {
        echo json_encode(array_merge(['ok' => true], Scanner::start($libraryId)));
        exit;
    }
    if ($action === 'step') {
        echo json_encode(array_merge(['ok' => true], Scanner::step($libraryId, 25)));
        exit;
    }
    if ($action === 'cancel') {
        Scanner::cancel($libraryId);
        echo json_encode(['ok' => true]);
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte Aktion.']);
