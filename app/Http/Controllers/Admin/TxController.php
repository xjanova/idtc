<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\Audit;
use App\Services\Admin\ChainActions;
use App\Services\Chain\Address;
use App\Services\Chain\PoolReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\RpcException;
use App\Services\Chain\TxTracker;
use App\Services\Chain\VestingReader;
use App\Support\Idtc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * บันทึกธุรกรรมที่แอดมินเซ็นและส่งเองจากเบราว์เซอร์ แล้วตามผลจากเชน
 * (เซิร์ฟเวอร์ไม่เซ็น ไม่ส่ง และไม่เชื่อสถานะที่เบราว์เซอร์บอก — ตรวจจากเชนเอง)
 */
class TxController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $action = (string) $request->input('action');
        $spec = ChainActions::get($action);
        if ($spec === null) {
            return response()->json(['message' => 'ไม่รู้จักคำสั่งนี้'], 422);
        }
        $hash = strtolower((string) $request->input('tx_hash'));
        if (! preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
            return response()->json(['message' => 'tx hash ไม่ถูกต้อง'], 422);
        }
        $params = ChainActions::cleanParams($action, (array) $request->input('params', []));
        if ($params === null) {
            return response()->json(['message' => 'ค่าที่ส่งมากับคำสั่งไม่ถูกรูปแบบ'], 422);
        }
        $contract = Idtc::contract($spec['contract']);
        if ($contract === null) {
            return response()->json(['message' => 'สัญญานี้ยังไม่ได้ตั้งที่อยู่'], 422);
        }

        // ส่งซ้ำ (เช่น เน็ตหลุดแล้วเบราว์เซอร์ลองใหม่) = คืนรายการเดิม ไม่บันทึกซ้ำ
        $existing = AuditLog::query()->where('tx_hash', $hash)->first();
        if ($existing !== null) {
            return response()->json($this->payload($existing));
        }

        $from = $request->input('from');
        $log = Audit::record($action, array_filter([
            'contract' => strtolower($contract),
            'params' => $params,
            'from' => Address::isValid($from) ? strtolower((string) $from) : null,
        ], fn ($v) => $v !== null && $v !== []), null, $hash, AuditLog::PENDING);

        return response()->json($this->payload($log), 201);
    }

    public function check(AuditLog $log, TxTracker $tracker, PresaleReader $presale, VestingReader $vesting, PoolReader $pools): JsonResponse
    {
        if (! $log->tx_hash) {
            return response()->json(['message' => 'รายการนี้ไม่ใช่ธุรกรรมบนเชน'], 422);
        }
        try {
            $tracker->check($log);
        } catch (RpcException $e) {
            return response()->json($this->payload($log) + ['message' => $e->getMessage()], 503);
        }
        $log->refresh();
        if ($log->tx_status === AuditLog::CONFIRMED) {
            // ค่าบนเชนเปลี่ยนแล้ว — ล้างแคชให้หน้าถัดไปเห็นค่าใหม่ทันที
            $presale->forget();
            $vesting->forget();
            $pools->forget();
        }

        return response()->json($this->payload($log));
    }

    /** @return array<string, mixed> */
    protected function payload(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'status' => $log->tx_status,
            'tx_hash' => $log->tx_hash,
            'explorer' => Idtc::explorerTx($log->tx_hash),
            'reason' => $log->details['result']['reason'] ?? null,
        ];
    }
}
