<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| แผน Binary โปรโมชั่น (idtc.online/binary) — แยกจาก presale และ IDTC Grid
|
| binary_deposits: การโอน USDT (เชน TPIX) เข้า "กระเป๋า Binary" ของโครงการ อ่านจาก event Transfer บนเชน
|   ฝาก = ซื้อ IDTC ทันทีตามราคาของสัปดาห์นั้น (ไม่มียอดเครดิตค้าง) · เซิร์ฟเวอร์ไม่ถือคีย์ — แค่อ่านเชน
|   status: ok = นับเป็นยอดซื้อ · off/min/quota/ended = ไม่นับ (แอดมินต้องคืนเงินเอง ดู reason)
|   price / idtc / pool_usdt ถูกบันทึกตอนอ่านรายการ และไม่เปลี่ยนอีก (แก้แผนภายหลังไม่กระทบรายการเก่า)
| binary_plan_versions: อัตราของแผน มีผลตั้งแต่สัปดาห์ from_week (ปฏิทินสัปดาห์เดียวกับ presale/Grid)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('tx_hash', 66);
            $table->unsignedInteger('log_index');
            $table->string('wallet', 42)->index();        // ผู้โอน (ตัวพิมพ์เล็ก) = สมาชิกที่ได้ยอด
            $table->string('to', 42);                     // กระเป๋า Binary ที่รับ ณ ตอนนั้น
            $table->string('amount', 80);                 // หน่วยเล็กสุดของ USDT (6 ทศนิยม)
            $table->double('usdt');
            $table->unsignedBigInteger('block_number')->index();
            $table->timestamp('block_time')->nullable();
            $table->unsignedInteger('week')->default(0)->index();
            $table->string('status', 12)->default('ok')->index();
            $table->string('price', 40)->nullable();      // USDT ต่อ 1 IDTC ที่ใช้
            $table->double('idtc')->default(0);           // IDTC ที่ซื้อได้
            $table->double('pool_usdt')->default(0);      // ส่วนที่เข้ากองรางวัลจับคู่ของสัปดาห์
            $table->timestamp('refunded_at')->nullable(); // แอดมินบันทึกว่าคืนเงินรายการที่ไม่นับแล้ว
            $table->string('refund_tx', 66)->nullable();
            $table->string('refunded_by', 42)->nullable();
            $table->timestamps();
            $table->unique(['tx_hash', 'log_index']);
        });

        Schema::create('binary_plan_versions', function (Blueprint $table) {
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
        Schema::dropIfExists('binary_plan_versions');
        Schema::dropIfExists('binary_deposits');
    }
};
