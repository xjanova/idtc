<?php

namespace App\Services\Chain;

use InvalidArgumentException;
use kornrunner\Keccak;
use phpseclib3\Math\BigInteger;
use UnexpectedValueException;

/**
 * ABI ขนาดเล็กสำหรับอ่านสัญญาฝั่งเซิร์ฟเวอร์ (เฉพาะชนิดที่หลังบ้านใช้)
 *
 * - selector/topic คำนวณจาก keccak256 ของลายเซ็นฟังก์ชันจริง ไม่ฮาร์ดโค้ด
 * - encode: address, uint*, bool, bytes32
 * - decode: uint*, address, bool, bytes32, tuple ของชนิดคงที่ (คือ word ต่อกัน) และ string
 */
final class Abi
{
    /** @var array<string, string> */
    private static array $selectors = [];

    /** keccak256 แบบ hex ไม่มี 0x */
    public static function keccak(string $data): string
    {
        return Keccak::hash($data, 256);
    }

    /** function selector เช่น selector('balanceOf(address)') = '0x70a08231' */
    public static function selector(string $signature): string
    {
        $sig = self::normalize($signature);

        return self::$selectors[$sig] ??= '0x'.substr(self::keccak($sig), 0, 8);
    }

    /** topic0 ของ event เช่น topic('Purchased(address,uint256,uint256)') */
    public static function topic(string $eventSignature): string
    {
        return '0x'.self::keccak(self::normalize($eventSignature));
    }

    public static function normalize(string $signature): string
    {
        return (string) preg_replace('/\s+/', '', $signature);
    }

    /**
     * แยก "name(type1,type2)" → ['name', ['type1', 'type2']] (เฉพาะชนิดคงที่ ไม่มี tuple ซ้อน)
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function parseSignature(string $signature): array
    {
        $sig = self::normalize($signature);
        if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\(([^()]*)\)$/', $sig, $m)) {
            throw new InvalidArgumentException("Bad function signature: {$signature}");
        }

        return [$m[1], $m[2] === '' ? [] : explode(',', $m[2])];
    }

    /**
     * calldata ของการเรียกฟังก์ชัน: selector + อาร์กิวเมนต์ที่ encode แล้ว
     *
     * @param  list<mixed>  $args
     */
    public static function encodeCall(string $signature, array $args = []): string
    {
        [, $types] = self::parseSignature($signature);
        if (count($types) !== count($args)) {
            throw new InvalidArgumentException('Argument count does not match '.$signature);
        }
        $data = substr(self::selector($signature), 2);
        foreach ($types as $i => $type) {
            $data .= self::encodeValue($type, $args[$i]);
        }

        return '0x'.$data;
    }

    public static function encodeValue(string $type, mixed $value): string
    {
        return match (true) {
            $type === 'address' => self::encodeAddress((string) $value),
            $type === 'bool' => self::encodeUint($value ? 1 : 0),
            $type === 'bytes32' => self::encodeBytes32((string) $value),
            (bool) preg_match('/^uint(\d*)$/', $type) => self::encodeUint(is_int($value) ? $value : (string) $value),
            default => throw new InvalidArgumentException("Unsupported ABI type: {$type}"),
        };
    }

    public static function encodeAddress(string $address): string
    {
        if (! Address::isValid($address)) {
            throw new InvalidArgumentException('Invalid address');
        }

        return str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    public static function encodeUint(int|string $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('uint must be a non-negative integer');
        }
        $hex = ltrim((new BigInteger($value, 10))->toHex(), '0');
        if (strlen($hex) > 64) {
            throw new InvalidArgumentException('uint overflows 256 bits');
        }

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function encodeBytes32(string $hex): string
    {
        $h = strtolower(preg_replace('/^0x/i', '', $hex) ?? '');
        if (! preg_match('/^[0-9a-f]{0,64}$/', $h)) {
            throw new InvalidArgumentException('Invalid bytes32');
        }

        return str_pad($h, 64, '0', STR_PAD_RIGHT);
    }

    /**
     * แบ่งข้อมูลที่สัญญาตอบกลับเป็น word ละ 32 ไบต์ (hex ไม่มี 0x)
     *
     * @return list<string>
     */
    public static function words(string $data): array
    {
        $h = strtolower(preg_replace('/^0x/i', '', trim($data)) ?? '');
        if ($h === '') {
            return [];
        }
        if (! preg_match('/^[0-9a-f]*$/', $h) || strlen($h) % 64 !== 0) {
            throw new UnexpectedValueException('Return data is not ABI-encoded');
        }

        return str_split($h, 64);
    }

    /** uint ทุกขนาด → เลขฐานสิบเป็นข้อความ (ไม่ล้นแม้ 2^256-1) */
    public static function decodeUint(string $word): string
    {
        $h = ltrim(strtolower(preg_replace('/^0x/i', '', $word) ?? ''), '0');

        return $h === '' ? '0' : (new BigInteger($h, 16))->toString();
    }

    public static function decodeAddress(string $word): string
    {
        $h = strtolower(preg_replace('/^0x/i', '', $word) ?? '');

        return '0x'.substr(str_pad($h, 64, '0', STR_PAD_LEFT), -40);
    }

    public static function decodeBool(string $word): bool
    {
        return self::decodeUint($word) !== '0';
    }

    /**
     * ถอดข้อมูลตามรายการชนิด — tuple ของชนิดคงที่ (เช่น tuple(uint256,uint32,uint32))
     * ถอดได้ด้วยการส่งชนิดย่อยเรียงกัน เพราะ ABI วางเป็น word ต่อกัน
     *
     * @param  list<string>  $types
     * @return list<mixed>
     */
    public static function decode(array $types, string $data): array
    {
        $words = self::words($data);
        if (count($words) < count($types)) {
            throw new UnexpectedValueException('Return data is shorter than expected');
        }
        $out = [];
        foreach ($types as $i => $type) {
            $out[] = match (true) {
                $type === 'address' => self::decodeAddress($words[$i]),
                $type === 'bool' => self::decodeBool($words[$i]),
                $type === 'bytes32' => '0x'.$words[$i],
                $type === 'string' => self::decodeStringAt($words, $words[$i]),
                (bool) preg_match('/^u?int(\d*)$/', $type) => self::decodeUint($words[$i]),
                default => throw new InvalidArgumentException("Unsupported ABI type: {$type}"),
            };
        }

        return $out;
    }

    /** @param list<string> $words */
    private static function decodeStringAt(array $words, string $offsetWord): string
    {
        $offset = (int) self::decodeUint($offsetWord);
        if ($offset % 32 !== 0 || ! isset($words[$offset / 32])) {
            throw new UnexpectedValueException('Bad string offset');
        }
        $start = $offset / 32;
        $length = (int) self::decodeUint($words[$start]);
        $hex = implode('', array_slice($words, $start + 1));
        if (strlen($hex) < $length * 2) {
            throw new UnexpectedValueException('String data is truncated');
        }

        return (string) hex2bin(substr($hex, 0, $length * 2));
    }
}
