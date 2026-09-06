<?php

namespace App;

use App\Metadata\FlacReader;
use App\Metadata\Id3Reader;
use App\Repositories\LibraryRepository;
use App\Repositories\TrackRepository;

/**
 * Scannt eine Bibliothek in kleinen Haeppchen (per wiederholtem AJAX-Aufruf
 * aus dem Admin-Bereich getrieben), damit auch auf Shared-Hosts mit
 * strengen PHP-Ausfuehrungszeitlimits kein Cronjob/SSH noetig ist. Der
 * Fortschritt wird zwischen den Aufrufen in einer kleinen JSON-Datei unter
 * data/scanstate/ gehalten.
 */
final class Scanner
{
    private static function stateFile(int $libraryId): string
    {
        return dirname(__DIR__) . "/data/scanstate/lib_{$libraryId}.json";
    }

    /** Startet einen neuen Scan: listet alle Audiodateien und legt den Fortschritt an. */
    public static function start(int $libraryId): array
    {
        $lib = (new LibraryRepository())->findById($libraryId);
        if (!$lib) {
            throw new \RuntimeException('Bibliothek nicht gefunden.');
        }
        $root = rtrim($lib['path'], '/\\');
        if (!is_dir($root) || !is_readable($root)) {
            throw new \RuntimeException("Verzeichnis nicht lesbar: {$root}");
        }

        $files = self::collectFiles($root, (bool) $lib['recursive']);
        $state = ['root' => $root, 'files' => $files, 'total' => count($files), 'processed' => 0];
        file_put_contents(self::stateFile($libraryId), json_encode($state));

        return ['total' => count($files)];
    }

    /** Verarbeitet den naechsten Haeppchen von Dateien. */
    public static function step(int $libraryId, int $chunkSize = 25): array
    {
        $stateFile = self::stateFile($libraryId);
        if (!is_file($stateFile)) {
            return ['done' => true, 'processed' => 0, 'total' => 0, 'error' => 'Kein laufender Scan.'];
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $files = $state['files'];
        $total = $state['total'];
        $processed = $state['processed'];
        $root = $state['root'];

        $trackRepo = new TrackRepository();
        $end = min($processed + $chunkSize, $total);
        $errors = [];
        $duplicates = ($state['duplicates'] ?? 0);
        for ($i = $processed; $i < $end; $i++) {
            $relpath = $files[$i];
            try {
                if (self::scanOneFile($libraryId, $root . '/' . $relpath, $relpath, $trackRepo)) {
                    $duplicates++;
                }
            } catch (\Throwable $e) {
                $errors[] = $relpath . ': ' . $e->getMessage();
            }
        }

        $state['processed'] = $end;
        $state['duplicates'] = $duplicates;
        $done = $end >= $total;

        if ($done) {
            $existing = $trackRepo->relpathsForLibrary($libraryId);
            $toDelete = array_values(array_diff($existing, $files));
            $trackRepo->deleteByRelpaths($libraryId, $toDelete);
            (new LibraryRepository())->markScanned($libraryId, $trackRepo->countForLibrary($libraryId));
            @unlink($stateFile);
        } else {
            file_put_contents($stateFile, json_encode($state));
        }

        return ['done' => $done, 'processed' => $end, 'total' => $total, 'errors' => $errors, 'duplicates' => $duplicates];
    }

    /** Bricht einen laufenden Scan ab (z.B. wenn der Admin ihn neu startet). */
    public static function cancel(int $libraryId): void
    {
        @unlink(self::stateFile($libraryId));
    }

    private static function collectFiles(string $root, bool $recursive): array
    {
        $result = [];
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && Util::isAllowedAudioExtension($file->getFilename())) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
                    $result[] = ltrim($rel, '/');
                }
            }
        } else {
            foreach (scandir($root) ?: [] as $entry) {
                $full = $root . '/' . $entry;
                if (is_file($full) && Util::isAllowedAudioExtension($entry)) {
                    $result[] = $entry;
                }
            }
        }
        sort($result);
        return $result;
    }

    private static function coverDir(): string
    {
        return dirname(__DIR__) . '/data/covers';
    }

    private const COVER_MIME_EXT = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png'];

    /** @return bool true, wenn die Datei als Duplikat (identische Tags, andere Datei) uebersprungen wurde. */
    private static function scanOneFile(int $libraryId, string $fullpath, string $relpath, TrackRepository $repo): bool
    {
        if (!is_file($fullpath)) {
            return false;
        }
        $ext = strtolower(pathinfo($fullpath, PATHINFO_EXTENSION));
        $meta = $ext === 'flac' ? (new FlacReader())->read($fullpath) : (new Id3Reader())->read($fullpath);

        $filename = basename($fullpath);
        if (empty($meta['title'])) {
            $meta['title'] = pathinfo($filename, PATHINFO_FILENAME);
        }
        $meta['filename'] = $filename;
        $meta['filesize'] = filesize($fullpath) ?: 0;
        $meta['mtime'] = filemtime($fullpath) ?: 0;
        $meta['codec'] = $ext;

        $coverExt = null;
        $coverData = $meta['cover_data'] ?? null;
        if ($coverData) {
            $coverExt = self::COVER_MIME_EXT[strtolower((string) ($meta['cover_mime'] ?? ''))] ?? null;
        }
        $meta['cover_ext'] = $coverExt;
        unset($meta['cover_mime'], $meta['cover_data']);

        $existing = $repo->findByLibraryAndRelpath($libraryId, $relpath);
        if (!$existing && $repo->findDuplicateByTags((string) $meta['title'], (string) ($meta['artist'] ?? ''), $libraryId, $relpath)) {
            // Neue Datei, aber Titel+Interpret stimmen mit einem bereits
            // vorhandenen Track ueberein (z.B. dieselbe Aufnahme doppelt auf
            // der Platte oder in zwei gescannten Ordnern) - nicht als
            // zweiten Eintrag anlegen, damit die Bibliothek/Auto-DJ sie
            // nicht als zwei verschiedene Songs behandelt.
            return true;
        }
        $trackId = $repo->upsert($libraryId, $relpath, $meta);

        self::syncCoverFile($trackId, $existing['cover_ext'] ?? null, $coverExt, $coverData);
        return false;
    }

    /** Schreibt/loescht die Cover-Datei passend zum aktuellen Scan-Ergebnis. */
    private static function syncCoverFile(int $trackId, ?string $oldExt, ?string $newExt, ?string $newData): void
    {
        if ($oldExt !== null && $oldExt !== $newExt) {
            @unlink(self::coverDir() . "/{$trackId}.{$oldExt}");
        }
        if ($newExt !== null && $newData !== null) {
            file_put_contents(self::coverDir() . "/{$trackId}.{$newExt}", $newData);
        }
    }
}
