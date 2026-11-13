<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ผัง Binary (ซ้าย/ขวา) จัดการได้ — จัดสาย · ย้ายสาย · ต่อสาย · เปลี่ยนผู้แนะนำ (owner) · คิวรอจัดสายของแม่ทีม
|
| grid_members.bin_mode: วิธีวางคนใหม่ที่ผู้ชวนเลือก weak | strong | left | right | fill | manual (null = ตาม bin_side_pref เดิม)
| grid_members.bin_wait_at: เข้าคิวรอจัดสายเมื่อไร (ผู้ชวนเลือก manual) · bin_wait_side = ขาที่ลิงก์ชวนระบุ (คำแนะนำ)
|
| grid_tree_changes: ทุกการเปลี่ยนตำแหน่งในผังหลังวางครั้งแรกอัตโนมัติ และทุกการเปลี่ยนผู้แนะนำ
|   ตัวคำนวณใช้ตารางนี้ย้อนดูผัง "ณ สัปดาห์นั้น" — การเปลี่ยนมีผลตั้งแต่สัปดาห์ขายที่ทำรายการ (week)
|   สัปดาห์ที่ปิดไปแล้วคิดด้วยผังเดิม ยอดและรางวัลที่ออกไปแล้วจึงไม่ถูกแบ่งใหม่
|   kind: place (วางครั้งแรกจากคิว/ต่อสายคนที่ยังไม่อยู่ในผัง) | move (ย้ายพร้อมทีม) | referrer (เปลี่ยนผู้แนะนำ)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_members', function (Blueprint $table) {
            $table->string('bin_mode', 8)->nullable();
            $table->timestamp('bin_wait_at')->nullable()->index();
            $table->string('bin_wait_side', 1)->nullable();
        });

        Schema::create('grid_tree_changes', function (Blueprint $table) {
            $table->id();
            $table->string('wallet', 42)->index();
            $table->string('kind', 10);
            $table->string('from_parent', 42)->nullable();
            $table->string('from_leg', 1)->nullable();
            $table->string('to_parent', 42)->nullable();
            $table->string('to_leg', 1)->nullable();
            $table->string('from_referrer', 42)->nullable();
            $table->string('to_referrer', 42)->nullable();
            $table->timestamp('effective_at')->index();
            $table->integer('week');
            $table->unsignedInteger('team')->default(1);   // จำนวนคนที่ย้ายไปด้วย (รวมตัวเอง) ตอนทำรายการ
            $table->string('actor', 42)->nullable();
            $table->string('actor_role', 10);               // owner | admin | member | system
            $table->string('reason', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_tree_changes');
        Schema::table('grid_members', function (Blueprint $table) {
            $table->dropIndex(['bin_wait_at']);
            $table->dropColumn(['bin_mode', 'bin_wait_at', 'bin_wait_side']);
        });
    }
};
