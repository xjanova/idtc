<?php

namespace App\Services\Admin;

/**
 * รายการคำสั่งบนเชนที่หลังบ้านส่งได้ — ต้องตรงกับ ACTIONS ใน resources/js/admin-chain.js
 *
 * เซิร์ฟเวอร์ไม่เคยเซ็นหรือส่งธุรกรรมเอง: กระเป๋าแอดมินในเบราว์เซอร์เป็นคนเซ็น
 * แล้วส่ง tx hash มาให้บันทึก ฝั่งนี้ใช้ตรวจว่าชื่อคำสั่ง/พารามิเตอร์ถูกรูปแบบ
 * และตอนตรวจผลบนเชนจะเทียบปลายทาง + function selector ของธุรกรรมกับรายการนี้
 */
final class ChainActions
{
    /**
     * guard: ใครเรียกได้ตามสัญญา — owner | pendingOwner | beneficiary | pendingBeneficiary | anyone
     *
     * @var array<string, array{contract: string, method: string, label: string, guard: string, destructive: bool, params: array<string, string>}>
     */
    public const ALL = [
        'presale.pause' => ['contract' => 'presale', 'method' => 'pause()', 'label' => 'หยุดขาย presale ชั่วคราว', 'guard' => 'owner', 'destructive' => true, 'params' => []],
        'presale.unpause' => ['contract' => 'presale', 'method' => 'unpause()', 'label' => 'เปิดขาย presale ต่อ', 'guard' => 'owner', 'destructive' => false, 'params' => []],
        'presale.setLimits' => ['contract' => 'presale', 'method' => 'setLimits(uint256,uint256)', 'label' => 'ตั้งขั้นต่ำ/เพดานต่อกระเป๋า', 'guard' => 'owner', 'destructive' => false, 'params' => ['minPurchase' => 'uint', 'maxPerWallet' => 'uint']],
        'presale.setWindow' => ['contract' => 'presale', 'method' => 'setWindow(uint64,uint64)', 'label' => 'ตั้งช่วงเวลาขาย', 'guard' => 'owner', 'destructive' => false, 'params' => ['startTime' => 'uint', 'endTime' => 'uint']],
        'presale.setTreasury' => ['contract' => 'presale', 'method' => 'setTreasury(address)', 'label' => 'เปลี่ยนกระเป๋ารับเงิน', 'guard' => 'owner', 'destructive' => true, 'params' => ['treasury' => 'address']],
        'presale.withdrawUnsold' => ['contract' => 'presale', 'method' => 'withdrawUnsold(address)', 'label' => 'ถอนเหรียญที่ขายไม่หมด', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address']],
        'presale.transferOwnership' => ['contract' => 'presale', 'method' => 'transferOwnership(address)', 'label' => 'เริ่มโอนสิทธิ์เจ้าของสัญญา presale', 'guard' => 'owner', 'destructive' => true, 'params' => ['newOwner' => 'address']],
        'presale.acceptOwnership' => ['contract' => 'presale', 'method' => 'acceptOwnership()', 'label' => 'รับสิทธิ์เจ้าของสัญญา presale', 'guard' => 'pendingOwner', 'destructive' => false, 'params' => []],
        'vesting.release' => ['contract' => 'vesting', 'method' => 'release()', 'label' => 'ปลดงวดที่ถึงเวลา', 'guard' => 'anyone', 'destructive' => false, 'params' => []],
        'vesting.transferBeneficiary' => ['contract' => 'vesting', 'method' => 'transferBeneficiary(address)', 'label' => 'เริ่มเปลี่ยนผู้รับเหรียญที่ปลดล็อก', 'guard' => 'beneficiary', 'destructive' => true, 'params' => ['newBeneficiary' => 'address']],
        'vesting.acceptBeneficiary' => ['contract' => 'vesting', 'method' => 'acceptBeneficiary()', 'label' => 'รับสิทธิ์ผู้รับเหรียญที่ปลดล็อก', 'guard' => 'pendingBeneficiary', 'destructive' => false, 'params' => []],
        'vesting.sweepExcess' => ['contract' => 'vesting', 'method' => 'sweepExcess(address)', 'label' => 'คืนเหรียญส่วนเกินจากสัญญาล็อก', 'guard' => 'beneficiary', 'destructive' => true, 'params' => ['token' => 'address']],
    ];

    public static function has(string $action): bool
    {
        return isset(self::ALL[$action]);
    }

    /** @return array{contract: string, method: string, label: string, guard: string, destructive: bool, params: array<string, string>}|null */
    public static function get(string $action): ?array
    {
        return self::ALL[$action] ?? null;
    }

    public static function label(string $action): ?string
    {
        return self::ALL[$action]['label'] ?? null;
    }

    /**
     * ตรวจรูปแบบพารามิเตอร์ที่เบราว์เซอร์ส่งมา — คืนค่าที่ทำความสะอาดแล้ว หรือ null ถ้าไม่ถูกต้อง
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>|null
     */
    public static function cleanParams(string $action, array $params): ?array
    {
        $spec = self::ALL[$action]['params'] ?? null;
        if ($spec === null) {
            return null;
        }
        $out = [];
        foreach ($spec as $name => $type) {
            $value = $params[$name] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }
            $value = (string) $value;
            $ok = match ($type) {
                'uint' => preg_match('/^\d{1,78}$/', $value) === 1,
                'address' => preg_match('/^0x[0-9a-fA-F]{40}$/', $value) === 1,
                default => false,
            };
            if (! $ok) {
                return null;
            }
            $out[$name] = $type === 'address' ? strtolower($value) : (ltrim($value, '0') ?: '0');
        }

        return $out;
    }
}
