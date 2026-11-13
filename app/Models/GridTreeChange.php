<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * หนึ่งการเปลี่ยนในผัง Binary / สายแนะนำ — ดู migration add_binary_tree_management
 *
 * @property int $id
 * @property string $wallet
 * @property string $kind place | move | referrer
 * @property string|null $from_parent
 * @property string|null $from_leg
 * @property string|null $to_parent
 * @property string|null $to_leg
 * @property string|null $from_referrer
 * @property string|null $to_referrer
 * @property Carbon $effective_at
 * @property int $week สัปดาห์ขายที่เริ่มมีผล
 * @property int $team
 * @property string|null $actor
 * @property string $actor_role owner | admin | member | system
 * @property string|null $reason
 * @property Carbon $created_at
 */
class GridTreeChange extends Model
{
    public const PLACE = 'place';

    public const MOVE = 'move';

    public const REFERRER = 'referrer';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'week' => 'integer', 'team' => 'integer'];
    }
}
