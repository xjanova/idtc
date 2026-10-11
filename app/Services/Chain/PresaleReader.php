<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Math\BigInteger;

/**
 * อ่านสถานะสัญญา FixedPriceSale (presale) จากเชน — แคช 15 วินาที
 */
class PresaleReader
{
    public const TTL = 15;

    public const STATUS_LABELS = [
        'live' => 'เปิดขาย',
        'paused' => 'หยุดชั่วคราว',
        'scheduled' => 'ยังไม่ถึงเวลาเปิด',
        'ended' => 'ปิดรอบแล้ว',
        'sold_out' => 'ขายครบเพดาน',
        'unknown' => 'อ่านสถานะไม่ได้',
    ];

    public function __construct(protected RpcClient $rpc) {}

    public function address(): ?string
    {
        return Idtc::contract('presale');
    }

    public function cacheKey(string $address): string
    {
        return 'idtc:read:presale:'.strtolower($address);
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
            return ['deployed' => false, 'address' => null, 'ok' => false, 'error' => null, 'status' => null];
        }

        try {
            return Cache::remember($this->cacheKey($address), self::TTL, fn () => $this->fetch($address));
        } catch (RpcException $e) {
            return ['deployed' => true, 'address' => $address, 'ok' => false, 'error' => $e->getMessage(), 'status' => 'unknown'];
        }
    }

    /** @return array<string, mixed> */
    protected function fetch(string $a): array
    {
        $r = $this->rpc->readMany([
            'saleToken' => [$a, 'saleToken()', [], ['address']],
            'payToken' => [$a, 'payToken()', [], ['address']],
            'rateNumerator' => [$a, 'rateNumerator()'],
            'rateDenominator' => [$a, 'rateDenominator()'],
            'saleCap' => [$a, 'saleCap()'],
            'sold' => [$a, 'sold()'],
            'raised' => [$a, 'raised()'],
            'startTime' => [$a, 'startTime()', [], ['uint64']],
            'endTime' => [$a, 'endTime()', [], ['uint64']],
            'minPurchase' => [$a, 'minPurchase()'],
            'maxPerWallet' => [$a, 'maxPerWallet()'],
            'paused' => [$a, 'paused()', [], ['bool']],
            'treasury' => [$a, 'treasury()', [], ['address']],
            'owner' => [$a, 'owner()', [], ['address']],
            'pendingOwner' => [$a, 'pendingOwner()', [], ['address']],
        ]);
        $v = $r['values'];

        $balance = null;
        if (Address::isValid($v['saleToken']) && ! Address::isZero($v['saleToken'])) {
            $balance = $this->rpc->readMany(['b' => [$v['saleToken'], 'balanceOf(address)', [$a]]])['values']['b'];
        }

        $core = $v['sold'] !== null && $v['saleCap'] !== null && $v['raised'] !== null;
        $start = $v['startTime'] !== null ? (int) $v['startTime'] : null;
        $end = $v['endTime'] !== null ? (int) $v['endTime'] : null;
        $now = time();
        $remaining = $core ? Units::sub($v['saleCap'], $v['sold']) : null;

        $status = match (true) {
            ! $core || $start === null || $end === null => 'unknown',
            $v['paused'] === true => 'paused',
            $now < $start => 'scheduled',
            $now >= $end => 'ended',
            Units::cmp($v['sold'], $v['saleCap']) >= 0 => 'sold_out',
            default => 'live',
        };

        return [
            'deployed' => true,
            'address' => $a,
            'ok' => $core,
            'error' => $core ? null : 'ที่อยู่นี้ไม่ตอบเหมือนสัญญา presale (FixedPriceSale) — ตรวจที่อยู่ในหน้าตั้งค่าเหรียญ',
            'errors' => $r['errors'],
            'status' => $status,
            'v' => $v,
            'start' => $start,
            'end' => $end,
            'remaining' => $remaining,
            'balance' => $balance,
            // เหรียญในสัญญาพอขายถึงเพดานไหม (ต้องฝากก่อนเปิดขาย)
            'funded' => $balance !== null && $remaining !== null ? Units::cmp($balance, $remaining) >= 0 : null,
            // ถอนเหรียญที่เหลือได้เมื่อปิดรอบหรือหยุดชั่วคราว
            'can_withdraw' => $v['paused'] === true || ($end !== null && $now >= $end),
            'plan' => $this->planCheck($v),
            'fetched_at' => $now,
        ];
    }

    /**
     * เทียบค่าที่ฝังในสัญญากับแผนใน config/idtc.php
     *
     * @param  array<string, mixed>  $v
     * @return array{rate: bool|null, cap: bool|null, pay_token: bool|null}
     */
    protected function planCheck(array $v): array
    {
        $sale = (array) config('idtc.sale');
        $rate = null;
        if ($v['rateNumerator'] !== null && $v['rateDenominator'] !== null) {
            $lhs = (new BigInteger($v['rateNumerator']))->multiply(new BigInteger((string) $sale['rate_den']));
            $rhs = (new BigInteger((string) $sale['rate_num']))->multiply(new BigInteger($v['rateDenominator']));
            $rate = $lhs->equals($rhs);
        }
        $cap = $v['saleCap'] !== null ? $v['saleCap'] === Units::toRaw((string) $sale['cap'], 18) : null;
        $usdt = Idtc::contract('usdt');
        $pay = $v['payToken'] !== null && $usdt ? Address::same($v['payToken'], $usdt) : null;

        return ['rate' => $rate, 'cap' => $cap, 'pay_token' => $pay];
    }
}
