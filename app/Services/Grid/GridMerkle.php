<?php

namespace App\Services\Grid;

use App\Services\Chain\Abi;
use InvalidArgumentException;

/**
 * Merkle tree ของรอบจ่าย — ต้องตรงกับสัญญา GridRewards (TPIX-Coin contracts/src/launch/GridRewards.sol)
 *
 *  leaf = keccak256(keccak256(abi.encode(address contract, uint256 round, address account, uint256 amount)))
 *  คู่  = keccak256(ค่าน้อยกว่า ‖ ค่ามากกว่า)  (OpenZeppelin MerkleProof แบบเรียงคู่)
 *  ชั้นที่เหลือตัวเดียว ยกขึ้นชั้นถัดไปเลย (ไม่ hash กับตัวเอง) → proof ของตัวนั้นข้ามชั้นนั้น
 *
 * ตรวจข้ามภาษาแล้ว: tests/Fixtures/grid-rewards-php.json ถูกรับจริงในเทสต์ Hardhat ของสัญญา
 */
final class GridMerkle
{
    /** @var list<list<string>> ชั้นของต้นไม้ (hex ตัวเล็กไม่มี 0x) จาก leaf ขึ้นไป */
    private array $layers;

    /** @param list<string> $leaves 0x + 64 hex */
    public function __construct(array $leaves)
    {
        if ($leaves === []) {
            throw new InvalidArgumentException('Merkle tree needs at least one leaf');
        }
        $cur = array_map(fn ($l) => strtolower(substr($l, 2)), $leaves);
        $this->layers = [$cur];
        while (count($cur) > 1) {
            $next = [];
            for ($i = 0; $i < count($cur); $i += 2) {
                $next[] = isset($cur[$i + 1]) ? self::pair($cur[$i], $cur[$i + 1]) : $cur[$i];
            }
            $this->layers[] = $next;
            $cur = $next;
        }
    }

    public static function leaf(string $contract, int $round, string $account, string $amount): string
    {
        $encoded = Abi::encodeAddress($contract).Abi::encodeUint($round).Abi::encodeAddress($account).Abi::encodeUint($amount);
        $inner = Abi::keccak((string) hex2bin($encoded));

        return '0x'.Abi::keccak((string) hex2bin($inner));
    }

    public function root(): string
    {
        return '0x'.$this->layers[count($this->layers) - 1][0];
    }

    /** @return list<string> */
    public function proof(int $index): array
    {
        $out = [];
        for ($l = 0; $l < count($this->layers) - 1; $l++) {
            $sib = $index ^ 1;
            if (isset($this->layers[$l][$sib])) {
                $out[] = '0x'.$this->layers[$l][$sib];
            }
            $index >>= 1;
        }

        return $out;
    }

    /** ตรวจ proof แบบเดียวกับ MerkleProof.verify ของสัญญา */
    public static function verify(array $proof, string $root, string $leaf): bool
    {
        $h = strtolower(substr($leaf, 2));
        foreach ($proof as $p) {
            $h = self::pair($h, strtolower(substr($p, 2)));
        }

        return '0x'.$h === strtolower($root);
    }

    private static function pair(string $a, string $b): string
    {
        // hex ยาวเท่ากัน ตัวพิมพ์เล็ก → เทียบสตริง = เทียบค่าตัวเลข
        return strcmp($a, $b) < 0 ? Abi::keccak((string) hex2bin($a.$b)) : Abi::keccak((string) hex2bin($b.$a));
    }
}
