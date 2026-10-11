<?php

namespace App\Services\Chain;

use kornrunner\Keccak;

/**
 * ที่อยู่ EVM: ตรวจรูปแบบ + checksum ตาม EIP-55
 */
final class Address
{
    public const ZERO = '0x0000000000000000000000000000000000000000';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/', $value) === 1;
    }

    /** ตัวพิมพ์เล็กล้วน/ใหญ่ล้วน = ไม่มี checksum (ยอมรับ) · ถ้าผสม ต้องตรง EIP-55 */
    public static function hasValidChecksum(string $address): bool
    {
        if (! self::isValid($address)) {
            return false;
        }
        $body = substr($address, 2);
        if ($body === strtolower($body) || $body === strtoupper($body)) {
            return true;
        }

        return self::checksum($address) === $address;
    }

    public static function checksum(string $address): string
    {
        $lower = strtolower(substr($address, 2));
        $hash = Keccak::hash($lower, 256);
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $out .= hexdec($hash[$i]) >= 8 ? strtoupper($lower[$i]) : $lower[$i];
        }

        return $out;
    }

    public static function lower(?string $address): ?string
    {
        return self::isValid($address) ? strtolower((string) $address) : null;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::isValid($a) && self::isValid($b) && strtolower((string) $a) === strtolower((string) $b);
    }

    public static function isZero(?string $address): bool
    {
        return ! self::isValid($address) || strtolower((string) $address) === self::ZERO;
    }
}
