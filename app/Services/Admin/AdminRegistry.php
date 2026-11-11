<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Services\Chain\Address;
use Throwable;

/**
 * ใครเข้าหลังบ้านได้ และได้สิทธิ์อะไร
 *
 *   1. IDTC_ADMIN_WALLETS ใน .env (config idtc.admin_wallets) — ตัวแรกที่ถูกรูปแบบ = owner ที่เหลือ = admin
 *      แก้จากหน้าเว็บไม่ได้ (กันเจ้าของล็อกตัวเองออก)
 *   2. ตาราง admins — owner เพิ่ม/เปลี่ยน/ลบจากหน้า "ผู้ดูแล"
 *   3. ไม่มีผู้ดูแลเลย (ทั้งสองข้อว่าง) = คนแรกที่เปิด /admin/setup ตั้งตัวเองเป็น owner (เจ้าของเลือกแบบนี้เอง)
 *
 * สิทธิ์: owner = ทุกอย่าง + จัดการผู้ดูแล · คนอื่นได้สิทธิ์รายเมนู (AdminPermissions) ที่ owner ติ๊กให้
 *   viewer/admin เป็นชุดสิทธิ์สำเร็จรูป: viewer = ดูทุกส่วน · admin = ดู + แก้ทุกส่วน
 */
class AdminRegistry
{
    public const RANK = ['viewer' => 1, 'admin' => 2, 'owner' => 3];

    public const LABELS = ['owner' => 'เจ้าของ (Owner)', 'admin' => 'แอดมิน', 'viewer' => 'ดูอย่างเดียว'];

    /** @return array<string, string> ที่อยู่ตัวพิมพ์เล็ก => role */
    public function envAdmins(): array
    {
        $out = [];
        foreach ((array) config('idtc.admin_wallets', []) as $address) {
            if (! Address::isValid($address)) {
                continue;
            }
            $out[strtolower($address)] ??= $out === [] ? 'owner' : 'admin';
        }

        return $out;
    }

    public function roleFor(?string $address): ?string
    {
        $a = Address::lower($address);
        if ($a === null) {
            return null;
        }
        $env = $this->envAdmins();
        if (isset($env[$a])) {
            return $env[$a];
        }

        try {
            $role = Admin::query()->where('address', $a)->value('role');
        } catch (Throwable) {
            return null;
        }

        return isset(self::RANK[$role]) ? $role : null;
    }

    public function labelFor(?string $address): ?string
    {
        $a = Address::lower($address);
        if ($a === null) {
            return null;
        }
        if (isset($this->envAdmins()[$a])) {
            return $this->envAdmins()[$a] === 'owner' ? 'เจ้าของ (.env)' : 'แอดมิน (.env)';
        }

        try {
            return Admin::query()->where('address', $a)->value('label');
        } catch (Throwable) {
            return null;
        }
    }

    public function isEnvAdmin(string $address): bool
    {
        return isset($this->envAdmins()[strtolower($address)]);
    }

    /**
     * มีผู้ดูแลอย่างน้อยหนึ่งคนหรือยัง — อ่านฐานข้อมูลไม่ได้ถือว่า "มีแล้ว" (ปิดหน้าตั้งค่าครั้งแรกไว้ก่อน
     * ไม่เปิดให้ใครก็ได้ยึดหลังบ้านตอนฐานข้อมูลมีปัญหา)
     */
    public function hasAnyAdmin(): bool
    {
        if ($this->envAdmins() !== []) {
            return true;
        }

        try {
            return Admin::query()->exists();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * สิทธิ์รายเมนูของที่อยู่นี้ — owner ได้ครบเสมอ · แอดมินจาก .env ได้ชุด admin · แถวในตารางใช้ค่าที่ติ๊ก (ว่าง = ตาม role)
     *
     * @return array<string, string>
     */
    public function permissionsFor(string $address, string $role): array
    {
        if ($role === 'owner' || $this->isEnvAdmin($address)) {
            return AdminPermissions::preset($role);
        }

        try {
            $raw = Admin::query()->where('address', strtolower($address))->value('permissions');
        } catch (Throwable) {
            return [];
        }
        $perms = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($perms) ? AdminPermissions::normalize($perms) : AdminPermissions::preset($role);
    }

    /** จำนวน owner ทั้งหมด (env + ตาราง) — กันลบ owner คนสุดท้าย */
    public function ownerCount(): int
    {
        $env = count(array_filter($this->envAdmins(), fn ($r) => $r === 'owner'));

        return $env + Admin::query()->where('role', 'owner')->count();
    }

    public static function atLeast(?string $role, string $min): bool
    {
        return (self::RANK[$role] ?? 0) >= (self::RANK[$min] ?? PHP_INT_MAX);
    }
}
