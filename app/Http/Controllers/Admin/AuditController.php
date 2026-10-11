<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\ChainActions;
use App\Services\Chain\TxTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request, TxTracker $tracker): View
    {
        if (AuditLog::query()->where('tx_status', AuditLog::PENDING)->exists()) {
            $tracker->refreshPending(10);
        }

        $type = (string) $request->query('type', '');
        $query = AuditLog::query()->latest('id');
        match ($type) {
            'chain' => $query->whereNotNull('tx_hash'),
            'settings' => $query->where(fn ($q) => $q->where('action', 'like', 'settings.%')->orWhere('action', 'like', 'content.%')->orWhere('action', 'like', 'wallets.%')),
            'auth' => $query->where('action', 'like', 'auth.%'),
            'admins' => $query->where('action', 'like', 'admins.%'),
            default => null,
        };

        return view('admin.audit', [
            'title' => 'บันทึกการทำงาน',
            'logs' => $query->paginate(30)->withQueryString(),
            'type' => in_array($type, ['chain', 'settings', 'auth', 'admins'], true) ? $type : '',
            'chainActions' => ChainActions::ALL,
        ]);
    }
}
