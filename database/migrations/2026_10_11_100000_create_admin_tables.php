<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| หลังบ้าน IDTC: ผู้ดูแล (เข้าด้วยกระเป๋า), ค่าตั้ง (key/value JSON) และบันทึกการทำงาน
| เซิร์ฟเวอร์ไม่เก็บคีย์ใด ๆ — ตารางพวกนี้มีแค่ที่อยู่กระเป๋าสาธารณะ
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->unique(); // ตัวพิมพ์เล็กเสมอ
            $table->string('role', 16);               // owner | admin | viewer
            $table->string('label', 80)->nullable();
            $table->string('added_by', 42)->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->string('updated_by', 42)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor', 42)->nullable();
            $table->string('role', 16)->nullable();
            $table->string('action', 64)->index();
            $table->json('details')->nullable();
            $table->string('tx_hash', 66)->nullable()->unique();
            $table->string('tx_status', 16)->nullable()->index(); // pending | confirmed | failed
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('admins');
    }
};
