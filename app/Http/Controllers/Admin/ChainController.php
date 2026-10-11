<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Chain\ChainStatus;
use App\Services\Chain\PoolReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\VestingReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * หน้าควบคุมสัญญา: Presale · สัญญาล็อก · พูลสภาพคล่อง
 * ค่าที่แสดงอ่านจากเชนฝั่งเซิร์ฟเวอร์ (แคช 15 วินาที) · คำสั่งเขียนเซ็นในกระเป๋าบนเบราว์เซอร์เท่านั้น
 */
class ChainController extends Controller
{
    public function presale(PresaleReader $reader, ChainStatus $chain): View
    {
        return view('admin.presale', [
            'title' => 'Presale',
            'p' => $reader->read(),
            'head' => $chain->head(),
            'txs' => $this->recentTx('presale.'),
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
    protected function recentTx(string $prefix)
    {
        return AuditLog::query()->where('action', 'like', $prefix.'%')->whereNotNull('tx_hash')->latest('id')->limit(6)->get();
    }
}
