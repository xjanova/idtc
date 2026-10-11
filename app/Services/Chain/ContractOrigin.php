<?php

namespace App\Services\Chain;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * หาบล็อกที่ deploy สัญญา — ใช้ตั้งบล็อกเริ่มดึงยอด presale ให้ไม่พลาดการซื้อที่เกิดก่อนแอดมินกรอกที่อยู่
 *
 * ถาม explorer (Blockscout) ว่า tx ไหนสร้างสัญญานี้ แล้วยืนยันกับ RPC ของเราเอง:
 * receipt ต้องมี contractAddress ตรงกับที่อยู่นี้ ไม่งั้นไม่เชื่อ (explorer ล่ม/ตอบผิด = null)
 * สัญญาที่ถูกสร้างจากสัญญาอื่น (factory) receipt ไม่มี contractAddress → null เช่นกัน
 */
class ContractOrigin
{
    public function __construct(protected RpcClient $rpc) {}

    public function creationBlock(string $address): ?int
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return null;
        }

        $base = rtrim((string) config('idtc.chain.explorer'), '/');
        try {
            $res = Http::timeout(8)->acceptJson()->get("{$base}/api/v2/addresses/{$address}");
            if (! $res->ok()) {
                return null;
            }
            // Blockscout 6.x ใช้ creation_transaction_hash · รุ่นเก่าใช้ creation_tx_hash
            $hash = $res->json('creation_transaction_hash') ?? $res->json('creation_tx_hash');
        } catch (Throwable) {
            return null;
        }
        if (! is_string($hash) || ! preg_match('/^0x[0-9a-fA-F]{64}$/', $hash)) {
            return null;
        }

        try {
            $receipt = $this->rpc->getReceipt($hash);
        } catch (RpcException) {
            return null;
        }
        if (! is_array($receipt)
            || strcasecmp((string) ($receipt['contractAddress'] ?? ''), $address) !== 0
            || ! isset($receipt['blockNumber'])) {
            return null;
        }

        return Units::hexToInt((string) $receipt['blockNumber']);
    }
}
