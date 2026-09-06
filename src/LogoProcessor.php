<?php

namespace App;

/**
 * Verarbeitet hochgeladene QR-Code-Logos, damit der weisse Rahmen im
 * QR-Code (siehe QrCode::svg()) sich immer an der sichtbaren Bildkontur
 * orientiert statt an der reinen Bild-Leinwand oder einem starren
 * Rechteck:
 *  - saveTrimmed(): schneidet transparente Raender beim Hochladen weg
 *    (nur PNG/WEBP koennen transparent sein).
 *  - composite(): baut zur Anzeigezeit das fertige Box-Bild (Logo + ein
 *    der Silhouette folgender weisser Rahmen) zusammen, per
 *    morphologischer Aufblaehung der Alpha-Maske.
 * Braucht die GD-Extension (auf praktisch jedem Shared-Hoster vorhanden);
 * ist sie nicht verfuegbar, wird beim Upload die Datei unveraendert
 * uebernommen und beim Anzeigen ganz auf das Logo verzichtet, statt den
 * Upload abzulehnen oder einen kaputten QR-Code zu riskieren.
 */
final class LogoProcessor
{
    // Sehr grosse Logos vor der pixelweisen Analyse auf diese Kantenlaenge
    // verkleinern, damit der Zuschnitt nicht unnoetig lange braucht/viel
    // Speicher belegt - fuer ein kleines Logo in einem QR-Code reicht das
    // allemal.
    private const MAX_DIMENSION = 2000;

    // Harte Obergrenze an den in der Datei ANGEGEBENEN Pixelmassen, geprueft
    // per getimagesize() (liest nur den Header) BEVOR imagecreatefrompng/
    // -webp die Datei tatsaechlich komplett dekodiert - eine kleine PNG/WEBP-
    // Datei kann sich sonst auf eine riesige Pixelflaeche "entpacken"
    // (Decompression Bomb) und GD zwingen, mehrere hundert MB/GB zu
    // allozieren, bevor MAX_DIMENSION unten ueberhaupt greifen wuerde.
    private const MAX_UPLOAD_PIXELS = 40_000_000; // z.B. 6320x6320

    /** Liest $srcPath, schneidet transparente Raender weg (falls moeglich/noetig) und schreibt das Ergebnis nach $destPath. */
    public static function saveTrimmed(string $srcPath, string $destPath, string $ext): void
    {
        if (!extension_loaded('gd') || !in_array($ext, ['png', 'webp'], true)) {
            // JPG kann nie transparent sein - Original 1:1 uebernehmen.
            copy($srcPath, $destPath);
            return;
        }

        $info = @getimagesize($srcPath);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > self::MAX_UPLOAD_PIXELS) {
            // Zu gross (oder Header nicht lesbar) - Original unveraendert
            // uebernehmen statt zu dekodieren; api/qr.php verzichtet dann
            // beim Anzeigen einfach auf ein Logo statt einen Fehler zu zeigen.
            copy($srcPath, $destPath);
            return;
        }

        $im = $ext === 'png' ? @imagecreatefrompng($srcPath) : @imagecreatefromwebp($srcPath);
        if ($im === false) {
            copy($srcPath, $destPath);
            return;
        }

        if (imagesx($im) > self::MAX_DIMENSION || imagesy($im) > self::MAX_DIMENSION) {
            $im = self::downscale($im, self::MAX_DIMENSION);
        }

        imagesavealpha($im, true);
        $box = self::alphaBoundingBox($im);
        if ($box !== null) {
            [$x, $y, $w, $h] = $box;
            $cropped = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
            if ($cropped !== false) {
                $im = $cropped;
                imagesavealpha($im, true);
            }
        }

