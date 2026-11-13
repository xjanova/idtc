<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use phpseclib3\Math\BigInteger;

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
 * @property string $idtc_micro
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

    /**
     * IDTC ที่ได้จาก USDT จำนวนนี้ แบบจำนวนเต็มแม่นยำ หน่วย 0.000001 IDTC ปัดลง (ไม่ผ่านทศนิยมลอยตัว)
     *
     * @param  string  $usdtMicro  USDT หน่วยเล็กสุด (6 ทศนิยม)
     * @param  string  $price  USDT ต่อ 1 IDTC เป็นข้อความทศนิยม เช่น "0.0000015"
     */
    public static function microIdtc(string $usdtMicro, string $price): string
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $price) || ! preg_match('/^\d+$/', $usdtMicro)) {
            return '0';
        }
        [$int, $frac] = array_pad(explode('.', $price), 2, '');
        $priceInt = new BigInteger(ltrim($int.$frac, '0') ?: '0');           // ราคา × 10^ทศนิยม
        if ($priceInt->equals(new BigInteger(0))) {
            return '0';
        }
        // idtc_micro = usdt_micro ÷ ราคา = usdt_micro × 10^ทศนิยม ÷ priceInt
        [$q] = (new BigInteger($usdtMicro))->multiply(new BigInteger('1'.str_repeat('0', strlen($frac))))->divide($priceInt);

        return $q->toString();
    }

    protected function casts(): array
    {
        return [
            'usdt' => 'float', 'idtc' => 'float', 'pool_usdt' => 'float', 'week' => 'integer', 'log_index' => 'integer',
            'block_number' => 'integer', 'block_time' => 'datetime', 'refunded_at' => 'datetime',
        ];
    }
}
