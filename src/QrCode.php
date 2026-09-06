<?php

namespace App;

/**
 * Reiner PHP QR-Code-Encoder (keine Abhaengigkeiten, keine externen
 * Dienste - wichtig, damit der QR-Code auch auf einer Party ganz ohne
 * Internetverbindung erzeugt werden kann). Implementiert den oeffentlichen
 * QR-Code-Standard (ISO/IEC 18004) fuer Byte-Modus, Versionen 1-6. Das
 * reicht fuer jede realistische Wunsch-Seiten-URL (bis ~100 Byte bei
 * Fehlerkorrektur M); laengere Texte werfen eine Exception mit Hinweis,
 * die URL zu kuerzen.
 */
final class QrCode
{
    // Oeffentlich, damit Aufrufer (z.B. api/qr.php) eine bestimmte Fehler-
    // korrektur anfordern koennen - v.a. ECC_H (30%), wenn ein Logo ueber
    // die Mitte des QR-Codes gelegt werden soll (siehe svg()).
    public const ECC_L = 0;
    public const ECC_M = 1;
    public const ECC_Q = 2;
    public const ECC_H = 3;

    // Faellt von der angefragten Stufe aus nur nach UNTEN (staerker->
    // schwaecher) zurueck, falls der Text bei keiner der 6 unterstuetzten
    // Versionen hineinpasst - nie nach oben, damit z.B. der alte
    // ECC_M-Standardaufruf sein Verhalten nicht durch eine ueberraschende
    // Hochstufung aendert.
    private const ECC_FALLBACK_ORDER = [self::ECC_H, self::ECC_Q, self::ECC_M, self::ECC_L];

    // Harte Obergrenze fuer die vom Logo (inkl. weissem Rand) ueberdeckte
    // Flaeche, als Prozentsatz der QR-Gesamtbreite - empirisch mit einem
    // echten QR-Decoder (jsQR) ermittelt: bei den hier unterstuetzten
    // kleinen QR-Versionen (1-6, max. ~41 Module) kippt die Lesbarkeit schon
    // deutlich frueher als die oft zitierten 20-30%, die fuer viel groessere
    // (dichtere) QR-Codes gelten - besonders bei kurzen Texten/kleinen
    // Versionen mit Alignment-Pattern nahe der Mitte. 15% blieb in allen
    // getesteten Versionen/Randgroessen sicher lesbar, mit Marge nach unten.
    private const MAX_LOGO_BOX_PERCENT = 15;

    private const ECC_INDICATOR = [self::ECC_L => 0b01, self::ECC_M => 0b00, self::ECC_Q => 0b11, self::ECC_H => 0b10];

