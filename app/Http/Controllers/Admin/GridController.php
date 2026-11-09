<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\Purchase;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * หลังบ้าน IDTC Grid — ภาพรวม/งบ · สมาชิก · รางวัลรายการซื้อ · แผน (แก้ได้ มีผลสัปดาห์ถัดไป) · พักรางวัล · เปิด/ปิดระบบ
 * ตัวเลขทั้งหมดคำนวณสดจากยอดซื้อบนเชน (GridLedger) — ยังไม่มีรอบจ่ายบนเชนในช่วงนี้
 */
class GridController extends Controller
{
    public const STATUS = [
        GridLedger::READY => 'ครบระยะถือ รอรอบจ่าย',
        GridLedger::WAIT => 'รอผู้ซื้อถือครบ',
        GridLedger::HELD => 'พักไว้ (ตรวจโกง)',
        GridLedger::RANK => 'ระดับไม่ถึง คืนงบ',
        GridLedger::NONE => 'ไม่มีสายบน คืนงบ',
    ];

    public const RANKS = ['none' => 'ยังไม่ซื้อ', 'spark' => 'สปาร์ก', 'cell' => 'เซลล์', 'grid' => 'กริด', 'core' => 'คอร์'];

    public function __construct(protected GridPlan $plans, protected GridLedger $ledger) {}

    public function index(): View
    {
        $book = $this->ledger->book();

        return view('admin.grid.index', [
            'title' => 'IDTC Grid',
            'book' => $book,
            'enabled' => $this->plans->enabled(),
            'plan' => $this->plans->current(),
            'pending' => $this->plans->pending(),
        ]);
    }

    public function members(Request $request): View
    {
        $book = $this->ledger->book();
        $q = strtolower(trim((string) $request->query('q', '')));
        $rank = (string) $request->query('rank', '');
        $sort = in_array($request->query('sort'), ['team', 'own', 'ready', 'directs', 'bound'], true) ? $request->query('sort') : 'team';
        $error = $q !== '' && ! preg_match('/^0x[0-9a-f]{1,40}$/', $q) ? 'ค้นด้วยที่อยู่กระเป๋า (ขึ้นต้นด้วย 0x)' : null;

        $rows = array_filter($book->nodes, function ($n) use ($q, $rank, $error) {
            if ($error === null && $q !== '' && ! str_starts_with($n['wallet'], $q)) {
                return false;
            }

            return $rank === '' || GridLedger::RANKS[$n['rank']] === $rank;
        });
        $key = ['team' => 'team_usdt', 'own' => 'own_usdt', 'ready' => 'ready', 'directs' => 'directs', 'bound' => 'bound_at'][$sort];
        usort($rows, fn ($a, $b) => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));

