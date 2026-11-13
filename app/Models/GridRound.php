<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * รอบจ่ายรางวัล IDTC Grid หนึ่งรอบ (หนึ่ง root บนสัญญา GridRewards)
 *
 * @property int $id
 * @property string $stream สายจ่าย: grid | bin_usdt | bin_idtc (PayoutRounds)
 * @property string $contract
 * @property int $chain_round
 * @property string $kind
 * @property string $status
 * @property string $root
 * @property string $total
 * @property float $total_idtc
 * @property int $items
 * @property Carbon|null $unlock_at
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon|null $live_at
 */
class GridRound extends Model
{
    public const DRAFT = 'draft';

    public const LIVE = 'live';

    public const REVOKED = 'revoked';

    public const STALE = 'stale';

    public const DISCARDED = 'discarded';

    /** สถานะที่นับเป็นยอดจัดสรรแล้ว (จ่ายซ้ำไม่ได้) */
    public const ALLOCATED = [self::DRAFT, self::LIVE];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'chain_round' => 'integer',
            'total_idtc' => 'float',
            'items' => 'integer',
            'unlock_at' => 'datetime',
            'live_at' => 'datetime',
        ];
    }

    /** @return HasMany<GridRoundItem, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(GridRoundItem::class, 'round_id');
    }
}
