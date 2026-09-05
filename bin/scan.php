<?php

/**
 * Optionaler CLI-Helfer fuer Cronjobs, z.B. per cPanel-Cron-UI (kein SSH
 * noetig - die meisten Hoster bieten "Cronjob einrichten" im Kundenmenue an):
 *
 *   php /pfad/zu/AudioPartyPlayer/bin/scan.php            (alle Bibliotheken)
 *   php /pfad/zu/AudioPartyPlayer/bin/scan.php 3          (nur Bibliothek-ID 3)
 *
 * Komplett optional - der Scan funktioniert auch ganz ohne Cron ueber den
 * "Scan starten"-Button im Admin-Bereich.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur per Kommandozeile/Cron nutzbar.');
}

require __DIR__ . '/../bootstrap.php';

use App\Repositories\LibraryRepository;
use App\Scanner;

$onlyId = isset($argv[1]) ? (int) $argv[1] : null;
$libraries = (new LibraryRepository())->all();

foreach ($libraries as $lib) {
    if ($onlyId !== null && (int) $lib['id'] !== $onlyId) {
        continue;
    }
    echo "Scanne „{$lib['name']}“ ({$lib['path']})...\n";
    try {
        $start = Scanner::start((int) $lib['id']);
        echo "  {$start['total']} Dateien gefunden.\n";
        do {
            $res = Scanner::step((int) $lib['id'], 100);
            echo "  {$res['processed']} / {$res['total']}\n";
        } while (!$res['done']);
        echo "  Fertig.\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "  Fehler: {$e->getMessage()}\n");
    }
}
