<?php

namespace App\Metadata;

/**
 * Minimaler, abhaengigkeitsfreier Tag-Reader fuer MP3 (ID3v1 + ID3v2.2/2.3/2.4)
 * inkl. grober Dauer-/Bitrate-Schaetzung ueber den MPEG-Frame-Header und
 * einen eventuell vorhandenen Xing/Info-VBR-Header. Deckt keine exotischen
 * Sonderfaelle ab, ist fuer normale MP3-Dateien aus gaengigen Encodern aber
 * ausreichend genau.
 */
final class Id3Reader
{
    private const V1_GENRES = [
        'Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop',
        'Jazz', 'Metal', 'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap',
        'Reggae', 'Rock', 'Techno', 'Industrial', 'Alternative', 'Ska', 'Death Metal', 'Pranks',
        'Soundtrack', 'Euro-Techno', 'Ambient', 'Trip-Hop', 'Vocal', 'Jazz+Funk', 'Fusion', 'Trance',
        'Classical', 'Instrumental', 'Acid', 'House', 'Game', 'Sound Clip', 'Gospel', 'Noise',
        'AlternRock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative', 'Instrumental Pop', 'Instrumental Rock',
        'Ethnic', 'Gothic', 'Darkwave', 'Techno-Industrial', 'Electronic', 'Pop-Folk', 'Eurodance', 'Dream',
        'Southern Rock', 'Comedy', 'Cult', 'Gangsta', 'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle',
        'Native American', 'Cabaret', 'New Wave', 'Psychedelic', 'Rave', 'Showtunes', 'Trailer', 'Lo-Fi',
        'Tribal', 'Acid Punk', 'Acid Jazz', 'Polka', 'Retro', 'Musical', 'Rock & Roll', 'Hard Rock',
    ];

    private const MPEG1_BITRATES = [null, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, null];
    private const MPEG2_BITRATES = [null, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, null];
    private const MPEG1_SAMPLERATES = [44100, 48000, 32000, null];
    private const MPEG2_SAMPLERATES = [22050, 24000, 16000, null];
    private const MPEG25_SAMPLERATES = [11025, 12000, 8000, null];

    public function read(string $path): array
    {
        $result = [
            'title' => null, 'artist' => null, 'album' => null, 'album_artist' => null,
            'genre' => null, 'track_no' => null, 'disc_no' => null, 'year' => null,
            'duration_seconds' => null, 'bitrate' => null,
        ];

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return $result;
        }

        try {
            $filesize = filesize($path) ?: 0;
            $id3v2Size = 0;

            $header = fread($fh, 10);
            if ($header !== false && strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
                $version = ord($header[3]);
                $flags = ord($header[5]);
                $size = self::synchsafeToInt(substr($header, 6, 4));
                $id3v2Size = 10 + $size;

                $bodyStart = 10;
                if ($flags & 0x40) { // extended header vorhanden
                    $extHeaderRaw = fread($fh, 4);
                    $extSize = $version >= 4
                        ? self::synchsafeToInt($extHeaderRaw)
                        : self::beToInt($extHeaderRaw);
                    fseek($fh, $extSize - 4, SEEK_CUR);
                }

                $body = fread($fh, max(0, $size - ($version >= 4 || !($flags & 0x40) ? 0 : 0)));
                if ($body !== false) {
                    $tags = $version <= 2
                        ? self::parseV22Frames($body)
                        : self::parseV23V24Frames($body, $version);
                    $result = self::applyTags($result, $tags);
                }
            }

            // ID3v1 (letzte 128 Bytes) nur zum Auffuellen fehlender Felder nutzen.
            $hasId3v1 = false;
            if ($filesize >= 128) {
                fseek($fh, $filesize - 128);
                $tail = fread($fh, 128);
                if ($tail !== false && substr($tail, 0, 3) === 'TAG') {
                    $hasId3v1 = true;
                    $v1 = self::parseV1($tail);
                    foreach ($v1 as $k => $v) {
                        if ($v !== null && empty($result[$k])) {
                            $result[$k] = $v;
                        }
                    }
                }
            }

            // Dauer/Bitrate ueber den ersten MPEG-Frame nach dem ID3v2-Tag.
            $audioEnd = $filesize - ($hasId3v1 ? 128 : 0);
            $frameInfo = self::analyzeAudio($fh, $audioEnd, $id3v2Size);
            if ($frameInfo !== null) {
                $result['duration_seconds'] = $frameInfo['duration'];
                $result['bitrate'] = $frameInfo['bitrate'];
            }
        } finally {
            fclose($fh);
        }