        if ($ext === 'png') {
            imagepng($im, $destPath);
        } else {
            imagewebp($im, $destPath);
        }
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null [x, y, width, height] der
     * sichtbaren (nicht komplett transparenten) Flaeche - null, wenn das
     * Bild komplett transparent ist (nichts zu erkennen) oder ohnehin schon
     * randlos ist (kein Zuschnitt noetig).
     */
    private static function alphaBoundingBox(\GdImage $im): ?array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        // Ohne imagealphablending(false) liefert imagecolorat() bei
        // aktiviertem Blending keinen zuverlaessigen rohen Alpha-Wert.
        imagealphablending($im, false);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                // GD-Alpha: 0 = undurchsichtig, 127 = komplett transparent.
                $alpha = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
                if ($alpha < 127) {
                    if ($x < $minX) {
                        $minX = $x;
                    }
                    if ($x > $maxX) {
                        $maxX = $x;
                    }
                    if ($y < $minY) {
                        $minY = $y;
                    }
                    if ($y > $maxY) {
                        $maxY = $y;
                    }
                }
            }
        }
        if ($maxX < 0) {
            return null;
        }
        if ($minX === 0 && $minY === 0 && $maxX === $w - 1 && $maxY === $h - 1) {
            return null;
        }
        return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
    }

    private static function downscale(\GdImage $im, int $maxDim): \GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $scale = $maxDim / max($w, $h);
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $newW, $newH, $w, $h);
        return $resized;
    }

    /**
     * Baut das fertige Box-Bild fuer den QR-Code zusammen: Logo (bereits
     * randlos zugeschnitten, siehe saveTrimmed()) mittig in ein $boxSize
     * grosses Quadrat eingepasst, mit einem WEISSEN RAHMEN, der der
     * sichtbaren Kontur des Logos folgt (nicht einem starren Rechteck) -
     * per morphologischer "Aufblaehung" der Alpha-Maske um $borderPx.
     * Alles ausserhalb der aufgeblaehten Kontur bleibt transparent, damit
     * dort die QR-Module normal durchscheinen (z.B. die Ecken bei einem
     * runden Logo). Gibt eine data:-URI zurueck, oder null, wenn GD fehlt
     * oder das Bild nicht geladen werden kann - der Aufrufer sollte dann
     * ganz auf das Logo verzichten statt einen kaputten QR-Code zu zeigen.
     */
    public static function composite(string $logoPath, string $ext, int $boxSize, int $borderPx): ?string
    {
        if (!extension_loaded('gd') || $boxSize <= 0 || !is_file($logoPath)) {
            return null;
        }
        // Wird bei jedem Aufruf des oeffentlichen, nicht angemeldeten
        // api/qr.php durchlaufen - dieselbe Decompression-Bomb-Bremse wie in
        // saveTrimmed() ist hier daher besonders wichtig (nicht nur beim
        // Upload selbst pruefen, auch bei jedem spaeteren Rendern).
        $info = @getimagesize($logoPath);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > self::MAX_UPLOAD_PIXELS) {
            return null;
        }
        $src = match ($ext) {
            'png' => @imagecreatefrompng($logoPath),
            'webp' => @imagecreatefromwebp($logoPath),
            default => @imagecreatefromjpeg($logoPath),
        };
        if ($src === false) {
            return null;
        }
        imagesavealpha($src, true);

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $innerMax = max(1, $boxSize - $borderPx * 2);
        $scale = min($innerMax / $srcW, $innerMax / $srcH);
        $fitW = max(1, (int) round($srcW * $scale));
        $fitH = max(1, (int) round($srcH * $scale));
        $offX = (int) round(($boxSize - $fitW) / 2);
        $offY = (int) round(($boxSize - $fitH) / 2);

        // Logo skaliert auf eine box-grosse transparente Leinwand kopieren -
        // wird gleich zweifach gebraucht: einmal fuer die Alpha-Maske (Basis
        // fuer den Rahmen), einmal als das eigentliche sichtbare Logo.
        $fitted = self::blankCanvas($boxSize);
        imagealphablending($fitted, false);
        imagecopyresampled($fitted, $src, $offX, $offY, 0, 0, $fitW, $fitH, $srcW, $srcH);

        $mask = self::alphaMask($fitted, $boxSize);
        $dilated = $borderPx > 0 ? self::dilateMask($mask, $boxSize, $boxSize, $borderPx) : $mask;

        $out = self::blankCanvas($boxSize);
        imagealphablending($out, false);
        $white = imagecolorallocatealpha($out, 255, 255, 255, 0);
        for ($y = 0; $y < $boxSize; $y++) {
            for ($x = 0; $x < $boxSize; $x++) {
                if ($dilated[$y][$x]) {
                    imagesetpixel($out, $x, $y, $white);
                }
            }
        }

        // Logo mit sauberer Kantenglaettung ueber den weissen Rahmen legen
        // (Alpha-Blending an, damit halbtransparente Kanten des Logos
        // korrekt mit dem Weiss darunter vermischt werden).
        imagealphablending($out, true);
        imagecopy($out, $fitted, 0, 0, 0, 0, $boxSize, $boxSize);

        imagesavealpha($out, true);
        ob_start();
        imagepng($out);
        $bytes = ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private static function blankCanvas(int $size): \GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        return $im;
    }

    /** @return bool[][] true = Pixel gehoert zum sichtbaren Logo (nicht komplett transparent) */
    private static function alphaMask(\GdImage $im, int $size): array
    {
        imagealphablending($im, false);
        $mask = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $alpha = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
                $row[] = $alpha < 127;
            }
            $mask[] = $row;
        }
        return $mask;
    }

    /**
     * Morphologische Dilatation ("Aufblaehen") der Maske um $radius Pixel,
     * per zweistufiger Chamfer-Distanztransformation (schnelle Naeherung an
     * eine euklidische Distanz, O(w*h) statt einer teuren Pruefung jedes
     * Pixels gegen einen Kreis-Kernel) - ergibt einen der Bildkontur
     * folgenden, gleichmaessig dicken Rahmen statt eines rechteckigen
     * Kastens (bei einem runden Logo also einen runden Rahmen).
     *
     * @param bool[][] $mask
     * @return bool[][]
     */
    private static function dilateMask(array $mask, int $w, int $h, int $radius): array
    {
        $inf = (float) ($w + $h);
        $dist = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $row[] = $mask[$y][$x] ? 0.0 : $inf;
            }
            $dist[] = $row;
        }

        $d1 = 1.0;
        $d2 = 1.41421356;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $v = $dist[$y][$x];
                if ($x > 0) {
                    $v = min($v, $dist[$y][$x - 1] + $d1);
                }
                if ($y > 0) {
                    $v = min($v, $dist[$y - 1][$x] + $d1);
                    if ($x > 0) {
                        $v = min($v, $dist[$y - 1][$x - 1] + $d2);
                    }
                    if ($x < $w - 1) {
                        $v = min($v, $dist[$y - 1][$x + 1] + $d2);
                    }
                }
                $dist[$y][$x] = $v;
            }
        }
        for ($y = $h - 1; $y >= 0; $y--) {
            for ($x = $w - 1; $x >= 0; $x--) {
                $v = $dist[$y][$x];
                if ($x < $w - 1) {
                    $v = min($v, $dist[$y][$x + 1] + $d1);
                }
                if ($y < $h - 1) {
                    $v = min($v, $dist[$y + 1][$x] + $d1);
                    if ($x < $w - 1) {
                        $v = min($v, $dist[$y + 1][$x + 1] + $d2);
                    }
                    if ($x > 0) {
                        $v = min($v, $dist[$y + 1][$x - 1] + $d2);
                    }
                }
                $dist[$y][$x] = $v;
            }
        }

        $out = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $row[] = $dist[$y][$x] <= $radius;
            }
            $out[] = $row;
        }
        return $out;
    }
}
