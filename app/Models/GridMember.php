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
 */
class GridMember extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['signature', 'message', 'ip'];

    protected function casts(): array
    {
        return ['bound_at' => 'datetime', 'held_at' => 'datetime'];
    }
}
