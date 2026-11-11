<?php

namespace App\Services\Admin;

use App\Services\Chain\Address;

/**
 * ผู้ดูแลที่เข้าระบบอยู่ในคำขอนี้ — middleware EnsureAdmin เป็นคนตั้งค่า
 * สิทธิ์อ่านใหม่จาก AdminRegistry ทุกคำขอ ถอนสิทธิ์แล้วมีผลทันที
 */
class AdminContext
{
    /**
     * @param  array<string, string>  $permissions  section => view|edit (owner ไม่ใช้ค่านี้ — ได้ทุกอย่าง)
     */
    public function __construct(
        public readonly string $address,
        public readonly string $role,
        public readonly ?string $label = null,
        public readonly array $permissions = [],
    ) {}

    public function checksum(): string
    {
        return Address::checksum($this->address);
    }

    /** เทียบระดับ role (ใช้กับงานของ owner เท่านั้น เช่น admin.role:owner) */
    public function can(string $minRole): bool
    {
        return AdminRegistry::atLeast($this->role, $minRole);
    }

    public function isOwner(): bool
    {
        return $this->can('owner');
    }

    public function canView(?string $section): bool
    {
        if ($section === null || ! isset(AdminPermissions::SECTIONS[$section])) {
            return false;
        }

        return $this->isOwner() || isset($this->permissions[$section]);
    }

    public function canEdit(?string $section): bool
    {
        if ($section === null || ! AdminPermissions::editable($section)) {
            return false;
        }

        return $this->isOwner() || ($this->permissions[$section] ?? null) === AdminPermissions::EDIT;
    }

    /** แก้/สั่งงานได้อย่างน้อยหนึ่งส่วน (ใช้แสดงป้ายสิทธิ์) */
    public function hasAnyEdit(): bool
    {
        return $this->isOwner() || AdminPermissions::hasAnyEdit($this->permissions);
    }

    /** หน้าแรกที่เปิดได้ (ภาพรวมถ้าดูได้) · null = ยังไม่มีสิทธิ์ดูส่วนไหนเลย */
    public function homeRoute(): ?string
    {
        foreach (AdminPermissions::SECTIONS as $section => $meta) {
            if ($this->canView($section)) {
                return $meta['route'];
            }
        }

        return null;
    }

    public function roleLabel(): string
    {
        $label = AdminRegistry::LABELS[$this->role] ?? $this->role;
        if (! $this->isOwner() && ! AdminPermissions::isPreset($this->permissions, $this->role)) {
            $label .= ' · สิทธิ์กำหนดเอง';
        }

        return $label;
    }
}