        return view('admin.grid.members', [
            'title' => 'สมาชิก Grid',
            'book' => $book,
            'rows' => $this->paginate($rows, $request, 50),
            'filters' => ['q' => $q, 'rank' => $rank, 'sort' => $sort],
            'filterError' => $error,
        ]);
    }

    public function member(string $wallet): View
    {
        $wallet = strtolower($wallet);
        $book = $this->ledger->book();
        $node = $book->node($wallet) ?? abort(404);

        return view('admin.grid.member', [
            'title' => 'สมาชิก '.substr($wallet, 0, 10).'…',
            'book' => $book,
            'n' => $node,
            'row' => GridMember::query()->where('wallet', $wallet)->first(),
            'upline' => $book->upline($wallet, 10),
            'rings' => $book->rings($wallet, 3, 100),
            'rewards' => $book->rewardsTo($wallet, 200),
            'purchases' => Purchase::query()->where('buyer', $wallet)->orderByDesc('block_time')->limit(100)->get(),
        ]);
    }

    public function ledger(Request $request): View
    {
        $book = $this->ledger->book();
        [$rows, $filters, $error] = $this->ledgerRows($request, $book);

        return view('admin.grid.ledger', [
            'title' => 'รางวัลรายการซื้อ',
            'book' => $book,
            'rows' => $this->paginate($rows, $request, 50),
            'filters' => $filters,
            'filterError' => $error,
            'sum' => array_sum(array_column($rows, 'amount')),
            'count' => count($rows),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $book = $this->ledger->book();
        [$rows] = $this->ledgerRows($request, $book);
        $name = 'idtc-grid-rewards-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['time_utc', 'week', 'tx_hash', 'buyer', 'pay_usdt', 'idtc_bought', 'kind', 'ring', 'to', 'rate', 'reward_idtc', 'status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    gmdate('Y-m-d H:i:s', $r['at']), $r['week'] + 1, $r['tx'], $r['buyer'], number_format($r['usdt'], 6, '.', ''),
                    number_format($r['idtc'], 6, '.', ''), $r['kind'], $r['ring'], $r['to'] ?? '', number_format($r['rate'], 6, '.', ''),
                    number_format($r['amount'], 6, '.', ''), $r['status'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function plan(): View
    {
        $book = $this->ledger->book();

        return view('admin.grid.plan', [
            'title' => 'แผนรางวัล Grid',
            'book' => $book,
            'plan' => $this->plans->pending()['plan'] ?? $this->plans->current(),
            'current' => $this->plans->current(),
            'pending' => $this->plans->pending(),
            'versions' => array_reverse($this->plans->versions()),
            'nextWeek' => GridPlan::nextEffectiveWeek(),
            'nowWeek' => GridPlan::weekAt(now()),
            'saleCap' => GridPlan::saleCap(),
            'ops' => GridPlan::operationsAllocation(),
        ]);
    }

    public function planStore(Request $request): RedirectResponse
    {
        $pct = fn ($v) => is_numeric($v) ? (float) $v / 100 : -1.0;
        $input = $request->input();
        $boost = [];
        foreach ((array) ($input['boost'] ?? []) as $b) {
            if (! is_array($b) || (($b['from'] ?? '') === '' && ($b['to'] ?? '') === '' && ($b['add'] ?? '') === '')) {
                continue;
            }
            // หน้าจอนับสัปดาห์จาก 1 (สัปดาห์ 1–3) · ข้อมูลนับจาก 0 แบบ [from, to)
            $boost[] = ['from' => (int) ($b['from'] ?? 0) - 1, 'to' => (int) ($b['to'] ?? 0), 'add' => $pct($b['add'] ?? null)];
        }
        $plan = GridPlan::normalize([
            'levels' => [$pct($input['l1'] ?? null), $pct($input['l2'] ?? null), $pct($input['l3'] ?? null)],
            'buyer' => $pct($input['buyer'] ?? null),
            'boost' => $boost,
            'sprint' => $pct($input['sprint'] ?? null),
            'sprint_cap' => $pct($input['sprint_cap'] ?? null),
            'hold_days' => is_numeric($input['hold_days'] ?? null) ? (int) $input['hold_days'] : -1,
            'instant' => $pct($input['instant'] ?? null),
            'rank' => [
                'spark_own' => is_numeric($input['spark_own'] ?? null) ? (float) $input['spark_own'] : -1,
                'cell_d' => is_numeric($input['cell_d'] ?? null) ? (int) $input['cell_d'] : -1,
                'grid_d' => is_numeric($input['grid_d'] ?? null) ? (int) $input['grid_d'] : -1,
                'grid_t' => is_numeric($input['grid_t'] ?? null) ? (float) $input['grid_t'] : -1,
                'core_d' => is_numeric($input['core_d'] ?? null) ? (int) $input['core_d'] : -1,
                'core_t' => is_numeric($input['core_t'] ?? null) ? (float) $input['core_t'] : -1,
            ],
            'budget' => preg_match('/^\d{1,15}$/', (string) ($input['budget'] ?? '')) ? (string) $input['budget'] : '0',
        ]);
        $note = trim((string) $request->input('note', ''));

        $errors = GridPlan::errors($plan);
        if (mb_strlen($note) < 8 || mb_strlen($note) > 500) {
            $errors['note'] = 'เขียนเหตุผลของการแก้แผน 8–500 ตัวอักษร (ขึ้นในบันทึกการทำงาน)';
        }
        // ภาระสูงสุดถ้าขายหมด ต้องไม่เกินงบ — ทั้งอัตราที่ใช้อยู่ (สัปดาห์นี้) และอัตราใหม่
        if (! isset($errors['budget'])) {
            $book = $this->ledger->book(fresh: true);
            $maxRate = max(GridPlan::maxRate($this->plans->current()), GridPlan::maxRate($plan));
            $worst = $book->totals['committed'] + max(0.0, GridPlan::saleCap() - $book->totals['sold_idtc']) * $maxRate;
            if ($worst > (float) $plan['budget'] + 1e-6) {
                $errors['budget'] = 'ภาระสูงสุดถ้าขายหมด '.number_format($worst).' IDTC เกินงบ '.number_format((float) $plan['budget']).' IDTC — ลดอัตรา หรือเพิ่มงบ';
            }
        }
        if ($errors) {
            return back()->withInput()->withErrors($errors)->with('error', 'แผนยังบันทึกไม่ได้: '.reset($errors));
        }

        $from = GridPlan::nextEffectiveWeek();
        $before = $this->plans->pending()['plan'] ?? $this->plans->current();
        if ($before == $plan) {
            return back()->with('status', 'แผนไม่ได้เปลี่ยน');
        }
        $by = app(AdminContext::class)->address;
        // แก้หลายครั้งก่อนถึงสัปดาห์ที่มีผล = แก้เวอร์ชันที่รออยู่ ไม่ซ้อนเวอร์ชัน
        $row = GridPlanVersion::query()->where('from_week', '>=', $from)->orderByDesc('id')->first() ?? new GridPlanVersion;
        $row->fill(['from_week' => $from, 'plan' => $plan, 'note' => $note, 'created_by' => $by])->save();
        GridPlanVersion::query()->where('from_week', '>=', $from)->whereKeyNot($row->id)->delete();
        $this->plans->forget();
        Audit::record('grid.plan', ['from_week' => $from + 1, 'note' => $note, 'old' => $before, 'new' => $plan]);

        return redirect()->route('admin.grid.plan')->with('status', $from === 0 && GridPlan::weekAt(now()) < 0
            ? 'บันทึกแผนแล้ว — มีผลตั้งแต่เปิดขายสัปดาห์แรก'
            : 'บันทึกแผนแล้ว — มีผลตั้งแต่สัปดาห์ขายที่ '.($from + 1).' (สัปดาห์ที่กำลังขายใช้อัตราเดิม)');
    }

    public function hold(Request $request, string $wallet): RedirectResponse
    {
        $wallet = strtolower($wallet);
        if (! Address::isValid($wallet)) {
            abort(404);
        }
        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 300) {
            return back()->withErrors(['reason' => 'เขียนเหตุผลที่พัก 8–300 ตัวอักษร'])->with('error', 'เขียนเหตุผลที่พัก 8–300 ตัวอักษร');
        }
        $row = GridMember::query()->firstOrNew(['wallet' => $wallet]);
        if ($row->held_at !== null) {
            return back()->with('status', 'กระเป๋านี้ถูกพักอยู่แล้ว');
        }
        $row->fill(['held_at' => now(), 'held_reason' => $reason, 'held_by' => app(AdminContext::class)->address])->save();
        Audit::record('grid.hold', ['wallet' => $wallet, 'reason' => $reason]);

        return back()->with('status', 'พักรางวัลของกระเป๋านี้แล้ว — รางวัลที่เข้าและที่เกิดจากการซื้อของกระเป๋านี้จะไม่เข้ารอบจ่ายจนกว่าจะปลด');
    }

    public function unhold(string $wallet): RedirectResponse
    {
        $row = GridMember::query()->where('wallet', strtolower($wallet))->whereNotNull('held_at')->first();
        if ($row === null) {
            return back()->with('status', 'กระเป๋านี้ไม่ได้ถูกพัก');
        }
        $reason = $row->held_reason;
        $row->fill(['held_at' => null, 'held_reason' => null, 'held_by' => null])->save();
        Audit::record('grid.unhold', ['wallet' => $row->wallet, 'was' => $reason]);

        return back()->with('status', 'ปลดการพักแล้ว — รางวัลกลับไปนับตามปกติ');
    }

    public function toggle(Request $request, Settings $settings): RedirectResponse
    {
        $on = $request->boolean('enabled');
        $old = $this->plans->enabled();
        if ($old === $on) {
            return back()->with('status', $on ? 'ระบบเปิดอยู่แล้ว' : 'ระบบปิดอยู่แล้ว');
        }
        $settings->put('grid.enabled', $on, app(AdminContext::class)->address);
        Audit::change('grid.enabled', $old, $on);

        return back()->with('status', $on
            ? 'เปิด IDTC Grid แล้ว — หน้า /grid และลิงก์ชวนใช้งานได้ เมนู "ชวนเพื่อน" ขึ้นบนเว็บ'
            : 'ปิด IDTC Grid แล้ว — หน้า /grid ตอบ 404 ลิงก์ชวนพาไปหน้าแรก ข้อมูลและรางวัลที่เกิดแล้วยังอยู่ครบ');
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: string|null}
     */
    protected function ledgerRows(Request $request, $book): array
    {
        $wallet = strtolower(trim((string) $request->query('wallet', '')));
        $status = (string) $request->query('status', '');
        $week = (string) $request->query('week', '');
        $error = null;
        if ($wallet !== '' && ! preg_match('/^0x[0-9a-f]{1,40}$/', $wallet)) {
            $error = 'กระเป๋าต้องขึ้นต้นด้วย 0x';
            $wallet = '';
        }
        if ($status !== '' && ! isset(self::STATUS[$status])) {
            $status = '';
        }
        if ($week !== '' && ! preg_match('/^\d{1,3}$/', $week)) {
            $week = '';
        }
        $rows = [];
        for ($i = count($book->events) - 1; $i >= 0; $i--) {
            $e = $book->events[$i];
            if ($week !== '' && (int) $week !== $e['week'] + 1) {
                continue;
            }
            foreach ($e['rewards'] as $r) {
                if ($status !== '' && $r['status'] !== $status) {
                    continue;
                }
                if ($wallet !== '' && ! str_starts_with($e['buyer'], $wallet) && ! str_starts_with((string) $r['to'], $wallet)) {
                    continue;
                }
                $rows[] = ['tx' => $e['tx'], 'buyer' => $e['buyer'], 'at' => $e['at'], 'week' => $e['week'], 'usdt' => $e['usdt'], 'idtc' => $e['idtc'], 'confirm_at' => $e['confirm_at']] + $r;
            }
        }

        return [$rows, ['wallet' => $wallet, 'status' => $status, 'week' => $week], $error];
    }

    /** @param list<mixed> $rows */
    protected function paginate(array $rows, Request $request, int $per): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return (new LengthAwarePaginator(array_slice(array_values($rows), ($page - 1) * $per, $per), count($rows), $per, $page, [
            'path' => $request->url(),
        ]))->withQueryString();
    }
}
