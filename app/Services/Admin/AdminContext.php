<?php

namespace App\Services\Admin;

use App\Services\Chain\Address;

/**
 * ผู้ดูแลที่เข้าระบบอยู่ในคำขอนี้ — middleware EnsureAdmin เป็นคนตั้งค่า
 * สิทธิ์อ่านใหม่จาก AdminRegistry ทุกคำขอ ถอนสิทธิ์แล้วมีผลทันที
 */
class AdminContext
{
    public function __construct(
        public readonly string $address,
        public readonly string $role,
        public readonly ?string $label = null,
    ) {}

    public function checksum(): string
    {
        return Address::checksum($this->address);
    }

    public function can(string $minRole): bool
    {
        return AdminRegistry::atLeast($this->role, $minRole);
    }

    public function canWrite(): bool
    {
        return $this->can('admin');
    }

    public function isOwner(): bool
    {
        return $this->can('owner');
    }

    public function roleLabel(): string
    {
        return AdminRegistry::LABELS[$this->role] ?? $this->role;
    }
}
