<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รางวัลของหนึ่งกระเป๋าในหนึ่งรอบ + proof สำหรับกดรับบนสัญญา
 *
 * @property int $round_id
 * @property string $wallet
 * @property string $amount
 * @property float $amount_idtc
 * @property list<string> $proof
 * @property bool|null $claimed อ่านจากเชนตอนรอบถูกยกเลิก
 */
class GridRoundItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_idtc' => 'float', 'proof' => 'array', 'claimed' => 'boolean'];
    }

    /** @return BelongsTo<GridRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(GridRound::class, 'round_id');
    }
}
