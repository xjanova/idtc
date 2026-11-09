<?php

namespace App\Console\Commands;

use App\Services\Chain\SalesIndexer;
use App\Services\Chain\TransferIndexer;
use App\Services\Chain\TxTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('idtc:index-sales {--max-chunks=40 : จำนวนช่วง 5,000 บล็อกสูงสุดต่อรอบ}')]
#[Description('ดึงยอดซื้อ presale (event Purchased) จากเชน TPIX ลงฐานข้อมูล การโอนเหรียญ IDTC (ตรวจการถือของ Grid) และตามผลธุรกรรมของหลังบ้านที่ค้างอยู่')]
class IndexSales extends Command
{
    public function handle(SalesIndexer $indexer, TransferIndexer $transfers, TxTracker $tracker): int
    {
        $result = $indexer->run(max(1, (int) $this->option('max-chunks')));
        $line = "[{$result['status']}] {$result['message']}".($result['inserted'] ? " · รายการใหม่ {$result['inserted']}" : '');
        $result['ok'] ? $this->info($line) : $this->warn($line);

        $moved = $transfers->run(max(1, (int) $this->option('max-chunks')));
        $line2 = "[{$moved['status']}] {$moved['message']}".($moved['inserted'] ? " · การโอนใหม่ {$moved['inserted']}" : '');
        $moved['ok'] || $moved['status'] === 'busy' ? $this->info($line2) : $this->warn($line2);

        $changed = $tracker->refreshPending();
        if ($changed > 0) {
            $this->info("อัปเดตสถานะธุรกรรมของหลังบ้าน {$changed} รายการ");
        }

        // busy = มีรอบอื่นทำอยู่แล้ว ไม่ใช่ความผิดพลาด
        return $result['ok'] || $result['status'] === 'busy' ? self::SUCCESS : self::FAILURE;
    }
}
