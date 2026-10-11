<?php

namespace App\Services\Chain;

use Illuminate\Support\Facades\Cache;

/**
 * สถานะเชนสั้น ๆ สำหรับแถบบนของหลังบ้าน (บล็อกล่าสุด) — แคช 10 วินาที
 */
class ChainStatus
{
    public function __construct(protected RpcClient $rpc) {}

    /** @return array{ok: bool, block: int|null, error: string|null} */
    public function head(): array
    {
        try {
            $block = Cache::remember('idtc:read:head', 10, fn () => $this->rpc->blockNumber());

            return ['ok' => true, 'block' => $block, 'error' => null];
        } catch (RpcException $e) {
            return ['ok' => false, 'block' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * ตรวจสุขภาพ RPC สำหรับหน้าตั้งค่า (ไม่แคช)
     *
     * @return array{ok: bool, chain_id: int|null, block: int|null, ms: int|null, error: string|null}
     */
    public function diagnose(): array
    {
        $t = microtime(true);
        try {
            $chainId = $this->rpc->chainId();
            $block = $this->rpc->blockNumber();
            $ok = $chainId === (int) config('idtc.chain.id');

            return [
                'ok' => $ok,
                'chain_id' => $chainId,
                'block' => $block,
                'ms' => (int) round((microtime(true) - $t) * 1000),
                'error' => $ok ? null : 'RPC นี้ไม่ใช่ TPIX Chain (chain id ไม่ตรง)',
            ];
        } catch (RpcException $e) {
            return ['ok' => false, 'chain_id' => null, 'block' => null, 'ms' => null, 'error' => $e->getMessage()];
        }
    }
}
