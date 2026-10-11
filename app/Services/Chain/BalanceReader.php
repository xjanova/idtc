<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;

/**
 * ยอดคงเหลือของกระเป๋า: TPIX (เหรียญหลักของเชน) · USDT · IDTC (เมื่อเหรียญขึ้นเชนแล้ว) — แคช 15 วินาที
 */
class BalanceReader
{
    public const TTL = 15;

    public function __construct(protected RpcClient $rpc) {}

    /**
     * @param  list<string>  $holders
     * @return array{ok: bool, error: string|null, balances: array<string, array{tpix: string|null, usdt: string|null, idtc: string|null}>}
     */
    public function read(array $holders): array
    {
        $holders = array_values(array_unique(array_map('strtolower', array_filter($holders, [Address::class, 'isValid']))));
        if ($holders === []) {
            return ['ok' => true, 'error' => null, 'balances' => []];
        }
        $usdt = Idtc::contract('usdt');
        $token = Idtc::contract('token');
        $key = 'idtc:read:balances:'.md5(implode('|', $holders).'|'.$usdt.'|'.$token);

        try {
            return Cache::remember($key, self::TTL, function () use ($holders, $usdt, $token) {
                $calls = [];
                foreach ($holders as $h) {
                    $calls[] = ['eth_getBalance', [$h, 'latest']];
                    if ($usdt) {
                        $calls[] = ['eth_call', [['to' => $usdt, 'data' => Abi::encodeCall('balanceOf(address)', [$h])], 'latest']];
                    }
                    if ($token) {
                        $calls[] = ['eth_call', [['to' => $token, 'data' => Abi::encodeCall('balanceOf(address)', [$h])], 'latest']];
                    }
                }
                $results = $this->rpc->batch($calls);
                $i = 0;
                $out = [];
                $word = fn (?array $r) => isset($r['result']) && is_string($r['result']) && strlen($r['result']) >= 66 ? Abi::decodeUint(substr($r['result'], 2, 64)) : null;
                foreach ($holders as $h) {
                    $tpix = $results[$i++] ?? null;
                    $row = ['tpix' => isset($tpix['result']) ? Units::hexToDec((string) $tpix['result']) : null, 'usdt' => null, 'idtc' => null];
                    if ($usdt) {
                        $row['usdt'] = $word($results[$i++] ?? null);
                    }
                    if ($token) {
                        $row['idtc'] = $word($results[$i++] ?? null);
                    }
                    $out[$h] = $row;
                }

                return ['ok' => true, 'error' => null, 'balances' => $out];
            });
        } catch (RpcException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'balances' => []];
        }
    }
}
