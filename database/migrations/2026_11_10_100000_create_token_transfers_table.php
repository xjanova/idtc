<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| การโอนเหรียญ IDTC (event Transfer ของสัญญาเหรียญ) — php artisan idtc:index-sales ดึงต่อจากยอดขาย
| ใช้ตรวจว่าผู้ซื้อถือเหรียญครบระยะ Grid จริง (ขาย/โอนออกก่อนครบ = ตัดรางวัลส่วนนั้น)
| value = จำนวนเต็มหน่วยเล็กสุดเป็นข้อความ · amount = ค่าเดียวกันแบบทศนิยม (IDTC)
|
| indexer_state.last_block_time = เวลาของบล็อกสุดท้ายที่ดึงครบแล้ว → การซื้อที่ครบระยะถือหลังเวลานี้ยังตัดสินไม่ได้
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('contract', 42);
            $table->string('tx_hash', 66);
            $table->unsignedInteger('log_index');
            $table->string('from', 42)->index(); // ตัวพิมพ์เล็กเสมอ
            $table->string('to', 42)->index();
            $table->string('value', 80);
            $table->double('amount');
            $table->unsignedBigInteger('block_number')->index();
            $table->timestamp('block_time')->nullable();
            $table->timestamps();
            $table->unique(['tx_hash', 'log_index']);
        });

        Schema::table('indexer_state', function (Blueprint $table) {
            $table->timestamp('last_block_time')->nullable()->after('last_block');
        });
    }

    public function down(): void
    {
        Schema::table('indexer_state', function (Blueprint $table) {
            $table->dropColumn('last_block_time');
        });
        Schema::dropIfExists('token_transfers');
    }
};
