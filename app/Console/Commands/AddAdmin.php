<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Chain\Address;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('idtc:admin {address : ที่อยู่กระเป๋า 0x…} {--role=owner : owner | admin | viewer} {--label= : ชื่อเรียก} {--remove : ลบออกจากตาราง admins}')]
#[Description('เพิ่ม/เปลี่ยน/ลบผู้ดูแลหลังบ้านจากบรรทัดคำสั่ง (ทางสำรองเมื่อเข้าหน้าเว็บไม่ได้)')]
class AddAdmin extends Command
{
    public function handle(): int
    {
        $address = (string) $this->argument('address');
        if (! Address::isValid($address) || ! Address::hasValidChecksum($address)) {
            $this->error('ที่อยู่ไม่ถูกต้อง (ต้องเป็น 0x ตามด้วย 40 ตัวอักษรฐานสิบหก และ checksum ถูก)');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $deleted = Admin::query()->where('address', strtolower($address))->delete();
            $this->info($deleted ? 'ลบแล้ว' : 'ไม่พบที่อยู่นี้ในตาราง admins');

            return self::SUCCESS;
        }

        $role = (string) $this->option('role');
        if (! in_array($role, Admin::ROLES, true)) {
            $this->error('role ต้องเป็น owner, admin หรือ viewer');

            return self::FAILURE;
        }

        Admin::query()->updateOrCreate(
            ['address' => strtolower($address)],
            ['role' => $role, 'label' => $this->option('label') ?: null, 'added_by' => 'cli'],
        );
        $this->info("บันทึกแล้ว: {$address} = {$role}");

        return self::SUCCESS;
    }
}
