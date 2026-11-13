<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * กระเป๋าหนึ่งใบใน IDTC Grid และผู้แนะนำของมัน
 *
 * @property string $wallet
 * @property string|null $referrer
 * @property string|null $message
 * @property string|null $signature
 * @property Carbon|null $bound_at
 * @property Carbon|null $held_at
 * @property string|null $held_reason
 * @property string|null $held_by
 * @property string|null $bin_parent กระเป๋าที่อยู่เหนือในผัง Binary (ไม่จำเป็นต้องเป็นผู้แนะนำ)
 * @property string|null $bin_leg L | R
 * @property Carbon|null $bin_placed_at
 * @property string|null $bin_side_pref ขาที่อยากให้คนใหม่ลง (null = อัตโนมัติ) — ค่าเดิม ใช้เมื่อ bin_mode ว่าง
 * @property string|null $bin_mode วิธีวางคนใหม่ที่ผู้ชวนเลือก (GridPlacer::MODES)
 * @property Carbon|null $bin_wait_at อยู่ในคิวรอจัดสายของผู้ชวนตั้งแต่เมื่อไร (null = ไม่ได้รอ)
 * @property string|null $bin_wait_side ขาที่ลิงก์ชวนระบุ (คำแนะนำตอนจัดสาย)
 */
class GridMember extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['signature', 'message', 'ip'];

    protected function casts(): array
    {
        return ['bound_at' => 'datetime', 'held_at' => 'datetime', 'bin_placed_at' => 'datetime', 'bin_wait_at' => 'datetime'];
    }
}