    // [eccPerBlock, group1Blocks, group1DataLen, group2Blocks, group2DataLen]
    private const BLOCK_TABLE = [
        1 => [self::ECC_L => [7, 1, 19, 0, 0], self::ECC_M => [10, 1, 16, 0, 0], self::ECC_Q => [13, 1, 13, 0, 0], self::ECC_H => [17, 1, 9, 0, 0]],
        2 => [self::ECC_L => [10, 1, 34, 0, 0], self::ECC_M => [16, 1, 28, 0, 0], self::ECC_Q => [22, 1, 22, 0, 0], self::ECC_H => [28, 1, 16, 0, 0]],
        3 => [self::ECC_L => [15, 1, 55, 0, 0], self::ECC_M => [26, 1, 44, 0, 0], self::ECC_Q => [18, 2, 17, 0, 0], self::ECC_H => [22, 2, 13, 0, 0]],
        4 => [self::ECC_L => [20, 1, 80, 0, 0], self::ECC_M => [18, 2, 32, 0, 0], self::ECC_Q => [26, 2, 24, 0, 0], self::ECC_H => [16, 4, 9, 0, 0]],
        5 => [self::ECC_L => [26, 1, 108, 0, 0], self::ECC_M => [24, 2, 43, 0, 0], self::ECC_Q => [18, 2, 15, 2, 16], self::ECC_H => [22, 2, 11, 2, 12]],
        6 => [self::ECC_L => [18, 2, 68, 0, 0], self::ECC_M => [16, 4, 27, 0, 0], self::ECC_Q => [24, 4, 19, 0, 0], self::ECC_H => [28, 4, 15, 0, 0]],
    ];

    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];

    private static array $gfExp = [];
    private static array $gfLog = [];

    /**
     * Erzeugt fertiges SVG-Markup fuer den QR-Code des uebergebenen Texts
     * (i.d.R. eine URL). Optional mit einem Logo in der Mitte: dafuer sollte
     * $preferredEcc auf ECC_H (30% Fehlertoleranz) stehen, damit ein
     * ueberdecktes Zentrum den Code nicht unlesbar macht.
     *
     * $logoPath/$logoExt: Pfad + Dateiendung (png/jpg/webp) des bereits
     * hochgeladenen (und randlos zugeschnittenen, siehe LogoProcessor)
     * Logos, oder $logoPath=null fuer kein Logo. Die eigentliche Bild-
     * Komposition (Logo einpassen + konturfolgender weisser Rahmen)
     * uebernimmt LogoProcessor::composite() - braucht dafuer die
     * GD-Extension; ist sie nicht verfuegbar, wird kein Logo angezeigt.
     */
    public static function svg(
        string $text,
        int $scale = 8,
        int $border = 4,
        string $dark = '#000000',
        string $light = '#ffffff',
        int $preferredEcc = self::ECC_M,
        ?string $logoPath = null,
        string $logoExt = '',
        int $logoSizePercent = 20,
        int $logoBorderPx = 6
    ): string {
        [$size, $modules] = self::encode($text, $preferredEcc);
        $dim = ($size + $border * 2) * $scale;

        $rows = [];
        for ($r = 0; $r < $size; $r++) {
            $runStart = null;
            for ($c = 0; $c <= $size; $c++) {
                $on = $c < $size && $modules[$r][$c];
                if ($on && $runStart === null) {
                    $runStart = $c;
                } elseif (!$on && $runStart !== null) {
                    $x = ($runStart + $border) * $scale;
                    $y = ($r + $border) * $scale;
                    $w = ($c - $runStart) * $scale;
                    $rows[] = "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$w}\" height=\"{$scale}\"/>";
                    $runStart = null;
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $dim . '" height="' . $dim . '" shape-rendering="crispEdges">';
        $svg .= '<rect width="100%" height="100%" fill="' . htmlspecialchars($light, ENT_QUOTES) . '"/>';
        $svg .= '<g fill="' . htmlspecialchars($dark, ENT_QUOTES) . '">' . implode('', $rows) . '</g>';

        if ($logoPath !== null) {
            $logoSizePercent = max(0, min(60, $logoSizePercent));
            $logoBorderPx = max(0, min(60, $logoBorderPx));
            $logoSize = (int) round($dim * ($logoSizePercent / 100));
            $boxSize = $logoSize + $logoBorderPx * 2;

            // Harte Sicherheitsgrenze (siehe MAX_LOGO_BOX_PERCENT): egal was
            // angefragt wurde, Logo+Rand duerfen zusammen nie mehr als den
            // sicheren Anteil der QR-Flaeche einnehmen - Logo und Rand werden
            // dafuer im gleichen Verhaeltnis anteilig verkleinert. Das ist die
            // eigentliche Umsetzung von "Logo immer an die Groesse des Platzes
            // im QR-Code angepasst": kleinere QR-Codes (kurze URLs) vertragen
            // in absoluten Pixeln weniger als grosse, der Rechenweg passt sich
            // also automatisch an die tatsaechliche QR-Groesse an.
            $safeMax = (int) floor($dim * (self::MAX_LOGO_BOX_PERCENT / 100));
            if ($boxSize > $safeMax && $boxSize > 0) {
                $ratio = $safeMax / $boxSize;
                $logoSize = (int) floor($logoSize * $ratio);
                $logoBorderPx = (int) floor($logoBorderPx * $ratio);
                $boxSize = $logoSize + $logoBorderPx * 2;
            }

            // Fertiges Box-Bild (Logo + konturfolgender weisser Rahmen, alles
            // andere transparent) von LogoProcessor zusammensetzen lassen -
            // liefert null, wenn GD fehlt/das Bild kaputt ist, dann einfach
            // ganz ohne Logo weitermachen statt einen kaputten QR-Code zu
            // riskieren.
            $composited = LogoProcessor::composite($logoPath, $logoExt, $boxSize, $logoBorderPx);
            if ($composited !== null) {
                $boxPos = (int) round(($dim - $boxSize) / 2);
                $svg .= '<image x="' . $boxPos . '" y="' . $boxPos . '" width="' . $boxSize . '" height="' . $boxSize . '" href="' . htmlspecialchars($composited, ENT_QUOTES) . '"/>';
            }
        }

        $svg .= '</svg>';
        return $svg;
    }

    /** @return array{0:int,1:bool[][]} */
    public static function encode(string $text, int $preferredEcc = self::ECC_M): array
    {
        self::initGf();
        $dataLen = strlen($text);

        $startAt = array_search($preferredEcc, self::ECC_FALLBACK_ORDER, true);
        $eccOrder = $startAt === false ? [self::ECC_M, self::ECC_L] : array_slice(self::ECC_FALLBACK_ORDER, $startAt);

        foreach ($eccOrder as $ecc) {
            for ($version = 1; $version <= 6; $version++) {
                [$eccPerBlock, $g1n, $g1len, $g2n, $g2len] = self::BLOCK_TABLE[$version][$ecc];
                $totalDataCodewords = $g1n * $g1len + $g2n * $g2len;
                $capacityBits = $totalDataCodewords * 8;
                $neededBits = 4 + 8 + $dataLen * 8; // mode(4) + count(8, versions<=9) + data
                if ($neededBits <= $capacityBits) {
                    return self::buildMatrix($version, $ecc, $text);
                }
            }
        }

        throw new \RuntimeException('Text ist zu lang fuer einen QR-Code (max. ca. 100 Zeichen). Bitte eine kuerzere URL verwenden.');
    }

    private static function buildMatrix(int $version, int $ecc, string $text): array
    {
        [$eccPerBlock, $g1n, $g1len, $g2n, $g2len] = self::BLOCK_TABLE[$version][$ecc];
        $totalDataCodewords = $g1n * $g1len + $g2n * $g2len;
        $capacityBits = $totalDataCodewords * 8;

        $bits = '0100' . str_pad(decbin(strlen($text)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $remaining = $capacityBits - strlen($bits);
        $bits .= str_repeat('0', max(0, min(4, $remaining)));
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        $padBytes = ['11101100', '00010001'];
        $p = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padBytes[$p % 2];
            $p++;
        }

        $dataCodewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        $groupLens = array_merge(array_fill(0, $g1n, $g1len), array_fill(0, $g2n, $g2len));
        $divisor = self::rsGenerator($eccPerBlock);
        $blocks = [];
        $idx = 0;
        foreach ($groupLens as $len) {
            $blockData = array_slice($dataCodewords, $idx, $len);
            $idx += $len;
            $blocks[] = ['data' => $blockData, 'ecc' => self::rsEncode($blockData, $divisor)];
        }

        $maxDataLen = max(array_map(fn ($b) => count($b['data']), $blocks));
        $final = [];
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b['data'])) {
                    $final[] = $b['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($blocks as $b) {
                $final[] = $b['ecc'][$i];
            }
        }

        $finalBits = '';
        foreach ($final as $byte) {
            $finalBits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $size = 21 + ($version - 1) * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($matrix, $isFunction, $size, 0, 0);
        self::placeFinder($matrix, $isFunction, $size, 0, $size - 7);
        self::placeFinder($matrix, $isFunction, $size, $size - 7, 0);

        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0);
            $matrix[6][$i] = $val;
            $isFunction[6][$i] = true;
            $matrix[$i][6] = $val;
            $isFunction[$i][6] = true;
        }

        $coords = self::ALIGNMENT[$version];
        $low = [0, 1, 2, 3, 4, 5, 6, 7];
        $high = range($size - 8, $size - 1);
        foreach ($coords as $r) {
            foreach ($coords as $c) {
                $rLow = in_array($r, $low, true);
                $rHigh = in_array($r, $high, true);
                $cLow = in_array($c, $low, true);
                $cHigh = in_array($c, $high, true);
                if (($rLow && $cLow) || ($rLow && $cHigh) || ($rHigh && $cLow)) {
                    continue;
                }
                self::placeAlignment($matrix, $isFunction, $r, $c);
            }
        }

        self::reserveFormatInfo($isFunction, $size);
        $matrix[$size - 8][8] = true;

        self::placeData($matrix, $isFunction, $size, $finalBits);

        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $trial = self::applyMask($matrix, $isFunction, $size, $mask);
            self::placeFormatInfo($trial, $size, $ecc, $mask);
            $penalty = self::penalty($trial, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMatrix = $trial;
            }
        }

        return [$size, $bestMatrix];
    }

    private static function placeFinder(array &$matrix, array &$isFunction, int $size, int $topRow, int $topCol): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $topRow + $dr;
                $c = $topCol + $dc;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                    continue;
                }
                $isFunction[$r][$c] = true;
                if ($dr < 0 || $dr > 6 || $dc < 0 || $dc > 6) {
                    $matrix[$r][$c] = false;
                    continue;
                }
                $isBorder = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6);
                $isCenter = ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                $matrix[$r][$c] = $isBorder || $isCenter;
            }
        }
    }

    private static function placeAlignment(array &$matrix, array &$isFunction, int $centerR, int $centerC): void
    {
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $r = $centerR + $dr;
                $c = $centerC + $dc;
                $isFunction[$r][$c] = true;
                $isBorder = (abs($dr) === 2 || abs($dc) === 2);
                $matrix[$r][$c] = $isBorder || ($dr === 0 && $dc === 0);
            }
        }
    }

    private static function reserveFormatInfo(array &$isFunction, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $isFunction[8][$i] = true;
                $isFunction[$i][8] = true;
            }
        }
        for ($i = $size - 7; $i <= $size - 1; $i++) {
            $isFunction[$i][8] = true;
        }
        for ($i = $size - 8; $i <= $size - 1; $i++) {
            $isFunction[8][$i] = true;
        }
    }

    private static function placeData(array &$matrix, array $isFunction, int $size, string $bits): void
    {
        $bitIndex = 0;
        $bitLen = strlen($bits);
        $upward = true;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col = 5;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;
                for ($c = 0; $c < 2; $c++) {
                    $curCol = $col - $c;
                    if (!$isFunction[$row][$curCol]) {
                        $matrix[$row][$curCol] = $bitIndex < $bitLen ? ($bits[$bitIndex] === '1') : false;
                        $bitIndex++;
                    }
                }
            }
            $upward = !$upward;
        }
    }

    private static function applyMask(array $matrix, array $isFunction, int $size, int $mask): array
    {
        $out = $matrix;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($isFunction[$r][$c]) {
                    continue;
                }
                if (self::maskBit($mask, $r, $c)) {
                    $out[$r][$c] = !$out[$r][$c];
                }
            }
        }
        return $out;
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => (($r + $c) % 2) === 0,
            1 => ($r % 2) === 0,
            2 => ($c % 3) === 0,
            3 => (($r + $c) % 3) === 0,
            4 => ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0,
            5 => ((($r * $c) % 2) + (($r * $c) % 3)) === 0,
            6 => (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            7 => (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            default => false,
        };
    }

    private static function placeFormatInfo(array &$matrix, int $size, int $ecc, int $mask): void
    {
        $data5 = (self::ECC_INDICATOR[$ecc] << 3) | $mask;
        $bch = self::bchFormat($data5);
        $bitsArr = [];
        for ($i = 14; $i >= 0; $i--) {
            $bitsArr[] = ($bch >> $i) & 1;
        }

        $copy1 = [];
        for ($c = 0; $c <= 5; $c++) {
            $copy1[] = [8, $c];
        }
        $copy1[] = [8, 7];
        $copy1[] = [8, 8];
        $copy1[] = [7, 8];
        for ($r = 5; $r >= 0; $r--) {
            $copy1[] = [$r, 8];
        }
        foreach ($copy1 as $i => $pos) {
            $matrix[$pos[0]][$pos[1]] = (bool) $bitsArr[$i];
        }

        $copy2 = [];
        for ($r = $size - 1; $r >= $size - 7; $r--) {
            $copy2[] = [$r, 8];
        }
        for ($c = $size - 8; $c <= $size - 1; $c++) {
            $copy2[] = [8, $c];
        }
        foreach ($copy2 as $i => $pos) {
            $matrix[$pos[0]][$pos[1]] = (bool) $bitsArr[$i];
        }

        $matrix[$size - 8][8] = true;
    }

    private static function bchFormat(int $data5): int
    {
        $g = 0b10100110111;
        $value = $data5 << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($value >> $i) & 1) {
                $value ^= $g << ($i - 10);
            }
        }
        $remainder = $value & 0x3FF;
        $formatBits = ($data5 << 10) | $remainder;
        return $formatBits ^ 0b101010000010010;
    }

    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        for ($r = 0; $r < $size; $r++) {
            $runLen = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] === $matrix[$r][$c - 1]) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        for ($c = 0; $c < $size; $c++) {
            $runLen = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($matrix[$r][$c] === $matrix[$r - 1][$c]) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $matrix[$r][$c];
                if ($v === $matrix[$r][$c + 1] && $v === $matrix[$r + 1][$c] && $v === $matrix[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        $patternA = [true, false, true, true, true, false, true, false, false, false, false];
        $patternB = [false, false, false, false, true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $slice = array_slice($matrix[$r], $c, 11);
                if ($slice === $patternA || $slice === $patternB) {
                    $penalty += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $slice = [];
                for ($k = 0; $k < 11; $k++) {
                    $slice[] = $matrix[$r + $k][$c];
                }
                if ($slice === $patternA || $slice === $patternB) {
                    $penalty += 40;
                }
            }
        }

        $dark = 0;
        foreach ($matrix as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $dark++;
                }
            }
        }
        $percent = ($dark * 100) / ($size * $size);
        $penalty += (int) floor(abs($percent - 50) / 5) * 10;

        return $penalty;
    }

    private static function initGf(): void
    {
        if (!empty(self::$gfExp)) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$gfExp[(self::$gfLog[$a] + self::$gfLog[$b]) % 255];
    }

    private static function rsGenerator(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 2);
        }
        return $result;
    }

    private static function rsEncode(array $data, array $divisor): array
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }
        return $result;
    }
}
