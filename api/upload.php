<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\LibraryRepository;
use App\Uploader;

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();
Auth::requireAdminApi();

if (!Csrf::check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges Formular. Bitte Seite neu laden.']);
    exit;
}

// Session-Lock sofort freigeben (siehe dieselbe Massnahme in api/events.php):
// Chunk-Schreibvorgaenge koennen bei echten Uploads spuerbar dauern, ohne
// das wuerde das PHP-Standard-Sessionhandling die Session-Datei fuer die
// gesamte Dauer sperren und damit jede andere parallele Anfrage derselben
// Sitzung (Navigation, weitere Chunks, andere API-Aufrufe) blockieren.
session_write_close();

$action = $_GET['action'] ?? '';
$uploadId = (string) ($_GET['upload_id'] ?? '');

try {
    if ($action === 'chunk') {
        Uploader::writeChunk($uploadId, (int) ($_GET['chunk_index'] ?? -1));
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'complete') {
        $result = Uploader::finalize(
            $uploadId,
            (int) ($_GET['total_chunks'] ?? 0),
            (string) ($_GET['subfolder'] ?? ''),
            (string) ($_GET['filename'] ?? '')
        );
        echo json_encode(array_merge(['ok' => true], $result));
        exit;
    }
    if ($action === 'abort') {
        Uploader::abort($uploadId);
        echo json_encode(['ok' => true]);
        exit;
    }
    // Legt fuer den jeweils ausgewaehlten Unterordner beim ersten Upload
    // automatisch eine eigene Bibliothek an (siehe LibraryRepository::
    // findOrCreateUploadLibrary()) und liefert ihre ID, damit der Client
    // danach den ganz normalen Scan-Ablauf (api/scan.php) fuer sie anstossen
    // kann. Jeder Unterordner bekommt so seine eigene, separat loeschbare
    // Bibliothek statt gebuendelt in einer gemeinsamen zu landen.
    if ($action === 'ensure_library') {
        $subfolder = (string) ($_GET['subfolder'] ?? '');
        $path = Uploader::resolveFolderPath($subfolder);
        $name = Uploader::folderLabel($subfolder);
        $lib = (new LibraryRepository())->findOrCreateUploadLibrary($path, $name);
        echo json_encode(['ok' => true, 'library_id' => (int) $lib['id']]);
        exit;
    }
    // Ordner-Anlegen ist bewusst ein eigener, dem eigentlichen Upload
    // vorgelagerter Schritt (siehe admin/library.php) - erst Ordner
    // anlegen, dann beim Hochladen aus den bestehenden Ordnern waehlen.
    if ($action === 'create_folder') {
        $folder = Uploader::createFolder((string) ($_GET['subfolder'] ?? ''));
        echo json_encode(['ok' => true, 'folder' => $folder]);
        exit;
    }
    if ($action === 'list_folders') {
        echo json_encode(['ok' => true, 'folders' => Uploader::listFolders()]);
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte Aktion.']);
