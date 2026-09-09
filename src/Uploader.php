<?php

namespace App;

/**
 * Nimmt Datei-Chunks entgegen (roher Request-Body statt multipart/form-data,
 * damit nur post_max_size statt des meist strengeren upload_max_filesize
 * gilt) und fuegt sie nach dem letzten Chunk zur fertigen Datei zusammen.
 * Zwischenstand liegt in data/uploads/tmp/ (gesperrt per .htaccess), je
 * Upload in einem eigenen, zufaelligen Unterordner (upload_id, vom Client
 * erzeugt).
 *
 * Der Zielordner ist bewusst fest auf data/uploads/library/ verdrahtet statt
 * vom Client frei waehlbar zu sein (Sicherheitsentscheidung: kein beliebiger
 * Server-Pfad aus einer Web-Anfrage heraus). Innerhalb dieses festen Ordners
 * darf der Admin per $subfolder aber frei einsortieren (z.B. nach Genre) -
 * sanitizeSubfolder() verhindert ein Verlassen des festen Roots (".."/
 * absolute Pfade).
 */
final class Uploader
{
    public static function isValidUploadId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16,64}$/', $id);
    }

    /** Fester, nicht vom Client waehlbarer Upload-Zielordner. */
    public static function libraryRoot(): string
    {
        $path = dirname(__DIR__) . '/data/uploads/library';
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        return $path;
    }

    /** Absoluter Pfad zu einem (bereinigten) Unterordner im festen Upload-Root -
     *  fuer die Zuordnung "ein Unterordner = eine eigene Bibliothek" (siehe
     *  LibraryRepository::findOrCreateUploadLibrary()). */
    public static function resolveFolderPath(string $subfolder): string
    {
        return self::libraryRoot() . self::sanitizeSubfolder($subfolder);
    }

    /** Anzeigename fuer die zu einem Unterordner gehoerende Bibliothek. */
    public static function folderLabel(string $subfolder): string
    {
        $rel = ltrim(self::sanitizeSubfolder($subfolder), '/');
        return $rel === '' ? 'Hauptordner' : $rel;
    }

    /** Prueft, ob ein Pfad innerhalb des festen Upload-Roots liegt - fuer
     *  admin/library.php: nur bei automatisch angelegten Upload-Bibliotheken
     *  (siehe LibraryRepository::findOrCreateUploadLibrary()) sollen beim
     *  Entfernen auch die Dateien auf der Platte mitgeloescht werden, nicht
     *  bei frei/manuell eingerichteten Bibliotheken (koennten auf beliebige,
     *  vom Admin selbst per FTP gepflegte Ordner ausserhalb dieser App
     *  zeigen - dort duerfen niemals automatisch Dateien geloescht werden). */
    public static function isUnderRoot(string $path): bool
    {
        $root = realpath(self::libraryRoot());
        $real = realpath($path);
        if ($root === false || $real === false) {
            return false;
        }
        return $real === $root || str_starts_with($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR);
    }

    /** Loescht einen Upload-Ordner komplett samt Inhalt - nur erlaubt
     *  innerhalb des festen Upload-Roots (siehe isUnderRoot()). */
    public static function deleteFolderTree(string $path): void
    {
        if (!self::isUnderRoot($path)) {
            throw new \RuntimeException('Pfad liegt ausserhalb des Upload-Ordners.');
        }
        self::removeDir($path);
    }

    private static function tmpRoot(): string
    {
        return dirname(__DIR__) . '/data/uploads/tmp';
    }

    private static function chunkDir(string $uploadId): string
    {
        return self::tmpRoot() . '/' . $uploadId;
    }

    public static function writeChunk(string $uploadId, int $index): void
    {
        if (!self::isValidUploadId($uploadId) || $index < 0) {
            throw new \RuntimeException('Ungueltige Chunk-Anfrage.');
        }
        $dir = self::chunkDir($uploadId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Temp-Ordner konnte nicht angelegt werden.');
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($dir . '/' . $index . '.part', 'wb');
        if (!$in || !$out) {
            throw new \RuntimeException('Chunk konnte nicht geschrieben werden.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    /** Fuegt alle Chunks zusammen, verschiebt die fertige Datei in den festen Upload-Ordner (ggf. Unterordner) und raeumt den Temp-Ordner auf. */
    public static function finalize(string $uploadId, int $totalChunks, string $subfolder, string $filename): array
    {
        if (!self::isValidUploadId($uploadId) || $totalChunks < 1) {
            throw new \RuntimeException('Ungueltige Anfrage.');
        }
        if (!Util::isAllowedAudioExtension($filename)) {
            throw new \RuntimeException('Nur MP3- und FLAC-Dateien sind erlaubt.');
        }

        $dir = self::chunkDir($uploadId);
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!is_file($dir . '/' . $i . '.part')) {
                throw new \RuntimeException('Upload unvollstaendig (Chunk ' . $i . ' fehlt).');
            }
        }

        $targetDir = self::libraryRoot() . self::sanitizeSubfolder($subfolder);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Zielordner konnte nicht angelegt werden.');
        }
        if (!is_writable($targetDir)) {
            throw new \RuntimeException('Zielordner nicht beschreibbar.');
        }

        $targetPath = self::resolveCollision($targetDir, self::sanitizeFilename($filename));

        $assembled = $dir . '/assembled.tmp';
        $out = fopen($assembled, 'wb');
        if (!$out) {
            throw new \RuntimeException('Datei konnte nicht zusammengesetzt werden.');
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $in = fopen($dir . '/' . $i . '.part', 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        if (!@rename($assembled, $targetPath)) {
            // Chunk-Temp-Ordner und Bibliotheks-Ordner koennen auf
            // verschiedenen Dateisystemen liegen (rename schlaegt dann fehl).
            if (!@copy($assembled, $targetPath)) {
                self::removeDir($dir);
                throw new \RuntimeException('Datei konnte nicht in die Bibliothek verschoben werden.');
            }
        }

        self::removeDir($dir);

        return ['filename' => basename($targetPath), 'size' => filesize($targetPath)];
    }

    public static function abort(string $uploadId): void
    {
        if (self::isValidUploadId($uploadId)) {
            self::removeDir(self::chunkDir($uploadId));
        }
    }

    /** Legt einen Unterordner im festen Upload-Root an (Ordner-Anlegen ist
     *  ein eigener, dem Upload vorgelagerter Schritt - siehe admin/library.php)
     *  und liefert den bereinigten, relativen Ordnernamen zurueck. */
    public static function createFolder(string $subfolder): string
    {
        $rel = self::sanitizeSubfolder($subfolder);
        if ($rel === '') {
            throw new \RuntimeException('Bitte einen Ordnernamen angeben.');
        }
        $target = self::libraryRoot() . $rel;
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException('Ordner konnte nicht angelegt werden.');
        }
        return ltrim($rel, '/');
    }

    /** Alle (auch verschachtelten) Unterordner im festen Upload-Root, fuer
     *  die Ordnerauswahl beim Hochladen. */
    public static function listFolders(): array
    {
        $root = self::libraryRoot();
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root)));
                $result[] = ltrim($rel, '/');
            }
        }
        sort($result);
        return $result;
    }

    /** Wandelt einen vom Client vorgeschlagenen Unterordner in einen sicheren,
     *  relativen Pfad-Anhang um ("" oder "/Segment1/Segment2/..."). Jedes
     *  Segment wird einzeln geprueft - ".."/"."/leere Segmente/Backslashes
     *  fallen raus, damit der feste Upload-Root nicht verlassen werden kann. */
    private static function sanitizeSubfolder(string $subfolder): string
    {
        $subfolder = str_replace('\\', '/', $subfolder);
        $segments = [];
        foreach (explode('/', $subfolder) as $segment) {
            $segment = trim($segment);
            $segment = preg_replace('/[\x00-\x1F\x7F]/', '', $segment) ?? $segment;
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }
        return $segments ? '/' . implode('/', $segments) : '';
    }

    private static function sanitizeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name;
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'mp3';
            $name = 'upload_' . bin2hex(random_bytes(4)) . '.' . $ext;
        }
        return $name;
    }

    private static function resolveCollision(string $dir, string $filename): string
    {
        $target = $dir . '/' . $filename;
        if (!file_exists($target)) {
            return $target;
        }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $i = 2;
        do {
            $candidate = $dir . '/' . $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            $i++;
        } while (file_exists($candidate));
        return $candidate;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
