<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * การโอน USDT เข้ากระเป๋า Binary หนึ่งรายการ (อ่านจากเชน) — ดู migration create_binary_promo_tables
 *
 * @property int $id
 * @property string $tx_hash
 * @property int $log_index
 * @property string $wallet
 * @property string $to
 * @property string $amount
 * @property float $usdt
 * @property int $block_number
 * @property Carbon|null $block_time
 * @property int $week
 * @property string $status
 * @property string|null $price
 * @property float $idtc
 * @property float $pool_usdt
 * @property Carbon|null $refunded_at
 * @property string|null $refund_tx
 * @property string|null $refunded_by
 */
class BinaryDeposit extends Model
{
    public const OK = 'ok';

    /** เหตุที่ไม่นับเป็นยอดซื้อ → ข้อความไทย (หลังบ้าน) */
    public const REASONS = [
        'off' => 'โอนตอนแผนปิดอยู่',
        'min' => 'ต่ำกว่าขั้นต่ำ',
        'quota' => 'เกินโควตา IDTC ของแผน',
        'ended' => 'โอนหลังแผนสิ้นสุด',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usdt' => 'float', 'idtc' => 'float', 'pool_usdt' => 'float', 'week' => 'integer', 'log_index' => 'integer',
            'block_number' => 'integer', 'block_time' => 'datetime', 'refunded_at' => 'datetime',
        ];
    }
}
