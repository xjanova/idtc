<?php

namespace App\Console\Commands;

use App\Services\Grid\GridPlacer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('idtc:grid-place')]
#[Description('วางตำแหน่งผัง Binary ให้กระเป๋าที่ผูกผู้แนะนำไว้ก่อนมีผัง (ตามลำดับที่ผูก) — รันซ้ำได้ ไม่ย้ายคนที่วางแล้ว')]
class PlaceGridMembers extends Command
{
    public function handle(GridPlacer $placer): int
    {
        $lock = Cache::lock(GridPlacer::LOCK, 120);
        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            $this->error('มีการผูกผู้แนะนำกำลังทำอยู่ — ลองใหม่อีกครั้ง');

            return self::FAILURE;
        }
        try {
            $placed = DB::transaction(fn () => $placer->placeMissing());
        } finally {
            $lock->release();
        }
        $this->info("วางตำแหน่งแล้ว {$placed} กระเป๋า");

        return self::SUCCESS;
    }
}
