<?php

namespace App\Console\Commands;

use App\Services\Binary\DepositIndexer;
use App\Services\Chain\SalesIndexer;
use App\Services\Chain\TransferIndexer;
use App\Services\Chain\TxTracker;
use App\Services\Grid\GridRounds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('idtc:index-sales {--max-chunks=40 : จำนวนช่วง 5,000 บล็อกสูงสุดต่อรอบ}')]
#[Description('ดึงยอดซื้อ presale (event Purchased) จากเชน TPIX ลงฐานข้อมูล การโอนเหรียญ IDTC (ตรวจการถือของ Grid) ตามผลธุรกรรมของหลังบ้านที่ค้างอยู่ และสถานะรอบจ่ายรางวัล Grid')]
class IndexSales extends Command
{
    public function handle(SalesIndexer $indexer, TransferIndexer $transfers, TxTracker $tracker, GridRounds $rounds, DepositIndexer $deposits): int
    {
        $result = $indexer->run(max(1, (int) $this->option('max-chunks')));
        $line = "[{$result['status']}] {$result['message']}".($result['inserted'] ? " · รายการใหม่ {$result['inserted']}" : '');
        $result['ok'] ? $this->info($line) : $this->warn($line);

        $moved = $transfers->run(max(1, (int) $this->option('max-chunks')));
        $line2 = "[{$moved['status']}] {$moved['message']}".($moved['inserted'] ? " · การโอนใหม่ {$moved['inserted']}" : '');
        $moved['ok'] || $moved['status'] === 'busy' ? $this->info($line2) : $this->warn($line2);

        // แผน Binary โปรโมชั่น: การโอน USDT เข้ากระเป๋า Binary (ยังไม่ตั้งกระเป๋า = ข้าม)
        $bin = $deposits->run(max(1, (int) $this->option('max-chunks')));
        if ($bin['status'] !== 'idle') {
            $line3 = "[{$bin['status']}] {$bin['message']}".($bin['inserted'] ? " · ยอดฝากใหม่ {$bin['inserted']}" : '');
            $bin['ok'] || $bin['status'] === 'busy' ? $this->info($line3) : $this->warn($line3);
        }

        $changed = $tracker->refreshPending();
        if ($changed > 0) {
            $this->info("อัปเดตสถานะธุรกรรมของหลังบ้าน {$changed} รายการ");
        }

        // รอบจ่ายรางวัล Grid: ตัดสินสถานะจากเชน (ประกาศแล้ว / ยกเลิก / ใช้ไม่ได้) + ใครรับไปแล้วในรอบที่ยกเลิก
        $rounds->reconcile() > 0 && $this->info('อัปเดตสถานะรอบจ่ายรางวัล Grid แล้ว');

        // busy = มีรอบอื่นทำอยู่แล้ว ไม่ใช่ความผิดพลาด
        return $result['ok'] || $result['status'] === 'busy' ? self::SUCCESS : self::FAILURE;
    }
}
