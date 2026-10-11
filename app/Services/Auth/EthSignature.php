<?php

namespace App\Services\Auth;

use kornrunner\Keccak;
use phpseclib3\Crypt\EC\Curves\secp256k1;
use phpseclib3\Math\BigInteger;
use Throwable;

/**
 * กู้ที่อยู่กระเป๋าจากลายเซ็น personal_sign (EIP-191) — ทำแบบเดียวกับ ecrecover
 *
 *   hash = keccak256("\x19Ethereum Signed Message:\n" + len(message) + message)
 *   ลายเซ็น 65 ไบต์ = r(32) + s(32) + v(1) · v รับทั้ง 27/28 และ 0/1
 *   Q = r⁻¹(s·R − e·G) แล้วที่อยู่ = 20 ไบต์ท้ายของ keccak256(Q.x ‖ Q.y)
 *
 * คณิตศาสตร์เส้นโค้งใช้ phpseclib3 (pure PHP — ใช้ GMP/BCMath ถ้ามี ไม่บังคับ ext ใด ๆ)
 */
final class EthSignature
{
    public static function personalHash(string $message): string
    {
        return Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256, true);
    }

    /** คืนที่อยู่ตัวพิมพ์เล็ก หรือ null ถ้าลายเซ็นผิดรูปแบบ/กู้ไม่ได้ */
    public static function recover(string $message, string $signature): ?string
    {
        if (! preg_match('/^0x[0-9a-fA-F]{130}$/', $signature)) {
            return null;
        }
        $sig = substr($signature, 2);
        $v = hexdec(substr($sig, 128, 2));
        if ($v >= 27) {
            $v -= 27;
        }
        if ($v !== 0 && $v !== 1) {
            return null;
        }

        try {
            $curve = new secp256k1;
            $n = $curve->getOrder();
            $zero = new BigInteger(0);
            $r = new BigInteger(substr($sig, 0, 64), 16);
            $s = new BigInteger(substr($sig, 64, 64), 16);
            if ($r->compare($zero) <= 0 || $r->compare($n) >= 0 || $s->compare($zero) <= 0 || $s->compare($n) >= 0) {
                return null;
            }

            // R = จุดบนเส้นโค้งที่ x = r และ y มีความคู่/คี่ตาม v
            $R = $curve->derivePoint(chr(2 + $v).str_pad($r->toBytes(), 32, "\0", STR_PAD_LEFT));

            $e = new BigInteger(self::personalHash($message), 256);
            $rInv = $r->modInverse($n);
            [, $eMod] = $e->divide($n);
            [, $u1] = $n->subtract($eMod)->multiply($rInv)->divide($n); // −e·r⁻¹ mod n
            [, $u2] = $s->multiply($rInv)->divide($n);                  //  s·r⁻¹ mod n

            $Q = $curve->multiplyAddPoints(
                [$curve->getBasePoint(), $R],
                [$curve->convertInteger($u1), $curve->convertInteger($u2)],
            );
            if (! isset($Q[0], $Q[1])) {
                return null; // จุดที่อนันต์ — ลายเซ็นใช้ไม่ได้
            }

            $x = str_pad($Q[0]->toBigInteger()->toBytes(), 32, "\0", STR_PAD_LEFT);
            $y = str_pad($Q[1]->toBigInteger()->toBytes(), 32, "\0", STR_PAD_LEFT);

            return '0x'.substr(Keccak::hash($x.$y, 256), -40);
        } catch (Throwable) {
            return null;
        }
    }
}
