<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;

/**
 * อ่านสัญญา TrancheVesting (ล็อก 70%) จากเชน — แคช 15 วินาที
 */
class VestingReader
{
    public const TTL = 15;

    public function __construct(protected RpcClient $rpc) {}

    public function address(): ?string
    {
        return Idtc::contract('vesting');
    }

    public function cacheKey(string $address): string
    {
        return 'idtc:read:vesting:'.strtolower($address);
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
            'token' => [$a, 'token()', [], ['address']],
            'beneficiary' => [$a, 'beneficiary()', [], ['address']],
            'pendingBeneficiary' => [$a, 'pendingBeneficiary()', [], ['address']],
            'start' => [$a, 'start()', [], ['uint64']],
            'period' => [$a, 'period()', [], ['uint64']],
            'totalAllocation' => [$a, 'totalAllocation()'],
            'released' => [$a, 'released()'],
            'releasable' => [$a, 'releasable()'],
            'lockedAmount' => [$a, 'lockedAmount()'],
            'isFunded' => [$a, 'isFunded()', [], ['bool']],
            'nextUnlock' => [$a, 'nextUnlock()', [], ['uint64', 'uint256']],
            'trancheCount' => [$a, 'trancheCount()'],
            'label' => [$a, 'label()', [], ['string']],
        ]);
        $v = $r['values'];
        $core = $v['totalAllocation'] !== null && $v['start'] !== null && $v['period'] !== null;

        $tranches = [];
        $count = min(12, (int) ($v['trancheCount'] ?? 0));
        if ($core && $count > 0) {
            $calls = [];
            for ($i = 0; $i < $count; $i++) {
                $calls["t{$i}"] = [$a, 'tranche(uint256)', [$i], ['uint256', 'uint32', 'uint32']];
            }
            $start = (int) $v['start'];
            $period = (int) $v['period'];
            foreach ($this->rpc->readMany($calls)['values'] as $t) {
                if (! is_array($t)) {
                    continue;
                }
                [$amount, $cliff, $release] = [$t[0], (int) $t[1], (int) $t[2]];
                $cliffEnd = $start + $cliff * $period;
                $tranches[] = [
                    'amount' => $amount,
                    'cliff' => $cliff,
                    'release' => $release,
                    'first_unlock' => $cliffEnd + $period,
                    'fully_vested' => $cliffEnd + $release * $period,
                    'per_period' => $release > 0 ? Units::toFloat($amount, 18) / $release : 0.0,
                ];
            }
        }

        $balance = null;
        if (Address::isValid($v['token']) && ! Address::isZero($v['token'])) {
            $balance = $this->rpc->readMany(['b' => [$v['token'], 'balanceOf(address)', [$a]]])['values']['b'];
        }
        $obligation = $core && $v['released'] !== null ? Units::sub($v['totalAllocation'], $v['released']) : null;
        $excess = $balance !== null && $obligation !== null && Units::cmp($balance, $obligation) > 0 ? Units::sub($balance, $obligation) : '0';

        return [
            'deployed' => true,
            'address' => $a,
            'ok' => $core,
            'error' => $core ? null : 'ที่อยู่นี้ไม่ตอบเหมือนสัญญาล็อก (TrancheVesting) — ตรวจที่อยู่ในหน้าตั้งค่าเหรียญ',
            'errors' => $r['errors'],
            'v' => $v,
            'tranches' => $tranches,
            'balance' => $balance,
            'obligation' => $obligation,
            'excess' => $excess,
            'next_at' => is_array($v['nextUnlock']) ? (int) $v['nextUnlock'][0] : null,
            'next_amount' => is_array($v['nextUnlock']) ? $v['nextUnlock'][1] : null,
            'fetched_at' => time(),
        ];
    }
}
