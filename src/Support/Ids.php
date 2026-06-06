<?php

namespace AgentPing\Laravel\Support;

class Ids
{
    private const REGION_PATTERN = '/^apk_([a-z]{2})_[0-9a-f]{32}$/';

    private const ID_PATTERN = '/^[a-z]+_[a-z]{2}_[0-9a-f]{32}$/';

    private static int $lastMs = 0;

    private static int $lastSeq = 0;

    public static function uuid7Hex(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs) {
            $ms = self::$lastMs;
            self::$lastSeq++;
        } else {
            self::$lastMs = $ms;
            self::$lastSeq = 0;
        }
        $seq = self::$lastSeq & 0x0FFF;

        $rand = random_bytes(10);

        $tsBytes = chr(($ms >> 40) & 0xFF)
            . chr(($ms >> 32) & 0xFF)
            . chr(($ms >> 24) & 0xFF)
            . chr(($ms >> 16) & 0xFF)
            . chr(($ms >> 8) & 0xFF)
            . chr($ms & 0xFF);

        $rand[0] = chr(0x70 | (($seq >> 8) & 0x0F));
        $rand[1] = chr($seq & 0xFF);
        $rand[2] = chr(0x80 | (ord($rand[2]) & 0x3F));

        return bin2hex($tsBytes . $rand);
    }

    public static function extractRegion(?string $apiKey): string
    {
        if ($apiKey === null || $apiKey === '') {
            return 'eu';
        }
        if (preg_match(self::REGION_PATTERN, $apiKey, $m) !== 1) {
            return 'eu';
        }

        return $m[1];
    }

    public static function newId(string $prefix, string $region): string
    {
        return $prefix . '_' . $region . '_' . self::uuid7Hex();
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return preg_match(self::ID_PATTERN, $value) === 1;
    }
}
