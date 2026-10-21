<?php

namespace App\Http\Controllers;

use App\Services\Bridge\BridgeApi;
use Illuminate\Http\JsonResponse;

/**
 * JSON สำหรับหน้า /bridge — ตัวกลางไปยังสะพาน USDT ของ tpix.online (App\Services\Bridge\BridgeApi)
 * อ่านอย่างเดียว ไม่มีการส่งเงินผ่านเซิร์ฟเวอร์: ผู้ใช้เซ็นทุกรายการในกระเป๋าของตัวเอง
 */
class BridgeController extends Controller
{
    public function __construct(private readonly BridgeApi $bridge) {}

    public function info(): JsonResponse
    {
        return response()->json($this->bridge->info())->header('Cache-Control', 'no-store');
    }

    public function deposit(string $txHash): JsonResponse
    {
        if (! preg_match(BridgeApi::HASH, $txHash)) {
            return response()->json(['state' => 'invalid_hash'], 422);
        }

        return response()->json($this->bridge->deposit($txHash))->header('Cache-Control', 'no-store');
    }

    public function withdrawal(string $txHash): JsonResponse
    {
        if (! preg_match(BridgeApi::HASH, $txHash)) {
            return response()->json(['state' => 'invalid_hash'], 422);
        }

        return response()->json($this->bridge->withdrawal($txHash))->header('Cache-Control', 'no-store');
    }
}
