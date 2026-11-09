<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * แผนรางวัล Grid หนึ่งเวอร์ชัน — มีผลตั้งแต่สัปดาห์ขาย from_week (0 = สัปดาห์แรก)
 *
 * @property int $from_week
 * @property array<string, mixed> $plan
 * @property string|null $note
 * @property string|null $created_by
 */
class GridPlanVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_week' => 'integer', 'plan' => 'array'];
    }
}
