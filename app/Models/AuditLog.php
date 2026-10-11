<?php

namespace App\Models;

use App\Services\Admin\AdminRegistry;
use App\Services\Admin\ChainActions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * บันทึกการทำงานของหลังบ้าน: ใคร (กระเป๋า + สิทธิ์) ทำอะไร ค่าเดิม → ค่าใหม่ และธุรกรรมบนเชน (ถ้ามี)
 *
 * @property int $id
 * @property string|null $actor
 * @property string|null $role
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property string|null $tx_hash
 * @property string|null $tx_status
 * @property string|null $ip
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    /** ชื่อการกระทำที่ไม่ใช่ธุรกรรมบนเชน (ธุรกรรมบนเชนอยู่ใน ChainActions) */
    public const ACTIONS = [
        'auth.login' => 'เข้าระบบ (ลายเซ็นถูกต้อง)',
        'auth.denied' => 'พยายามเข้าระบบ (ถูกปฏิเสธ)',
        'auth.logout' => 'ออกจากระบบ',
        'settings.contract' => 'ตั้งที่อยู่สัญญา',
        'settings.indexer' => 'ตั้งบล็อกเริ่มดึงยอดขาย',
        'content.announcement' => 'แก้ประกาศหน้าเว็บ',
        'content.announcement_en' => 'แก้ประกาศหน้าเว็บภาษาอังกฤษ',
        'content.social' => 'แก้ลิงก์โซเชียล',
        'wallets.update' => 'แก้รายชื่อกระเป๋าโครงการ',
        'admins.add' => 'เพิ่มผู้ดูแล',
        'admins.role' => 'เปลี่ยนสิทธิ์ผู้ดูแล',
        'admins.remove' => 'ลบผู้ดูแล',
        'sales.sync' => 'ซิงก์ยอดขายจากเชน',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? ChainActions::label($this->action) ?? $this->action;
    }

    public function actorLabel(): string
    {
        if ($this->role) {
            return AdminRegistry::LABELS[$this->role] ?? $this->role;
        }

        return $this->action === 'auth.denied' ? 'ไม่มีสิทธิ์' : 'ระบบ';
    }

    public function isChainAction(): bool
    {
        return ChainActions::has($this->action);
    }
}
