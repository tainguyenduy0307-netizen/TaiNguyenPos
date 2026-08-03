<?php

namespace App\Libraries;

use RuntimeException;

class SimpleZip
{
    public static function read(string $path): array
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Cannot read zip file.');
        }

        $eocdPosition = self::findEndOfCentralDirectory($data);
        $eocd = unpack('Vsig/vdisk/vcdisk/ventriesDisk/ventries/Vsize/Voffset/vcommentLength', substr($data, $eocdPosition, 22));
        $position = $eocd['offset'];
        $entries = [];

        for ($i = 0; $i < $eocd['entries']; $i++) {
            $header = unpack(
                'Vsig/vmade/vneeded/vflag/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdisk/vinternalAttributes/VexternalAttributes/VlocalOffset',
                substr($data, $position, 46)
            );
            if ($header['sig'] !== 0x02014b50) {
                throw new RuntimeException('Invalid zip central directory.');
            }

            $name = substr($data, $position + 46, $header['nameLength']);
            $local = unpack('Vsig/vneeded/vflag/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($data, $header['localOffset'], 30));
            if ($local['sig'] !== 0x04034b50) {
                throw new RuntimeException('Invalid zip local header.');
            }

            $contentOffset = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($data, $contentOffset, $header['compressedSize']);
            $entries[$name] = self::inflate($compressed, $header['method']);

            $position += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
        }

        return $entries;
    }

    public static function create(string $path, array $entries): void
    {
        $localData = '';
        $centralData = '';
        $offset = 0;

        foreach ($entries as $name => $content) {
            $crc = (int) hexdec(hash('crc32b', $content));
            $size = strlen($content);
            $nameLength = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0
            );
            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            );

            $localData .= $localHeader . $name . $content;
            $centralData .= $centralHeader . $name;
            $offset += strlen($localHeader) + $nameLength + $size;
        }

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($entries),
            count($entries),
            strlen($centralData),
            strlen($localData),
            0
        );

        if (file_put_contents($path, $localData . $centralData . $end) === false) {
            throw new RuntimeException('Cannot write zip file.');
        }
    }

    private static function inflate(string $data, int $method): string
    {
        if ($method === 0) {
            return $data;
        }
        if ($method === 8) {
            $inflated = gzinflate($data);
            if ($inflated === false) {
                throw new RuntimeException('Cannot inflate zip entry.');
            }

            return $inflated;
        }

        throw new RuntimeException('Unsupported zip compression method: ' . $method);
    }

    private static function findEndOfCentralDirectory(string $data): int
    {
        $minimum = max(0, strlen($data) - 65557);
        for ($position = strlen($data) - 22; $position >= $minimum; $position--) {
            if (substr($data, $position, 4) === "\x50\x4b\x05\x06") {
                return $position;
            }
        }

        throw new RuntimeException('Invalid zip file.');
    }
}
