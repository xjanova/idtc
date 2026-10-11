<?php

namespace App\Services\Admin;

use App\Models\AuditLog;

/**
 * เขียนบันทึกการทำงาน — ห้ามใส่ความลับใน $details (ที่อยู่กระเป๋า/ค่าตั้ง/ข้อความประกาศเท่านั้น)
 */
final class Audit
{
    /** @param array<string, mixed> $details */
    public static function record(string $action, array $details = [], ?AdminContext $actor = null, ?string $txHash = null, ?string $txStatus = null): AuditLog
    {
        $actor ??= app()->bound(AdminContext::class) ? app(AdminContext::class) : null;

        return AuditLog::query()->create([
            'actor' => $actor?->address,
            'role' => $actor?->role,
            'action' => $action,
            'details' => $details ?: null,
            'tx_hash' => $txHash,
            'tx_status' => $txStatus,
            'ip' => request()->ip(),
        ]);
    }

    /**
     * บันทึกการเปลี่ยนค่า (ค่าเดิม → ค่าใหม่) — ไม่บันทึกถ้าค่าไม่ได้เปลี่ยน
     *
     * @param  array<string, mixed>  $extra
     */
    public static function change(string $action, mixed $old, mixed $new, array $extra = []): ?AuditLog
    {
        if ($old === $new) {
            return null;
        }

        return self::record($action, $extra + ['old' => $old, 'new' => $new]);
    }
}
