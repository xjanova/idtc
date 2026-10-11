<?php

namespace App\Services\Chain;

use App\Models\AuditLog;
use App\Services\Admin\ChainActions;

/**
 * ตามผลธุรกรรมที่แอดมินส่งจากเบราว์เซอร์ (บันทึกไว้เป็น pending) — ดูจากเชนเองทุกครั้ง ไม่เชื่อสถานะที่เบราว์เซอร์บอก
 *
 * ยืนยันว่า: ธุรกรรมส่งไปที่สัญญาที่บันทึกไว้จริง และเรียกฟังก์ชันตรงกับชื่อคำสั่ง
 * ไม่พบธุรกรรมบนเชนเกิน 2 ชั่วโมง = ถือว่าล้มเหลว (ถูกยกเลิก/หลุดจากคิว)
 */
class TxTracker
{
    public const DROP_AFTER_SECONDS = 7200;

    public function __construct(protected RpcClient $rpc) {}

    /** @return int จำนวนรายการที่เปลี่ยนสถานะ */
    public function refreshPending(int $limit = 20): int
    {
        $changed = 0;
        $pending = AuditLog::query()->where('tx_status', AuditLog::PENDING)->whereNotNull('tx_hash')->oldest()->limit($limit)->get();
        foreach ($pending as $log) {
            try {
                if ($this->check($log)) {
                    $changed++;
                }
            } catch (RpcException) {
                break; // เชนตอบไม่ได้ตอนนี้ — ลองใหม่รอบหน้า
            }
        }

        return $changed;
    }

    /**
     * ตรวจหนึ่งรายการ คืน true ถ้าสถานะเปลี่ยน
     *
     * @throws RpcException
     */
    public function check(AuditLog $log): bool
    {
        if ($log->tx_status !== AuditLog::PENDING || ! $log->tx_hash) {
            return false;
        }
        $details = $log->details ?? [];
        $spec = ChainActions::get($log->action);

        $tx = $this->rpc->getTransaction($log->tx_hash);
        if ($tx === null) {
            if ($log->created_at->diffInSeconds(now()) > self::DROP_AFTER_SECONDS) {
                return $this->finish($log, AuditLog::FAILED, ['reason' => 'ไม่พบธุรกรรมนี้บนเชนภายใน 2 ชั่วโมง (อาจถูกยกเลิกในกระเป๋า)']);
            }

            return false;
        }

        $expectedTo = $details['contract'] ?? null;
        if ($spec === null || ! Address::same($tx['to'] ?? null, $expectedTo)) {
            return $this->finish($log, AuditLog::FAILED, ['reason' => 'ธุรกรรมนี้ไม่ได้ส่งไปที่สัญญาที่บันทึกไว้', 'to' => $tx['to'] ?? null]);
        }
        $input = strtolower((string) ($tx['input'] ?? $tx['data'] ?? ''));
        if (! str_starts_with($input, Abi::selector($spec['method']))) {
            return $this->finish($log, AuditLog::FAILED, ['reason' => 'ธุรกรรมนี้เรียกฟังก์ชันไม่ตรงกับคำสั่งที่บันทึก']);
        }

        $receipt = $this->rpc->getReceipt($log->tx_hash);
        if ($receipt === null) {
            return false; // ยังไม่ลงบล็อก
        }
        $ok = strtolower((string) ($receipt['status'] ?? '')) === '0x1';

        return $this->finish($log, $ok ? AuditLog::CONFIRMED : AuditLog::FAILED, [
            'from' => strtolower((string) ($receipt['from'] ?? $tx['from'] ?? '')),
            'block' => isset($receipt['blockNumber']) ? Units::hexToInt((string) $receipt['blockNumber']) : null,
            'reason' => $ok ? null : 'สัญญาปฏิเสธธุรกรรม (revert) — ค่าบนเชนไม่เปลี่ยน',
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function finish(AuditLog $log, string $status, array $extra): bool
    {
        $details = $log->details ?? [];
        $details['result'] = array_filter($extra, fn ($v) => $v !== null);
        $log->forceFill(['tx_status' => $status, 'details' => $details])->save();

        return true;
    }
}
