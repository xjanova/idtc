<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use App\Models\Purchase;
use App\Services\Chain\Address;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ผูกกระเป๋ากับผู้ชวน — กติกา: ผูกได้ครั้งเดียว · ก่อนซื้อครั้งแรก · ชวนตัวเองไม่ได้ · ผู้ชวนต้องไม่อยู่ในทีมของตัวเอง (กันวงวน)
 * ตรวจลายเซ็นมาก่อนแล้วใน controller (SiweAuth) — คลาสนี้ตรวจแค่กติกาและบันทึก
 * ผูกสำเร็จ = ได้ตำแหน่งในผัง Binary ทันที (GridPlacer) — ผูกทีละรายการทั้งระบบ (Cache lock) ตำแหน่งจึงไม่ชนกัน
 */
final class GridBinder
{
    /** สายบนลึกเกินนี้ถือว่าข้อมูลผิดปกติ (ไม่ควรเกิด) */
    public const MAX_WALK = 10000;

    public function __construct(protected GridPlacer $placer) {}

    /**
     * ตรวจก่อนออกข้อความให้เซ็น (และตรวจซ้ำตอนบันทึก)
     *
     * @return string|null รหัสข้อผิดพลาด (lang grid.err.*) หรือ null = ผูกได้
     */
    public function check(string $wallet, string $referrer): ?string
    {
        if (! Address::isValid($wallet)) {
            return 'address';
        }
        if (! Address::isValid($referrer) || Address::isZero($referrer)) {
            return 'referrer';
        }
        $wallet = strtolower($wallet);
        $referrer = strtolower($referrer);
        if ($wallet === $referrer) {
            return 'self';
        }
        if (GridMember::query()->where('wallet', $wallet)->whereNotNull('referrer')->exists()) {
            return 'bound';
        }
        if (Purchase::query()->where('buyer', $wallet)->exists()) {
            return 'bought';
        }
        // เดินขึ้นจากผู้ชวน — เจอตัวเอง = ผู้ชวนอยู่ในทีมของเรา
        $cur = $referrer;
        for ($i = 0; $i < self::MAX_WALK && $cur !== null; $i++) {
            if ($cur === $wallet) {
                return 'cycle';
            }
            $cur = GridMember::query()->where('wallet', $cur)->value('referrer');
        }

        return null;
    }

    /** ยังผูกผู้ชวนได้ไหม (ยังไม่ผูก และยังไม่เคยซื้อ) */
    public function canBind(string $wallet): bool
    {
        $wallet = strtolower($wallet);

        return ! GridMember::query()->where('wallet', $wallet)->whereNotNull('referrer')->exists()
            && ! Purchase::query()->where('buyer', $wallet)->exists();
    }

    /**
     * @param  string|null  $side  ขาในผัง Binary ที่ลิงก์ชวนระบุ (L | R) · null = ตามที่ผู้ชวนตั้งไว้ / อัตโนมัติ
     * @return string|GridMember รหัสข้อผิดพลาด หรือแถวที่บันทึก
     */
    public function bind(string $wallet, string $referrer, string $message, string $signature, ?string $ip = null, ?string $side = null): string|GridMember
    {
        $lock = Cache::lock(GridPlacer::LOCK, 30);
        try {
            $lock->block(8);
        } catch (LockTimeoutException) {
            return 'busy';
        }
        try {
            return DB::transaction(function () use ($wallet, $referrer, $message, $signature, $ip, $side) {
                $error = $this->check($wallet, $referrer);
                if ($error !== null) {
                    return $error;
                }
                $row = GridMember::query()->firstOrNew(['wallet' => strtolower($wallet)]);
                $row->fill([
                    'referrer' => strtolower($referrer),
                    'message' => $message,
                    'signature' => $signature,
                    'bound_at' => now(),
                    'ip' => $ip,
                ])->save();
                $this->placer->place($row, $side);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return 'bound';
        } finally {
            $lock->release();
        }
    }
}
