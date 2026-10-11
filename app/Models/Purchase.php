<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * รายการซื้อ presale หนึ่งรายการ (หนึ่ง event Purchased บนเชน)
 *
 * @property string $contract
 * @property string $tx_hash
 * @property int $log_index
 * @property string $buyer
 * @property string $pay_amount
 * @property string $tokens_out
 * @property float $pay_usdt
 * @property float $tokens_idtc
 * @property int $block_number
 * @property Carbon|null $block_time
 */
class Purchase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'log_index' => 'integer',
            'block_number' => 'integer',
            'pay_usdt' => 'float',
            'tokens_idtc' => 'float',
            'block_time' => 'datetime',
        ];
    }
}
