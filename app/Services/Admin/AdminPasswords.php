<?php

namespace App\Services\Admin;

use App\Models\AdminCredential;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * รหัสผ่านชั้นที่สองของหลังบ้าน (ถามหลังลายเซ็นกระเป๋าผ่านแล้ว)
 *
 *   - ผู้ดูแลที่ตั้งรหัสผ่านไว้ ต้องใส่รหัสผ่านทุกครั้งที่เข้าระบบ
 *   - owner ที่ยังไม่มีรหัสผ่าน (รวม owner จาก .env) และใครที่ถูกรีเซ็ต ต้องตั้งรหัสผ่านก่อนเข้า
 *   - เก็บเฉพาะ Hash::make · ไม่ log ไม่ส่งกลับ · ใส่ผิดเกิน 5 ครั้ง ล็อกที่อยู่นั้น 15 นาที (ไม่ผูก IP — เปลี่ยน IP ก็ไม่ช่วย)
 */
class AdminPasswords
{
    public const MIN_LENGTH = 10;

    /** bcrypt อ่านแค่ 72 ไบต์แรก — ยาวกว่านี้ส่วนท้ายไม่มีผล จึงไม่รับ (ภาษาไทย 1 ตัว = 3 ไบต์) */
    public const MAX_BYTES = 72;

    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public const STEP_ENTER = 'enter';

    public const STEP_SET = 'set';

    public function find(string $address): ?AdminCredential
    {
        return AdminCredential::query()->where('address', strtolower($address))->first();
    }

    /**
     * หลังลายเซ็นผ่าน ต้องทำอะไรต่อ: null = เข้าได้เลย · enter = ใส่รหัสผ่าน · set = ตั้งรหัสผ่านใหม่ก่อน
     */
    public function stepFor(string $address, string $role, ?AdminCredential $cred = null): ?string
    {
        $cred ??= $this->find($address);

        return match (true) {
            $cred?->must_change === true => self::STEP_SET,
            $cred?->password_hash !== null => self::STEP_ENTER,
            $role === 'owner' => self::STEP_SET,
            default => null,
        };
    }

    /** ตรวจรหัสผ่านใหม่ · คืนข้อความผิดพลาด (ไทย) หรือ null ถ้าใช้ได้ — ไม่ใส่ตัวรหัสผ่านลงในข้อความ */
    public function validateNew(mixed $password, mixed $confirmation): ?string
    {
        if (! is_string($password) || $password === '') {
            return 'ใส่รหัสผ่านใหม่';
        }

        return match (true) {
            mb_strlen($password) < self::MIN_LENGTH => 'รหัสผ่านต้องยาวอย่างน้อย '.self::MIN_LENGTH.' ตัวอักษร',
            strlen($password) > self::MAX_BYTES => 'รหัสผ่านยาวเกินไป (ไม่เกิน 72 ไบต์ — อักษรอังกฤษ 72 ตัว หรืออักษรไทยราว 24 ตัว)',
            trim($password) !== $password => 'รหัสผ่านขึ้นต้นหรือลงท้ายด้วยช่องว่างไม่ได้',
            ! is_string($confirmation) || ! hash_equals($password, $confirmation) => 'ยืนยันรหัสผ่านไม่ตรงกัน',
            default => null,
        };
    }

    /** ตั้งรหัสผ่านใหม่ · เพิ่ม version (session เดิมทุกอันของที่อยู่นี้หลุด เว้นแต่ผู้เรียกอัปเดต session ตัวเอง) */
    public function set(string $address, string $password): AdminCredential
    {
        $cred = AdminCredential::query()->firstOrNew(['address' => strtolower($address)]);
        $cred->fill([
            'password_hash' => Hash::make($password),
            'must_change' => false,
            'version' => ((int) $cred->version) + 1,
            'password_changed_at' => now(),
            'reset_by' => null,
        ])->save();
        RateLimiter::clear($this->throttleKey($address));

        return $cred;
    }

    public function check(string $address, string $password, ?AdminCredential $cred = null): bool
    {
        $cred ??= $this->find($address);
        if ($cred === null || $cred->password_hash === null) {
            return false;
        }

        return Hash::check($password, $cred->password_hash);
    }

    /** owner รีเซ็ตรหัสผ่านของคนอื่น: ลบ hash + บังคับตั้งใหม่ในการเข้าครั้งถัดไป + ตัด session เดิม */
    public function reset(string $address, ?string $by): AdminCredential
    {
        $cred = AdminCredential::query()->firstOrNew(['address' => strtolower($address)]);
        $cred->fill([
            'password_hash' => null,
            'must_change' => true,
            'version' => ((int) $cred->version) + 1,
            'reset_by' => $by !== null ? strtolower($by) : null,
        ])->save();
        RateLimiter::clear($this->throttleKey($address));

        return $cred;
    }

    public function forget(string $address): void
    {
        AdminCredential::query()->where('address', strtolower($address))->delete();
        RateLimiter::clear($this->throttleKey($address));
    }

    public function throttleKey(string $address): string
    {
        return 'admin-pw|'.strtolower($address);
    }

    public function lockedSeconds(string $address): int
    {
        $key = $this->throttleKey($address);

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) ? max(1, RateLimiter::availableIn($key)) : 0;
    }

    /** @return int จำนวนครั้งที่ยังเหลือก่อนถูกล็อก */
    public function recordFailure(string $address): int
    {
        $key = $this->throttleKey($address);
        RateLimiter::hit($key, self::LOCK_SECONDS);

        return max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($key));
    }

    public function clearFailures(string $address): void
    {
        RateLimiter::clear($this->throttleKey($address));
    }

    /** ตัวเลขที่ session เก็บไว้เทียบทุกคำขอ — null = ที่อยู่นี้ไม่ต้องใช้รหัสผ่าน */
    public function sessionVersion(string $address, string $role, ?AdminCredential $cred = null): ?int
    {
        $cred ??= $this->find($address);

        return $this->stepFor($address, $role, $cred) === null ? null : (int) ($cred?->version ?? 0);
    }

    public static function lockMessage(int $seconds): string
    {
        return 'ใส่รหัสผ่านผิดหลายครั้ง ระบบล็อกกระเป๋านี้ไว้ชั่วคราว ลองใหม่ในอีก '.max(1, (int) ceil($seconds / 60)).' นาที';
    }
}
