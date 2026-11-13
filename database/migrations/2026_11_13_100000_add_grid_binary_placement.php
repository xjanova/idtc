<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| IDTC Grid — ตำแหน่งในผัง Binary (สองขา ซ้าย/ขวา) ของแต่ละกระเป๋า
|
| ผู้แนะนำ (referrer) ยังเป็นตัวกำหนดรางวัลวง 1..3 ตามเดิม · bin_parent คือ "คนที่อยู่เหนือในผัง Binary"
| ซึ่งอาจไม่ใช่ผู้แนะนำ (คนที่ผู้แนะนำชวนมาเกินสองคนถูกวางต่อท้ายขานั้นลงไปเรื่อย ๆ = spillover)
| ตำแหน่งวางครั้งเดียวตอนผูกผู้แนะนำ ไม่ย้าย · หนึ่งตำแหน่ง (bin_parent + bin_leg) มีได้คนเดียว
| bin_side_pref: ขาที่เจ้าของกระเป๋าอยากให้คนใหม่ลงเมื่อลิงก์ชวนไม่ได้ระบุขา (null = อัตโนมัติ ขาที่คนน้อยกว่า)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_members', function (Blueprint $table) {
            $table->string('bin_parent', 42)->nullable();
            $table->string('bin_leg', 1)->nullable();          // L | R
            $table->timestamp('bin_placed_at')->nullable();
            $table->string('bin_side_pref', 1)->nullable();    // L | R | null = อัตโนมัติ
            $table->unique(['bin_parent', 'bin_leg'], 'grid_members_bin_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('grid_members', function (Blueprint $table) {
            $table->dropUnique('grid_members_bin_slot_unique');
            $table->dropColumn(['bin_parent', 'bin_leg', 'bin_placed_at', 'bin_side_pref']);
        });
    }
};
