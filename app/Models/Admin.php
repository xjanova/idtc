<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ผู้ดูแลที่เพิ่มจากหลังบ้าน (นอกเหนือจาก IDTC_ADMIN_WALLETS ใน .env)
 *
 * @property int $id
 * @property string $address
 * @property string $role
 * @property string|null $label
 * @property string|null $added_by
 */
class Admin extends Model
{
    public const ROLES = ['owner', 'admin', 'viewer'];

    protected $fillable = ['address', 'role', 'label', 'added_by'];

    protected function setAddressAttribute(string $value): void
    {
        $this->attributes['address'] = strtolower($value);
    }
}
