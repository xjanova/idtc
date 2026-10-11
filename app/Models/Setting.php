<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ค่าตั้งแบบ key/value (ค่าเป็น JSON) — อ่านผ่าน App\Support\Settings ที่มีแคช
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
