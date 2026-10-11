<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\SalesStats;
use App\Services\Chain\ChainStatus;
use App\Services\Chain\PoolReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\SalesIndexer;
use App\Services\Chain\TxTracker;
use App\Services\Chain\VestingReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        SalesStats $stats,
        PresaleReader $presale,
        VestingReader $vesting,
        PoolReader $pools,
        ChainStatus $chain,
        SalesIndexer $indexer,
        TxTracker $tracker,
    ): View {
        if (AuditLog::query()->where('tx_status', AuditLog::PENDING)->exists()) {
            $tracker->refreshPending(5);
        }

        $range = (int) $request->query('range', 14) === 30 ? 30 : 14;
        $today = $stats->todayStart();

        return view('admin.dashboard', [
            'title' => 'ภาพรวม',
            'range' => $range,
            'series' => $stats->series($range),
            'today' => $stats->between($today, $today->addDay()),
            'yesterday' => $stats->between($today->subDay(), $today),
            'totals' => $stats->totals(),
            'newBuyers' => $stats->newBuyersSince($today),
            'recent' => $stats->query()->latest('block_number')->latest('log_index')->limit(8)->get(),
            'presale' => $presale->read(),
            'vesting' => $vesting->read(),
            'pools' => $pools->read(),
            'head' => $chain->head(),
            'indexState' => $indexer->state(),
            'logs' => AuditLog::query()->latest('id')->limit(6)->get(),
        ]);
    }
}
