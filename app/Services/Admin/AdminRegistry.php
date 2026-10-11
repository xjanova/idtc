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
 *
 * สิทธิ์: viewer = ดูอย่างเดียว · admin = แก้ค่าตั้ง + ใช้หน้าคำสั่งบนเชน · owner = ทุกอย่าง + จัดการผู้ดูแล
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

    public function hasAnyAdmin(): bool
    {
        if ($this->envAdmins() !== []) {
            return true;
        }

        try {
            return Admin::query()->exists();
        } catch (Throwable) {
            return false;
        }
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
