<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * รหัสผ่านของผู้ดูแล (ชั้นที่สองหลังลายเซ็นกระเป๋า) — ผูกกับที่อยู่ ใช้ได้ทั้งกระเป๋าใน .env และในตาราง admins
 *
 * @property int $id
 * @property string $address
 * @property string|null $password_hash
 * @property bool $must_change
 * @property int $version
 * @property Carbon|null $password_changed_at
 * @property string|null $reset_by
 */
class AdminCredential extends Model
{
    protected $fillable = ['address', 'password_hash', 'must_change', 'version', 'password_changed_at', 'reset_by'];

    /** ห้าม hash หลุดไปกับ toArray()/JSON */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'must_change' => 'boolean',
            'version' => 'integer',
            'password_changed_at' => 'datetime',
        ];
    }

    protected function setAddressAttribute(string $value): void
    {
        $this->attributes['address'] = strtolower($value);
    }
}
