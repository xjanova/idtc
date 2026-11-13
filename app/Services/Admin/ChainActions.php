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
        // ขายผ่าน TPIX Launchpad: สัญญาเติมพูล (PoolFeeder) เป็นเจ้าของถาวรของ presale — สั่ง presale ผ่านสัญญานี้
        // (ไม่มีคำสั่งเปลี่ยนกระเป๋ารับเงินหรือโอนเจ้าของ presale: สัญญาเติมพูลไม่เปิดให้ทำ)
        'feeder.pauseSale' => ['contract' => 'feeder', 'method' => 'pauseSale()', 'label' => 'หยุดขาย presale ชั่วคราว (ผ่านสัญญาเติมพูล)', 'guard' => 'owner', 'destructive' => true, 'params' => []],
        'feeder.unpauseSale' => ['contract' => 'feeder', 'method' => 'unpauseSale()', 'label' => 'เปิดขาย presale ต่อ (ผ่านสัญญาเติมพูล)', 'guard' => 'owner', 'destructive' => false, 'params' => []],
        'feeder.setSaleLimits' => ['contract' => 'feeder', 'method' => 'setSaleLimits(uint256,uint256)', 'label' => 'ตั้งขั้นต่ำ/เพดานต่อกระเป๋า (ผ่านสัญญาเติมพูล)', 'guard' => 'owner', 'destructive' => false, 'params' => ['minPurchase' => 'uint', 'maxPerWallet' => 'uint']],
        'feeder.setSaleWindow' => ['contract' => 'feeder', 'method' => 'setSaleWindow(uint64,uint64)', 'label' => 'ตั้งช่วงเวลาขาย (ผ่านสัญญาเติมพูล)', 'guard' => 'owner', 'destructive' => false, 'params' => ['startTime' => 'uint', 'endTime' => 'uint']],
        'feeder.withdrawUnsold' => ['contract' => 'feeder', 'method' => 'withdrawUnsold(address)', 'label' => 'ถอนเหรียญที่ขายไม่หมด (หลังปลดล็อก LP)', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address']],
        'feeder.feed' => ['contract' => 'feeder', 'method' => 'feed()', 'label' => 'เติม USDT จากการขายเข้าพูล', 'guard' => 'anyone', 'destructive' => false, 'params' => []],
        'feeder.setProjectTreasury' => ['contract' => 'feeder', 'method' => 'setProjectTreasury(address)', 'label' => 'เปลี่ยนกระเป๋าโครงการของสัญญาเติมพูล', 'guard' => 'owner', 'destructive' => true, 'params' => ['treasury' => 'address']],
        'feeder.withdrawLiquidity' => ['contract' => 'feeder', 'method' => 'withdrawLiquidity(address)', 'label' => 'ถอน LP ออกจากสัญญาเติมพูล (หลังปลดล็อก)', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address']],
        'feeder.withdrawReserve' => ['contract' => 'feeder', 'method' => 'withdrawReserve(address)', 'label' => 'ถอนเหรียญสำรองของสัญญาเติมพูล (หลังปลดล็อก)', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address']],
        'feeder.withdrawLeftoverQuote' => ['contract' => 'feeder', 'method' => 'withdrawLeftoverQuote(address)', 'label' => 'ถอน USDT ที่ค้างในสัญญาเติมพูล (หลังปลดล็อก)', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address']],
        'feeder.transferOwnership' => ['contract' => 'feeder', 'method' => 'transferOwnership(address)', 'label' => 'เริ่มโอนสิทธิ์เจ้าของสัญญาเติมพูล', 'guard' => 'owner', 'destructive' => true, 'params' => ['newOwner' => 'address']],
        'feeder.acceptOwnership' => ['contract' => 'feeder', 'method' => 'acceptOwnership()', 'label' => 'รับสิทธิ์เจ้าของสัญญาเติมพูล', 'guard' => 'pendingOwner', 'destructive' => false, 'params' => []],
        // GridRewards: ประกาศรอบจ่ายรางวัล Grid (รายชื่อสร้างที่หน้า Grid › รอบจ่าย) · เหรียญต้องโอนเข้าสัญญาก่อนประกาศ
        'rewards.addRound' => ['contract' => 'rewards', 'method' => 'addRound(uint256,bytes32,uint256,uint64)', 'label' => 'ประกาศรอบจ่ายรางวัล Grid', 'guard' => 'owner', 'destructive' => false, 'params' => ['id' => 'uint', 'root' => 'bytes32', 'total' => 'uint', 'unlockAt' => 'uint']],
        'rewards.revokeRound' => ['contract' => 'rewards', 'method' => 'revokeRound(uint256)', 'label' => 'ยกเลิกรอบจ่ายรางวัล Grid', 'guard' => 'owner', 'destructive' => true, 'params' => ['id' => 'uint']],
        'rewards.pause' => ['contract' => 'rewards', 'method' => 'pause()', 'label' => 'หยุดรับรางวัล Grid ชั่วคราว', 'guard' => 'owner', 'destructive' => true, 'params' => []],
        'rewards.unpause' => ['contract' => 'rewards', 'method' => 'unpause()', 'label' => 'เปิดรับรางวัล Grid ต่อ', 'guard' => 'owner', 'destructive' => false, 'params' => []],
        'rewards.withdrawExcess' => ['contract' => 'rewards', 'method' => 'withdrawExcess(address,uint256)', 'label' => 'ถอนเหรียญส่วนเกินจากสัญญาจ่ายรางวัล', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address', 'amount' => 'uint']],
        // แผน Binary โปรโมชั่น — สัญญา GridRewards ของสาย รางวัลจับคู่ Binary (USDT)
        'binpay.addRound' => ['contract' => 'binpay', 'method' => 'addRound(uint256,bytes32,uint256,uint64)', 'label' => 'ประกาศรอบจ่าย: รางวัลจับคู่ Binary (USDT)', 'guard' => 'owner', 'destructive' => false, 'params' => ['id' => 'uint', 'root' => 'bytes32', 'total' => 'uint', 'unlockAt' => 'uint']],
        'binpay.revokeRound' => ['contract' => 'binpay', 'method' => 'revokeRound(uint256)', 'label' => 'ยกเลิกรอบจ่าย: รางวัลจับคู่ Binary (USDT)', 'guard' => 'owner', 'destructive' => true, 'params' => ['id' => 'uint']],
        'binpay.pause' => ['contract' => 'binpay', 'method' => 'pause()', 'label' => 'หยุดรับชั่วคราว: รางวัลจับคู่ Binary (USDT)', 'guard' => 'owner', 'destructive' => true, 'params' => []],
        'binpay.unpause' => ['contract' => 'binpay', 'method' => 'unpause()', 'label' => 'เปิดรับต่อ: รางวัลจับคู่ Binary (USDT)', 'guard' => 'owner', 'destructive' => false, 'params' => []],
        'binpay.withdrawExcess' => ['contract' => 'binpay', 'method' => 'withdrawExcess(address,uint256)', 'label' => 'ถอน USDT ส่วนเกินจากสัญญา: รางวัลจับคู่ Binary (USDT)', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address', 'amount' => 'uint']],
        // แผน Binary โปรโมชั่น — สัญญา GridRewards ของสาย ส่ง IDTC ของแผน Binary
        'bindel.addRound' => ['contract' => 'bindel', 'method' => 'addRound(uint256,bytes32,uint256,uint64)', 'label' => 'ประกาศรอบจ่าย: ส่ง IDTC ของแผน Binary', 'guard' => 'owner', 'destructive' => false, 'params' => ['id' => 'uint', 'root' => 'bytes32', 'total' => 'uint', 'unlockAt' => 'uint']],
        'bindel.revokeRound' => ['contract' => 'bindel', 'method' => 'revokeRound(uint256)', 'label' => 'ยกเลิกรอบจ่าย: ส่ง IDTC ของแผน Binary', 'guard' => 'owner', 'destructive' => true, 'params' => ['id' => 'uint']],
        'bindel.pause' => ['contract' => 'bindel', 'method' => 'pause()', 'label' => 'หยุดรับชั่วคราว: ส่ง IDTC ของแผน Binary', 'guard' => 'owner', 'destructive' => true, 'params' => []],
        'bindel.unpause' => ['contract' => 'bindel', 'method' => 'unpause()', 'label' => 'เปิดรับต่อ: ส่ง IDTC ของแผน Binary', 'guard' => 'owner', 'destructive' => false, 'params' => []],
        'bindel.withdrawExcess' => ['contract' => 'bindel', 'method' => 'withdrawExcess(address,uint256)', 'label' => 'ถอน IDTC ส่วนเกินจากสัญญา: ส่ง IDTC ของแผน Binary', 'guard' => 'owner', 'destructive' => true, 'params' => ['to' => 'address', 'amount' => 'uint']],
        'vesting.release' => ['contract' => 'vesting', 'method' => 'release()', 'label' => 'ปลดงวดที่ถึงเวลา', 'guard' => 'anyone', 'destructive' => false, 'params' => []],
        'vesting.transferBeneficiary' => ['contract' => 'vesting', 'method' => 'transferBeneficiary(address)', 'label' => 'เริ่มเปลี่ยนผู้รับเหรียญที่ปลดล็อก', 'guard' => 'beneficiary', 'destructive' => true, 'params' => ['newBeneficiary' => 'address']],
        'vesting.acceptBeneficiary' => ['contract' => 'vesting', 'method' => 'acceptBeneficiary()', 'label' => 'รับสิทธิ์ผู้รับเหรียญที่ปลดล็อก', 'guard' => 'pendingBeneficiary', 'destructive' => false, 'params' => []],
        'vesting.sweepExcess' => ['contract' => 'vesting', 'method' => 'sweepExcess(address)', 'label' => 'คืนเหรียญส่วนเกินจากสัญญาล็อก', 'guard' => 'beneficiary', 'destructive' => true, 'params' => ['token' => 'address']],
    ];

    public static function has(string $action): bool
    {
        return isset(self::ALL[$action]);
    }

    /** ส่วนของหลังบ้าน (AdminPermissions) ที่ต้องมีสิทธิ์ edit จึงบันทึก/ตามผลคำสั่งนี้ได้ · null = ไม่ใช่คำสั่งบนเชนที่รู้จัก */
    public static function section(string $action): ?string
    {
        $contract = self::get($action)['contract'] ?? null;

        return match ($contract) {
            'presale', 'feeder' => 'presale',
            'vesting' => 'vesting',
            'rewards' => 'grid_rounds',
            'binpay', 'bindel' => 'binary',
            default => null,
        };
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
                'bytes32' => preg_match('/^0x[0-9a-fA-F]{64}$/', $value) === 1,
                default => false,
            };
            if (! $ok) {
                return null;
            }
            $out[$name] = in_array($type, ['address', 'bytes32'], true) ? strtolower($value) : (ltrim($value, '0') ?: '0');
        }

        return $out;
    }
}
