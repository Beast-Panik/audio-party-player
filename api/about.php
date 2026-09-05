<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Repositories\SettingRepository;

/**
 * Inhalt fuer den About-Dialog (Sidebar-Footer -> Versionsnummer).
 * Wird per AJAX geladen statt in jede Seite eingebettet, damit
 * Versionsnummer/Lizenzhinweise nur an einer Stelle gepflegt werden.
 */

header('Content-Type: application/json; charset=utf-8');
Auth::requireLoginApi();

$appName = (new SettingRepository())->get('app_name', 'Party Player - pan1k.de');

echo json_encode([
    'app_name' => $appName,
    'version' => APP_VERSION,
    // Keine Drittanbieter-Bibliotheken im Einsatz (kein Composer/npm, keine
    // CDN-Einbindungen) - QrCode.php und Id3Reader.php sind eigene
    // Implementierungen oeffentlicher Standards, daher hier bewusst leer.
    'sources' => [],
    'sources_note' => 'Diese App verwendet keine Drittanbieter-Bibliotheken. Sie laeuft ausschliesslich mit PHP und SQLite als Laufzeitumgebung; Funktionen wie der QR-Code (ISO/IEC 18004) und das Auslesen von Audio-Metadaten (ID3) sind eigene Implementierungen.',
]);
