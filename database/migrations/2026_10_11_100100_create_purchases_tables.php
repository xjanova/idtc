<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ยอดขาย presale ที่ดึงจาก event Purchased บนเชน (php artisan idtc:index-sales)
| pay_amount / tokens_out = จำนวนเต็มหน่วยเล็กสุดเป็นข้อความ (แม่นยำ ไม่ปัด)
| pay_usdt / tokens_idtc = ค่าเดียวกันแบบทศนิยม ใช้รวมยอด/ทำกราฟเท่านั้น
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('contract', 42);
            $table->string('tx_hash', 66);
            $table->unsignedInteger('log_index');
            $table->string('buyer', 42)->index(); // ตัวพิมพ์เล็กเสมอ
            $table->string('pay_amount', 80);      // หน่วย 1e-6 USDT
            $table->string('tokens_out', 80);      // หน่วย 1e-18 IDTC
            $table->double('pay_usdt');
            $table->double('tokens_idtc');
            $table->unsignedBigInteger('block_number')->index();
            $table->timestamp('block_time')->nullable()->index();
            $table->timestamps();
            $table->unique(['tx_hash', 'log_index']);
        });

        Schema::create('indexer_state', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('contract', 42)->nullable();
            $table->unsignedBigInteger('last_block')->nullable();
            $table->unsignedBigInteger('head_block')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexer_state');
        Schema::dropIfExists('purchases');
    }
};
