<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\Admin\Audit;
use App\Services\Admin\SalesStats;
use App\Services\Chain\ChainStatus;
use App\Services\Chain\SalesIndexer;
use App\Services\Chain\TxTracker;
use App\Services\Chain\Units;
use App\Support\AdminFormat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesController extends Controller
{
    public function index(Request $request, SalesStats $stats, SalesIndexer $indexer, ChainStatus $chain): View
    {
        [$filters, $error] = $this->filters($request);
        $query = $this->filtered($stats->query(), $filters);
        $summary = (clone $query)->selectRaw('COUNT(*) as c, COALESCE(SUM(pay_usdt), 0) as u, COALESCE(SUM(tokens_idtc), 0) as t, COUNT(DISTINCT buyer) as b')->first();

        return view('admin.sales', [
            'title' => 'ยอดขาย',
            'filters' => $filters,
            'filterError' => $error,
            'summary' => $summary,
            'purchases' => $query->latest('block_number')->latest('log_index')->paginate(25)->withQueryString(),
            'indexState' => $indexer->state(),
            'head' => $chain->head(),
        ]);
    }

    public function export(Request $request, SalesStats $stats): StreamedResponse
    {
        [$filters] = $this->filters($request);
        $query = $this->filtered($stats->query(), $filters)->orderBy('block_number')->orderBy('log_index');
        $name = 'idtc-presale-sales-'.CarbonImmutable::now(AdminFormat::TZ)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // ให้ Excel อ่านภาษาไทยถูก
            fputcsv($out, ['time_th', 'time_utc', 'buyer', 'pay_usdt', 'tokens_idtc', 'pay_amount_raw', 'tokens_out_raw', 'tx_hash', 'log_index', 'block']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    /** @var Purchase $p */
                    fputcsv($out, [
                        $p->block_time?->setTimezone(AdminFormat::TZ)->format('Y-m-d H:i:s'),
                        $p->block_time?->utc()->format('Y-m-d\TH:i:s\Z'),
                        $p->buyer,
                        Units::fromRaw($p->pay_amount, 6),
                        Units::fromRaw($p->tokens_out, 18),
                        $p->pay_amount,
                        $p->tokens_out,
                        $p->tx_hash,
                        $p->log_index,
                        $p->block_number,
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sync(Request $request, SalesIndexer $indexer, TxTracker $tracker): RedirectResponse|JsonResponse
    {
        // ปุ่มบนหน้าเว็บ: ดึงไม่เกิน 10 ช่วง (50,000 บล็อก) ต่อครั้ง ไม่ให้คำขอค้างนาน — ที่เหลือ cron ดึงต่อ
        @set_time_limit(90);
        $result = $indexer->run(10);
        $tracker->refreshPending();
        Audit::record('sales.sync', ['message' => $result['message'], 'inserted' => $result['inserted']]);

        if ($request->expectsJson()) {
            return response()->json($result, $result['ok'] ? 200 : 503);
        }
        $msg = $result['message'].($result['inserted'] > 0 ? " · รายการใหม่ {$result['inserted']} รายการ" : '');

        return back()->with($result['ok'] ? 'status' : 'error', $msg);
    }

    /** @return array{0: array{from: ?string, to: ?string, wallet: ?string}, 1: ?string} */
    protected function filters(Request $request): array
    {
        $f = ['from' => null, 'to' => null, 'wallet' => null];
        $errors = [];
        foreach (['from', 'to'] as $k) {
            $v = $request->query($k);
            if (is_string($v) && $v !== '') {
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $f[$k] = $v;
                } else {
                    $errors['date'] = 'รูปแบบวันที่ไม่ถูกต้อง (ใช้ปี ค.ศ. เช่น 2026-10-11)';
                }
            }
        }
        $wallet = trim((string) $request->query('wallet', ''));
        if ($wallet !== '') {
            if (preg_match('/^0x[0-9a-fA-F]{1,40}$/', $wallet)) {
                $f['wallet'] = strtolower($wallet);
            } else {
                $errors['wallet'] = 'ค้นหากระเป๋าด้วยที่อยู่ที่ขึ้นต้นด้วย 0x เท่านั้น';
            }
        }
        if ($f['from'] && $f['to'] && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }

        return [$f, $errors ? implode(' · ', $errors) : null];
    }

    /**
     * @param  Builder<Purchase>  $q
     * @param  array{from: ?string, to: ?string, wallet: ?string}  $f
     * @return Builder<Purchase>
     */
    protected function filtered(Builder $q, array $f): Builder
    {
        if ($f['from']) {
            $q->where('block_time', '>=', CarbonImmutable::createFromFormat('Y-m-d', $f['from'], AdminFormat::TZ)->startOfDay()->utc());
        }
        if ($f['to']) {
            $q->where('block_time', '<', CarbonImmutable::createFromFormat('Y-m-d', $f['to'], AdminFormat::TZ)->startOfDay()->addDay()->utc());
        }
        if ($f['wallet']) {
            strlen($f['wallet']) === 42
                ? $q->where('buyer', $f['wallet'])
                : $q->where('buyer', 'like', $f['wallet'].'%');
        }

        return $q;
    }
}
