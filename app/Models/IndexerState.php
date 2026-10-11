<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ตำแหน่งล่าสุดของตัวดึงยอดขาย (หนึ่งแถวต่อหนึ่งงาน เช่น key = sales)
 *
 * @property string $key
 * @property string|null $contract
 * @property int|null $last_block
 * @property int|null $head_block
 * @property string|null $last_error
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_success_at
 */
class IndexerState extends Model
{
    protected $table = 'indexer_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_block' => 'integer',
            'head_block' => 'integer',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
