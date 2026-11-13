<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * อัตราของแผน Binary โปรโมชั่น หนึ่งเวอร์ชัน (มีผลตั้งแต่สัปดาห์ from_week)
 *
 * @property int $id
 * @property int $from_week
 * @property array<string, mixed> $plan
 * @property string|null $note
 * @property string|null $created_by
 */
class BinaryPlanVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'from_week' => 'integer'];
    }
}
