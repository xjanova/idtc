<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;

/**
 * อ่านสถานะสัญญาเติมพูล (PoolFeeder จาก TPIX LaunchpadFactory) — แคช 15 วินาที
 *
 * PoolFeeder เป็นเจ้าของถาวรและกระเป๋ารับเงิน (treasury) ของสัญญา presale:
 * คำสั่งเจ้าของของ presale (หยุดขาย ตั้งเวลา ตั้งเพดาน ถอนเหรียญที่ขายไม่หมด) ต้องสั่งผ่านสัญญานี้
 */
class FeederReader
{
    public const TTL = 15;

    /** PoolFeeder.BPS */
    public const BPS = 10000;

    public function __construct(protected RpcClient $rpc) {}

    public function address(): ?string
    {
        return Idtc::contract('feeder');
    }

    public function cacheKey(string $address): string
    {
        return 'idtc:read:feeder:'.strtolower($address);
    }

    public function forget(): void
    {
        if ($a = $this->address()) {
            Cache::forget($this->cacheKey($a));
        }
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $address = $this->address();
        if ($address === null) {
            return ['deployed' => false, 'address' => null, 'ok' => false, 'error' => null];
        }

        try {
            return Cache::remember($this->cacheKey($address), self::TTL, fn () => $this->fetch($address));
        } catch (RpcException $e) {
            return ['deployed' => true, 'address' => $address, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    protected function fetch(string $a): array
    {
        $r = $this->rpc->readMany([
            'owner' => [$a, 'owner()', [], ['address']],
            'pendingOwner' => [$a, 'pendingOwner()', [], ['address']],
            'sale' => [$a, 'sale()', [], ['address']],
            'projectTreasury' => [$a, 'projectTreasury()', [], ['address']],
            'pair' => [$a, 'pair()', [], ['address']],
            'lpUnlockTime' => [$a, 'lpUnlockTime()', [], ['uint64']],
            'saleEndLimit' => [$a, 'saleEndLimit()', [], ['uint64']],
            'poolShareBps' => [$a, 'poolShareBps()', [], ['uint16']],
            'bandBps' => [$a, 'bandBps()', [], ['uint16']],
            'minFeed' => [$a, 'minFeed()'],
            'pendingPool' => [$a, 'pendingPool()'],
            'pendingQuote' => [$a, 'pendingQuote()'],
            'tokenReserve' => [$a, 'tokenReserve()'],
            'poolVsSaleBps' => [$a, 'poolVsSaleBps()'],
            'totalQuoteToPool' => [$a, 'totalQuoteToPool()'],
            'totalTokenToPool' => [$a, 'totalTokenToPool()'],
            'totalQuoteToProject' => [$a, 'totalQuoteToProject()'],
        ]);
        $v = $r['values'];

        $core = $v['owner'] !== null && $v['sale'] !== null && $v['lpUnlockTime'] !== null && $v['saleEndLimit'] !== null;
        $now = time();
        $unlock = $v['lpUnlockTime'] !== null ? (int) $v['lpUnlockTime'] : null;

        // LP ที่สัญญาถือ (ล็อกจนถึง lpUnlockTime)
        $lp = null;
        if (Address::isValid($v['pair']) && ! Address::isZero($v['pair'])) {
            $lp = $this->rpc->readMany(['b' => [$v['pair'], 'balanceOf(address)', [$a]]])['values']['b'];
        }

        $presale = Idtc::contract('presale');

        return [
            'deployed' => true,
            'address' => $a,
            'ok' => $core,
            'error' => $core ? null : 'ที่อยู่นี้ไม่ตอบเหมือนสัญญาเติมพูล (PoolFeeder) — ตรวจที่อยู่ในหน้าตั้งค่าเหรียญ',
            'errors' => $r['errors'],
            'v' => $v,
            'lp_unlock' => $unlock,
            'sale_end_limit' => $v['saleEndLimit'] !== null ? (int) $v['saleEndLimit'] : null,
            'unlocked' => $unlock !== null && $now >= $unlock,
            'lp_balance' => $lp,
            // ผูกกับสัญญา presale ที่ตั้งไว้ในหลังบ้านไหม (null = ยังไม่ได้ตั้ง presale หรืออ่านไม่ได้)
            'bound' => $presale !== null && Address::isValid($v['sale']) ? Address::same($v['sale'], $presale) : null,
            'band' => $this->band($v['poolVsSaleBps'], $v['bandBps']),
            'fetched_at' => $now,
        ];
    }

    /**
     * ราคาพูลเทียบราคาขาย — empty: พูลยังว่าง (เติมครั้งแรกตั้งราคาตามราคาขายพอดี) · in: อยู่ในกรอบ เติมได้ · out: นอกกรอบ เติมไม่ได้จนราคากลับมา
     *
     * @return array{state: string, percent: ?float}
     */
    protected function band(?string $vs, ?string $band): array
    {
        if ($vs === null || $band === null) {
            return ['state' => 'unknown', 'percent' => null];
        }
        if ($vs === '0') {
            return ['state' => 'empty', 'percent' => null];
        }
        $bps = (int) $vs;
        $b = (int) $band;

        return ['state' => $bps >= self::BPS - $b && $bps <= self::BPS + $b ? 'in' : 'out', 'percent' => (float) ($bps / 100)];
    }
}
