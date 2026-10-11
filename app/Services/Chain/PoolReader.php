<?php

namespace App\Services\Chain;

use App\Support\Idtc;
use Illuminate\Support\Facades\Cache;

/**
 * อ่านพูล TPIX DEX (Uniswap V2 fork): IDTC/USDT → ราคาเป็น USD · IDTC/TPIX → ราคาเป็น TPIX
 * ถ้ายังไม่ได้ตั้งที่อยู่พูลแต่รู้ที่อยู่เหรียญแล้ว จะถาม factory.getPair() ให้เป็นคำแนะนำ
 */
class PoolReader
{
    public const TTL = 15;

    /** key ของพูล => [key ของเหรียญคู่, สัญลักษณ์, ทศนิยม] */
    public const PAIRS = [
        'pair_usdt' => ['usdt', 'USDT', 6],
        'pair_tpix' => ['wtpix', 'TPIX', 18],
    ];

    public function __construct(protected RpcClient $rpc) {}

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    protected function cacheKey(): string
    {
        $c = Idtc::contracts();

        return 'idtc:read:pools:'.md5(implode('|', [$c['token'], $c['pair_usdt'], $c['pair_tpix'], $c['usdt'], $c['wtpix'], $c['dex_factory']]));
    }

    /** @return array<string, array<string, mixed>> */
    public function read(): array
    {
        $configured = array_filter(array_map(fn ($k) => Idtc::contract($k), array_keys(self::PAIRS)));
        if ($configured === [] && ! Idtc::isDeployed('token')) {
            return array_map(fn () => ['deployed' => false, 'address' => null, 'ok' => false, 'error' => null, 'suggested' => null], self::PAIRS);
        }

        try {
            return Cache::remember($this->cacheKey(), self::TTL, fn () => $this->fetch());
        } catch (RpcException $e) {
            $out = [];
            foreach (self::PAIRS as $key => $_) {
                $out[$key] = ['deployed' => Idtc::isDeployed($key), 'address' => Idtc::contract($key), 'ok' => false, 'error' => $e->getMessage(), 'suggested' => null];
            }

            return $out;
        }
    }

    /** @return array<string, array<string, mixed>> */
    protected function fetch(): array
    {
        $token = Idtc::contract('token');
        $out = [];
        foreach (self::PAIRS as $key => [$quoteKey, $symbol, $decimals]) {
            $pair = Idtc::contract($key);
            $quote = Idtc::contract($quoteKey);
            if ($pair === null) {
                $out[$key] = ['deployed' => false, 'address' => null, 'ok' => false, 'error' => null, 'suggested' => $this->suggest($token, $quote)];

                continue;
            }
            $out[$key] = $this->readPair($pair, $token, $quote, $symbol, $decimals);
        }

        // ราคา TPIX เป็น USD จากพูล TPIX/USDT บน DEX เดียวกัน (ถ้ามี) — ใช้แปลงราคา IDTC/TPIX เป็น USD
        $tpixUsd = ($out['pair_tpix']['price'] ?? null) !== null ? $this->tpixUsd() : null;
        if ($tpixUsd !== null) {
            $out['pair_tpix']['price_usd'] = $out['pair_tpix']['price'] * $tpixUsd;
            $out['pair_tpix']['tpix_usd'] = $tpixUsd;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function readPair(string $pair, ?string $token, ?string $quote, string $symbol, int $decimals): array
    {
        $r = $this->rpc->readMany([
            'token0' => [$pair, 'token0()', [], ['address']],
            'token1' => [$pair, 'token1()', [], ['address']],
            'reserves' => [$pair, 'getReserves()', [], ['uint112', 'uint112', 'uint32']],
        ]);
        $v = $r['values'];
        $base = ['deployed' => true, 'address' => $pair, 'suggested' => null, 'symbol' => $symbol, 'token0' => $v['token0'], 'token1' => $v['token1']];
        if ($v['token0'] === null || $v['token1'] === null || ! is_array($v['reserves'])) {
            return $base + ['ok' => false, 'error' => 'ที่อยู่นี้ไม่ตอบเหมือนพูลของ DEX — ตรวจที่อยู่ในหน้าตั้งค่าเหรียญ'];
        }

        // หาว่าฝั่งไหนคือ IDTC: ถ้ารู้ที่อยู่เหรียญใช้ที่อยู่ ไม่งั้นถือว่าฝั่งที่ไม่ใช่เหรียญคู่คือ IDTC
        $idtcIs0 = match (true) {
            $token !== null && Address::same($v['token0'], $token) => true,
            $token !== null && Address::same($v['token1'], $token) => false,
            $token === null && $quote !== null && Address::same($v['token1'], $quote) => true,
            $token === null && $quote !== null && Address::same($v['token0'], $quote) => false,
            default => null,
        };
        $quoteOk = $quote !== null && Address::same($idtcIs0 ? $v['token1'] : $v['token0'], $quote);
        if ($idtcIs0 === null || ! $quoteOk) {
            return $base + ['ok' => false, 'error' => "พูลนี้ไม่ใช่คู่ IDTC/{$symbol}"];
        }

        [$r0, $r1, $ts] = $v['reserves'];
        $idtcRaw = $idtcIs0 ? $r0 : $r1;
        $quoteRaw = $idtcIs0 ? $r1 : $r0;
        $idtc = Units::toFloat($idtcRaw, 18);
        $q = Units::toFloat($quoteRaw, $decimals);

        return $base + [
            'ok' => true,
            'error' => null,
            'reserve_idtc' => $idtcRaw,
            'reserve_quote' => $quoteRaw,
            'decimals' => $decimals,
            'price' => $idtc > 0 ? $q / $idtc : null,
            'updated_at' => (int) $ts ?: null,
        ];
    }

    protected function suggest(?string $token, ?string $quote): ?string
    {
        $factory = Idtc::contract('dex_factory');
        if ($token === null || $quote === null || $factory === null) {
            return null;
        }
        $pair = $this->rpc->readMany(['p' => [$factory, 'getPair(address,address)', [$token, $quote], ['address']]])['values']['p'];

        return Address::isValid($pair) && ! Address::isZero($pair) ? Address::checksum($pair) : null;
    }

    protected function tpixUsd(): ?float
    {
        $factory = Idtc::contract('dex_factory');
        $wtpix = Idtc::contract('wtpix');
        $usdt = Idtc::contract('usdt');
        if (! $factory || ! $wtpix || ! $usdt) {
            return null;
        }
        $pair = $this->rpc->readMany(['p' => [$factory, 'getPair(address,address)', [$wtpix, $usdt], ['address']]])['values']['p'];
        if (! Address::isValid($pair) || Address::isZero($pair)) {
            return null;
        }
        $r = $this->rpc->readMany([
            'token0' => [$pair, 'token0()', [], ['address']],
            'reserves' => [$pair, 'getReserves()', [], ['uint112', 'uint112', 'uint32']],
        ])['values'];
        if (! is_array($r['reserves']) || $r['token0'] === null) {
            return null;
        }
        $wIs0 = Address::same($r['token0'], $wtpix);
        $w = Units::toFloat($wIs0 ? $r['reserves'][0] : $r['reserves'][1], 18);
        $u = Units::toFloat($wIs0 ? $r['reserves'][1] : $r['reserves'][0], 6);

        return $w > 0 ? $u / $w : null;
    }
}
