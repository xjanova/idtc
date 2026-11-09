<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| IDTC Grid — ใครมาจากลิงก์ของใคร และแผนรางวัลแต่ละเวอร์ชัน
| grid_members: หนึ่งกระเป๋าผูกผู้แนะนำได้ครั้งเดียว ก่อนซื้อครั้งแรก พร้อมข้อความที่เจ้าของกระเป๋าเซ็นไว้เป็นหลักฐาน
| grid_plan_versions: แผนที่แก้จากหลังบ้าน มีผลตั้งแต่สัปดาห์ขาย from_week — การซื้อทุกรายการใช้อัตราของสัปดาห์ตัวเองเสมอ
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_members', function (Blueprint $table) {
            $table->id();
            $table->string('wallet', 42)->unique();      // ตัวพิมพ์เล็กเสมอ
            $table->string('referrer', 42)->nullable()->index(); // null = เข้ามาเอง ไม่มีผู้แนะนำ
            $table->text('message')->nullable();          // ข้อความที่เซ็น (หลักฐานการผูก)
            $table->string('signature', 200)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('held_at')->nullable();     // แอดมินพักรางวัล (สงสัยโกง)
            $table->string('held_reason', 300)->nullable();
            $table->string('held_by', 42)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('grid_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('from_week')->index();
            $table->json('plan');
            $table->string('note', 500)->nullable();
            $table->string('created_by', 42)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_plan_versions');
        Schema::dropIfExists('grid_members');
    }
};
