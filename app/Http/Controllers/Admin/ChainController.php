<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Chain\Address;
use App\Services\Chain\ChainStatus;
use App\Services\Chain\FeederReader;
use App\Services\Chain\PoolReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\VestingReader;
use App\Support\Idtc;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * หน้าควบคุมสัญญา: Presale · สัญญาล็อก · พูลสภาพคล่อง
 * ค่าที่แสดงอ่านจากเชนฝั่งเซิร์ฟเวอร์ (แคช 15 วินาที) · คำสั่งเขียนเซ็นในกระเป๋าบนเบราว์เซอร์เท่านั้น
 */
class ChainController extends Controller
{
    public function presale(PresaleReader $reader, FeederReader $feeder, ChainStatus $chain): View
    {
        $p = $reader->read();
        $feederAddress = Idtc::contract('feeder');
        $owner = ($p['ok'] ?? false) ? ($p['v']['owner'] ?? null) : null;

        return view('admin.presale', [
            'title' => 'Presale',
            'p' => $p,
            'fd' => $feeder->read(),
            // ขายผ่าน TPIX Launchpad: เจ้าของ presale คือสัญญาเติมพูล → คำสั่งเจ้าของต้องส่งผ่านสัญญานั้น
            'viaFeeder' => $feederAddress !== null && $owner !== null && Address::same($owner, $feederAddress),
            // ตั้งสัญญาเติมพูลไว้ แต่ไม่ใช่เจ้าของ presale ตัวนี้ — ที่อยู่ในหน้าตั้งค่าน่าจะผิด
            'feederMismatch' => $feederAddress !== null && $owner !== null && ! Address::same($owner, $feederAddress),
            'head' => $chain->head(),
            'txs' => $this->recentTx('presale.', 'feeder.'),
        ]);
    }

    public function vesting(VestingReader $reader, ChainStatus $chain): View
    {
        return view('admin.vesting', [
            'title' => 'สัญญาล็อก',
            'vst' => $reader->read(),
            'head' => $chain->head(),
            'txs' => $this->recentTx('vesting.'),
        ]);
    }

    public function pools(PoolReader $reader, ChainStatus $chain): View
    {
        return view('admin.pools', [
            'title' => 'พูลสภาพคล่อง',
            'pools' => $reader->read(),
            'head' => $chain->head(),
        ]);
    }

    /** @return Collection<int, AuditLog> */
    protected function recentTx(string ...$prefixes)
    {
        return AuditLog::query()
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->whereNotNull('tx_hash')->latest('id')->limit(6)->get();
    }
}
