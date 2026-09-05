<?php

namespace App\Metadata;

/**
 * Liest die STREAMINFO- (exakte Dauer) und VORBIS_COMMENT-Bloecke (Tags)
 * aus einer FLAC-Datei. Reines Binaer-Parsing nach dem offenen,
 * dokumentierten FLAC-Format (xiph.org) - keine externen Abhaengigkeiten.
 */
final class FlacReader
{
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
            if (fread($fh, 4) !== 'fLaC') {
                return $result;
            }

            $filesize = filesize($path) ?: 0;
            $audioStart = null;

            while (!feof($fh)) {
                $blockHeader = fread($fh, 4);
                if ($blockHeader === false || strlen($blockHeader) < 4) {
                    break;
                }
                $first = ord($blockHeader[0]);
                $isLast = ($first & 0x80) !== 0;
                $blockType = $first & 0x7F;
                $blockLen = (ord($blockHeader[1]) << 16) | (ord($blockHeader[2]) << 8) | ord($blockHeader[3]);

                $blockData = $blockLen > 0 ? fread($fh, $blockLen) : '';

                if ($blockType === 0) { // STREAMINFO
                    $info = self::parseStreamInfo($blockData);
                    $result['duration_seconds'] = $info['duration'];
                    if ($info['duration'] > 0) {
                        $result['bitrate'] = (int) round(($filesize * 8) / $info['duration'] / 1000);
                    }
                } elseif ($blockType === 4) { // VORBIS_COMMENT
                    $tags = self::parseVorbisComment($blockData);
                    $result = self::applyTags($result, $tags);
                }

                if ($isLast) {
                    $audioStart = ftell($fh);
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        return $result;
    }

    private static function parseStreamInfo(string $data): array
    {
        if (strlen($data) < 18) {
            return ['duration' => null];
        }
        // Byte-Layout STREAMINFO (34 Bytes gesamt):
        // 0-1 min blocksize, 2-3 max blocksize, 4-6 min framesize, 7-9 max framesize
        // 10-17: 20 bit samplerate | 3 bit channels-1 | 5 bit bits/sample-1 | 36 bit total samples
        $bytes = array_values(unpack('C8', substr($data, 10, 8)));
        $sampleRate = ($bytes[0] << 12) | ($bytes[1] << 4) | ($bytes[2] >> 4);
        $totalSamplesHigh = $bytes[3] & 0x0F;
        $totalSamples = ($totalSamplesHigh << 32)
            | ($bytes[4] << 24) | ($bytes[5] << 16) | ($bytes[6] << 8) | $bytes[7];

        $duration = $sampleRate > 0 ? (int) round($totalSamples / $sampleRate) : null;
        return ['duration' => $duration, 'sample_rate' => $sampleRate];
    }

    private static function parseVorbisComment(string $data): array
    {
        $tags = [];
        $len = strlen($data);
        $pos = 0;
        if ($pos + 4 > $len) {
            return $tags;
        }
        $vendorLen = self::readUint32LE($data, $pos);
        $pos += 4 + $vendorLen;
        if ($pos + 4 > $len) {
            return $tags;
        }
        $commentCount = self::readUint32LE($data, $pos);
        $pos += 4;

        for ($i = 0; $i < $commentCount && $pos + 4 <= $len; $i++) {
            $entryLen = self::readUint32LE($data, $pos);
            $pos += 4;
            if ($pos + $entryLen > $len) {
                break;
            }
            $entry = substr($data, $pos, $entryLen);
            $pos += $entryLen;
            $eq = strpos($entry, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtoupper(substr($entry, 0, $eq));
            $value = substr($entry, $eq + 1);
            // Mehrfach vorkommende Tags (z.B. mehrere ARTIST) - erster gewinnt.
            if (!isset($tags[$key])) {
                $tags[$key] = $value;
            }
        }
        return $tags;
    }

    private static function readUint32LE(string $data, int $pos): int
    {
        $b = array_values(unpack('C4', substr($data, $pos, 4)));
        return $b[0] | ($b[1] << 8) | ($b[2] << 16) | ($b[3] << 24);
    }

    private static function applyTags(array $result, array $tags): array
    {
        $map = [
            'TITLE' => 'title', 'ARTIST' => 'artist', 'ALBUM' => 'album',
            'ALBUMARTIST' => 'album_artist', 'ALBUM ARTIST' => 'album_artist',
            'GENRE' => 'genre',
        ];
        foreach ($map as $vorbisKey => $field) {
            if (!empty($tags[$vorbisKey])) {
                $result[$field] = $tags[$vorbisKey];
            }
        }
        if (!empty($tags['TRACKNUMBER'])) {
            $result['track_no'] = (int) explode('/', $tags['TRACKNUMBER'])[0];
        }
        if (!empty($tags['DISCNUMBER'])) {
            $result['disc_no'] = (int) explode('/', $tags['DISCNUMBER'])[0];
        }
        if (!empty($tags['DATE'])) {
            $result['year'] = (int) substr($tags['DATE'], 0, 4);
        } elseif (!empty($tags['YEAR'])) {
            $result['year'] = (int) substr($tags['YEAR'], 0, 4);
        }
        return $result;
    }
}
