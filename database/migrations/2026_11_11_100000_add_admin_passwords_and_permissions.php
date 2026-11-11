<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| หลังบ้าน IDTC: รหัสผ่านชั้นที่สอง (หลังเซ็นกระเป๋า) + สิทธิ์รายเมนู
|
| admin_credentials ผูกกับที่อยู่กระเป๋า ไม่ใช่แถวใน admins — เจ้าของจาก IDTC_ADMIN_WALLETS (.env)
| ไม่มีแถวใน admins แต่ต้องมีรหัสผ่านได้ · เก็บเฉพาะ hash (Hash::make) ไม่เก็บรหัสผ่านจริง
| version เพิ่มทุกครั้งที่ตั้ง/รีเซ็ตรหัสผ่าน — session ที่ผูกกับ version เก่าหลุดทันที
|
| admins.permissions: {"sales":"edit","audit":"view",...} · null = ใช้ชุดสิทธิ์ตาม role (viewer/admin)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        Schema::create('admin_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->unique(); // ตัวพิมพ์เล็กเสมอ
            $table->string('password_hash')->nullable();
            $table->boolean('must_change')->default(false);
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('password_changed_at')->nullable();
            $table->string('reset_by', 42)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_credentials');
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
