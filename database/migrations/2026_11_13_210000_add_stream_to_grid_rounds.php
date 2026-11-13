<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| รอบจ่ายใช้ร่วมกันหลายสาย (App\Services\Payout\PayoutRounds) — แยกด้วยคอลัมน์ stream
|   grid      รางวัล IDTC Grid (ค่าเริ่มต้น — รอบที่มีอยู่แล้วทั้งหมดเป็นของ Grid)
|   bin_usdt  รางวัลจับคู่ของแผน Binary โปรโมชั่น (จ่าย USDT)
|   bin_idtc  IDTC ที่ซื้อในแผน Binary โปรโมชั่น
| คอลัมน์ total_idtc / amount_idtc ของสาย bin_usdt เก็บจำนวน USDT (หน่วยเต็มของเหรียญที่สายนั้นจ่าย)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grid_rounds', function (Blueprint $table) {
            $table->string('stream', 16)->default('grid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('grid_rounds', function (Blueprint $table) {
            $table->dropIndex(['stream']);
            $table->dropColumn('stream');
        });
    }
};
