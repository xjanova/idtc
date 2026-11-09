<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| รอบจ่ายรางวัล IDTC Grid (สัญญา GridRewards) — หลังบ้านสร้างรายชื่อจากยอด "ครบระยะถือ" แล้วเจ้าของประกาศ root บนเชน
|
| grid_rounds.status: draft (สร้างแล้ว ยังไม่ประกาศ) · live (root บนเชนตรงกับรายชื่อนี้) · revoked (ยกเลิกบนเชน)
|                     stale (เลขรอบนี้บนเชนเป็น root อื่น — รายชื่อนี้ใช้ไม่ได้แล้ว) · discarded (แอดมินทิ้งก่อนประกาศ)
| ยอดที่ "จัดสรรแล้ว" ของแต่ละกระเป๋า = รวม amount ในรอบ draft + live + รอบ revoked ที่รับไปแล้ว (หรือยังอ่านไม่ได้)
|   → รอบถัดไปจ่ายเฉพาะส่วนที่ครบเพิ่ม · ยกเลิกรอบแล้ว คนที่รับไปแล้วไม่ได้ซ้ำ
| grid_round_items.claimed: อ่านจากเชนตอนรอบถูกยกเลิก (หลังยกเลิกรับไม่ได้อีก ค่านี้จึงคงที่) · null = ยังไม่ได้อ่าน
| amount = หน่วยเล็กสุดของเหรียญ (ข้อความ) · amount_idtc = ค่าเดียวกันแบบทศนิยม
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('contract', 42);
            $table->unsignedInteger('chain_round');
            $table->string('kind', 16);           // full | instant | deferred
            $table->string('status', 16)->index();
            $table->string('root', 66);
            $table->string('total', 80);
            $table->double('total_idtc');
            $table->unsignedInteger('items');
            $table->timestamp('unlock_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('created_by', 42)->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamps();
            $table->index(['contract', 'chain_round']);
        });

        Schema::create('grid_round_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('grid_rounds')->cascadeOnDelete();
            $table->string('wallet', 42)->index();
            $table->string('amount', 80);
            $table->double('amount_idtc');
            $table->text('proof');                // JSON list ของ bytes32
            $table->boolean('claimed')->nullable();
            $table->unique(['round_id', 'wallet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_round_items');
        Schema::dropIfExists('grid_rounds');
    }
};