        return $result;
    }

    private static function synchsafeToInt(string $bytes): int
    {
        $b = array_values(unpack('C4', str_pad($bytes, 4, "\0")));
        return ($b[0] << 21) | ($b[1] << 14) | ($b[2] << 7) | $b[3];
    }

    private static function beToInt(string $bytes): int
    {
        $b = array_values(unpack('C4', str_pad($bytes, 4, "\0")));
        return ($b[0] << 24) | ($b[1] << 16) | ($b[2] << 8) | $b[3];
    }

    private static function parseV23V24Frames(string $body, int $version): array
    {
        $tags = [];
        $len = strlen($body);
        $pos = 0;
        while ($pos + 10 <= $len) {
            $frameId = substr($body, $pos, 4);
            if ($frameId === "\0\0\0\0" || trim($frameId) === '') {
                break;
            }
            $sizeBytes = substr($body, $pos + 4, 4);
            $frameSize = $version >= 4 ? self::synchsafeToInt($sizeBytes) : self::beToInt($sizeBytes);
            $pos += 10;
            if ($frameSize <= 0 || $pos + $frameSize > $len) {
                break;
            }
            $frameData = substr($body, $pos, $frameSize);
            $pos += $frameSize;

            if ($frameId[0] === 'T') {
                $tags[$frameId] = self::decodeTextFrame($frameData);
            }
        }
        return $tags;
    }

    private static function parseV22Frames(string $body): array
    {
        $tags = [];
        $len = strlen($body);
        $pos = 0;
        // v2.2 mappt 3-stellige Frame-IDs auf die gaengigen 4-stelligen um.
        $map = ['TT2' => 'TIT2', 'TP1' => 'TPE1', 'TAL' => 'TALB', 'TYE' => 'TYER', 'TRK' => 'TRCK', 'TCO' => 'TCON', 'TPA' => 'TPOS'];
        while ($pos + 6 <= $len) {
            $frameId = substr($body, $pos, 3);
            if (trim($frameId) === '') {
                break;
            }
            $sizeBytes = "\0" . substr($body, $pos + 3, 3);
            $frameSize = self::beToInt($sizeBytes);
            $pos += 6;
            if ($frameSize <= 0 || $pos + $frameSize > $len) {
                break;
            }
            $frameData = substr($body, $pos, $frameSize);
            $pos += $frameSize;
            if (isset($map[$frameId])) {
                $tags[$map[$frameId]] = self::decodeTextFrame($frameData);
            }
        }
        return $tags;
    }

    private static function decodeTextFrame(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $encByte = ord($data[0]);
        $text = substr($data, 1);
        $decoded = match ($encByte) {
            1 => self::decodeUtf16($text, true),
            2 => self::decodeUtf16($text, false),
            3 => $text,
            default => @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text) ?: $text,
        };
        return trim(rtrim($decoded, "\0"));
    }

    private static function decodeUtf16(string $text, bool $withBom): string
    {
        if ($withBom && strlen($text) >= 2) {
            $bom = substr($text, 0, 2);
            $body = substr($text, 2);
            $encoding = $bom === "\xFE\xFF" ? 'UTF-16BE' : 'UTF-16LE';
            return @iconv($encoding, 'UTF-8//IGNORE', $body) ?: '';
        }
        return @iconv('UTF-16BE', 'UTF-8//IGNORE', $text) ?: '';
    }

    private static function applyTags(array $result, array $tags): array
    {
        if (!empty($tags['TIT2'])) {
            $result['title'] = $tags['TIT2'];
        }
        if (!empty($tags['TPE1'])) {
            $result['artist'] = $tags['TPE1'];
        }
        if (!empty($tags['TALB'])) {
            $result['album'] = $tags['TALB'];
        }
        if (!empty($tags['TPE2'])) {
            $result['album_artist'] = $tags['TPE2'];
        }
        if (!empty($tags['TRCK'])) {
            $result['track_no'] = (int) explode('/', $tags['TRCK'])[0];
        }
        if (!empty($tags['TPOS'])) {
            $result['disc_no'] = (int) explode('/', $tags['TPOS'])[0];
        }
        if (!empty($tags['TYER'])) {
            $result['year'] = (int) substr($tags['TYER'], 0, 4);
        }
        if (!empty($tags['TDRC'])) {
            $result['year'] = (int) substr($tags['TDRC'], 0, 4);
        }
        if (!empty($tags['TCON'])) {
            $result['genre'] = self::resolveGenre($tags['TCON']);
        }
        return $result;
    }

    private static function resolveGenre(string $raw): string
    {
        if (preg_match('/^\((\d+)\)$/', trim($raw), $m) || preg_match('/^\((\d+)\)/', trim($raw), $m)) {
            $idx = (int) $m[1];
            if (isset(self::V1_GENRES[$idx])) {
                return self::V1_GENRES[$idx];
            }
        }
        return $raw;
    }

    private static function parseV1(string $tail): array
    {
        $title = trim(rtrim(substr($tail, 3, 30), "\0 "));
        $artist = trim(rtrim(substr($tail, 33, 30), "\0 "));
        $album = trim(rtrim(substr($tail, 63, 30), "\0 "));
        $year = trim(rtrim(substr($tail, 93, 4), "\0 "));
        $comment = substr($tail, 97, 30);
        $genreIdx = ord($tail[126] ?? "\0");

        $trackNo = null;
        if (strlen($comment) >= 30 && $comment[28] === "\0" && ord($comment[29]) > 0) {
            $trackNo = ord($comment[29]);
        }

        return [
            'title' => $title !== '' ? $title : null,
            'artist' => $artist !== '' ? $artist : null,
            'album' => $album !== '' ? $album : null,
            'album_artist' => null,
            'genre' => self::V1_GENRES[$genreIdx] ?? null,
            'track_no' => $trackNo,
            'disc_no' => null,
            'year' => ctype_digit($year) ? (int) $year : null,
        ];
    }

    /**
     * Sucht den ersten gueltigen MPEG-Audio-Frame, liest ggf. einen
     * Xing/Info-VBR-Header aus und schaetzt sonst per CBR-Bitrate die Dauer.
     */
    private static function analyzeAudio($fh, int $filesize, int $startOffset): ?array
    {
        fseek($fh, $startOffset);
        $chunk = fread($fh, 65536);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $len = strlen($chunk);
        for ($i = 0; $i < $len - 4; $i++) {
            if (ord($chunk[$i]) !== 0xFF || (ord($chunk[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }
            $b1 = ord($chunk[$i + 1]);
            $b2 = ord($chunk[$i + 2]);
            $versionBits = ($b1 >> 3) & 0x03; // 00=2.5 01=res 10=2 11=1
            $layerBits = ($b1 >> 1) & 0x03;   // 11=I 10=II 01=III 00=res
            if ($versionBits === 1 || $layerBits === 0) {
                continue;
            }
            $bitrateIdx = ($b2 >> 4) & 0x0F;
            $samplerateIdx = ($b2 >> 2) & 0x03;
            $padding = ($b2 >> 1) & 0x01;

            $isV1 = $versionBits === 3;
            $bitrateTable = $isV1 ? self::MPEG1_BITRATES : self::MPEG2_BITRATES;
            $samplerateTable = $isV1 ? self::MPEG1_SAMPLERATES : ($versionBits === 2 ? self::MPEG2_SAMPLERATES : self::MPEG25_SAMPLERATES);

            $bitrate = $bitrateTable[$bitrateIdx] ?? null;
            $samplerate = $samplerateTable[$samplerateIdx] ?? null;
            if ($bitrate === null || $samplerate === null) {
                continue;
            }

            $samplesPerFrame = $isV1 ? 1152 : 576;
            $frameSize = intdiv($samplesPerFrame * ($bitrate * 1000), $samplerate * 8) + $padding;
            if ($frameSize < 21) {
                continue;
            }

            // Xing/Info-Header liegt direkt nach den "Side Info"-Bytes.
            $sideInfoSize = $isV1 ? (($b2 >> 6) === 3 ? 17 : 32) : (($b2 >> 6) === 3 ? 9 : 17);
            $xingOffset = $i + 4 + $sideInfoSize;
            if ($xingOffset + 8 <= $len) {
                $tag = substr($chunk, $xingOffset, 4);
                if ($tag === 'Xing' || $tag === 'Info') {
                    $flags = self::beToInt(substr($chunk, $xingOffset + 4, 4));
                    if ($flags & 0x01) { // Frames-Feld vorhanden
                        $numFrames = self::beToInt(substr($chunk, $xingOffset + 8, 4));
                        if ($numFrames > 0) {
                            $duration = (int) round(($numFrames * $samplesPerFrame) / $samplerate);
                            return ['duration' => $duration, 'bitrate' => $bitrate];
                        }
                    }
                }
            }

            // Kein VBR-Header gefunden -> CBR-Schaetzung ueber die Dateigroesse.
            $audioBytes = max(0, $filesize - $startOffset);
            $duration = $bitrate > 0 ? (int) round($audioBytes * 8 / ($bitrate * 1000)) : null;
            return ['duration' => $duration, 'bitrate' => $bitrate];
        }
        return null;
    }
}
