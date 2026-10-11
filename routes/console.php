<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ดึงยอด presale จากเชนทุกนาที — ต้องมี cron เรียก schedule:run (ดู README หัวข้อ "งานตามเวลา")
Schedule::command('idtc:index-sales')->everyMinute()->withoutOverlapping(10);
