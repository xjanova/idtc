<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('idtc:admin-password-reset {address : ที่อยู่กระเป๋าผู้ดูแล 0x…}')]
#[Description('รีเซ็ตรหัสผ่านหลังบ้าน (ทางกู้เมื่อเจ้าของคนเดียวลืมรหัสผ่าน) — ผู้นั้นตั้งรหัสใหม่หลังเซ็นกระเป๋าครั้งถัดไป')]
class ResetAdminPassword extends Command
{
    public function handle(AdminRegistry $registry, AdminPasswords $passwords): int
    {
        $address = strtolower(trim((string) $this->argument('address')));
        if (! Address::isValid($address) || $registry->roleFor($address) === null) {
            $this->error('ไม่พบผู้ดูแลกระเป๋านี้');

            return self::FAILURE;
        }

        $passwords->reset($address, null);
        Audit::record('admins.password_reset', ['address' => $address, 'via' => 'cli']);
        $this->info('รีเซ็ตแล้ว — เข้าสู่ระบบด้วยกระเป๋านี้ แล้วตั้งรหัสผ่านใหม่');

        return self::SUCCESS;
    }
}
