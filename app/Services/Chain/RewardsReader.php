<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;

/**
 * อ่านสัญญา GridRewards — แคช 15 วินาที ยกเว้นสถานะรับแล้วของสมาชิก
 * ค่าเริ่มต้นอ่านสัญญาของ IDTC Grid (contracts.rewards) · สายจ่ายอื่นใช้สัญญาของตัวเอง: for('binpay') / for('bindel')
 */
class RewardsReader
{
    public const TTL = 15;

    public const ROUND = ['bytes32', 'uint128', 'uint128', 'uint64', 'uint64', 'bool'];

    /** key ของที่อยู่สัญญาใน config idtc.contracts */
    protected string $key = 'rewards';

    public function __construct(protected RpcClient $rpc) {}

    /** ตัวอ่านของสัญญาจ่ายอีกตัว (GridRewards เหมือนกัน คนละที่อยู่/คนละเหรียญ) */
    public function for(string $key): static
    {
        $copy = clone $this;
        $copy->key = $key;

        return $copy;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function address(): ?string
    {
        return Idtc::contract($this->key);
    }

    public function forget(): void
    {
        if ($a = $this->address()) {
            Cache::forget('idtc:read:rewards:'.strtolower($a));
        }
    }

    /**
     * ภาพรวมสัญญา + ทุกรอบบนเชน
     *
     * @return array{deployed: bool, address: string|null, ok: bool, error: string|null, owner?: string, token?: string, paused?: bool, outstanding?: string, balance?: string, count?: int, rounds?: array<int, array{root: string, total: string, claimed: string, unlock_at: int, added_at: int, revoked: bool}>}
     */
    public function read(): array
    {
        $a = $this->address();
        if ($a === null) {
            return ['deployed' => false, 'address' => null, 'ok' => false, 'error' => null];
        }
        try {
            return Cache::remember('idtc:read:rewards:'.strtolower($a), self::TTL, fn () => $this->fetch($a));
        } catch (RpcException $e) {
            return ['deployed' => true, 'address' => $a, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    protected function fetch(string $a): array
    {
        $r = $this->rpc->readMany([
            'owner' => [$a, 'owner()', [], ['address']],
            'token' => [$a, 'token()', [], ['address']],
            'paused' => [$a, 'paused()', [], ['bool']],
            'outstanding' => [$a, 'outstanding()'],
            'count' => [$a, 'roundCount()'],
        ]);
        $v = $r['values'];
        if ($v['token'] === null || $v['count'] === null) {
            return ['deployed' => true, 'address' => $a, 'ok' => false, 'error' => 'อ่านสัญญาจ่ายรางวัลไม่ได้ — ที่อยู่นี้อาจไม่ใช่ GridRewards'];
        }
        $count = (int) $v['count'];
        $calls = ['balance' => [$v['token'], 'balanceOf(address)', [$a]]];
        for ($i = 0; $i < $count; $i++) {
            $calls["r{$i}"] = [$a, 'rounds(uint256)', [$i], self::ROUND];
        }
        $more = $this->rpc->readMany($calls)['values'];
        $rounds = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $more["r{$i}"] ?? null;
            if (is_array($x)) {
                $rounds[$i] = ['root' => strtolower($x[0]), 'total' => $x[1], 'claimed' => $x[2], 'unlock_at' => (int) $x[3], 'added_at' => (int) $x[4], 'revoked' => (bool) $x[5]];
            }
        }

        return [
            'deployed' => true, 'address' => $a, 'ok' => true, 'error' => null,
            'owner' => $v['owner'], 'token' => $v['token'], 'paused' => (bool) $v['paused'],
            'outstanding' => (string) $v['outstanding'], 'balance' => (string) ($more['balance'] ?? '0'),
            'count' => $count, 'rounds' => $rounds,
        ];
    }

    /**
     * หลายกระเป๋าในรอบเดียว — ใช้ตอนรอบถูกยกเลิก เพื่อรู้ว่าใครรับไปแล้ว
     *
     * @param  list<string>  $wallets
     * @return array<string, bool|null> null = อ่านไม่ได้
     *
     * @throws RpcException
     */
    public function claimedBy(int $round, array $wallets): array
    {
        $a = $this->address();
        if ($a === null || $wallets === []) {
            return [];
        }
        $calls = [];
        foreach (array_values($wallets) as $i => $w) {
            $calls["w{$i}"] = [$a, 'claimed(uint256,address)', [$round, $w], ['bool']];
        }
        $v = $this->rpc->readMany($calls)['values'];
        $out = [];
        foreach (array_values($wallets) as $i => $w) {
            $out[$w] = $v["w{$i}"] ?? null;
        }

        return $out;
    }

    /**
     * รับแล้วหรือยัง — สด ไม่แคช (หลังกดรับต้องเห็นผลทันที)
     *
     * @param  list<int>  $rounds
     * @return array<int, bool|null> null = อ่านไม่ได้
     *
     * @throws RpcException
     */
    public function claimed(string $account, array $rounds): array
    {
        $a = $this->address();
        if ($a === null || $rounds === []) {
            return [];
        }
        $calls = [];
        foreach ($rounds as $id) {
            $calls["c{$id}"] = [$a, 'claimed(uint256,address)', [$id, $account], ['bool']];
        }
        $v = $this->rpc->readMany($calls)['values'];
        $out = [];
        foreach ($rounds as $id) {
            $out[$id] = $v["c{$id}"] ?? null;
        }

        return $out;
    }
}
