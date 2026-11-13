<?php

namespace App\Http\Controllers;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Services\Admin\AdminRegistry;
use App\Services\Auth\SiweAuth;
use App\Services\Auth\SiweException;
use App\Services\Binary\BinaryLedger;
use App\Services\Binary\BinaryPlan;
use App\Services\Binary\BinaryRounds;
use App\Services\Chain\Address;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridPlacer;
use App\Services\Grid\GridPlan;
use App\Services\Grid\TreeEditor;
use App\Services\Grid\TreeView;
use App\Support\Idtc;
use App\Support\SiteLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * หน้าสาธารณะของแผน Binary โปรโมชั่น (/binary) + ลิงก์ชวน (/b/{wallet}/{l|r}) + API ของสมาชิก
 *
 *  - แผนปิดอยู่ (หลังบ้าน binary.enabled) = ทุกเส้นทางตอบ 404 ยกเว้นผู้ที่เข้าหลังบ้านไว้ (ดูตัวอย่างได้)
 *  - สมาชิกเข้าระบบด้วยการเซ็นข้อความ (EIP-4361, URI /binary) — ใช้ session สมาชิกเดียวกับ /grid (grid.address)
 *  - ผูกผู้ชวน = GridBinder ตัวเดียวกับ Grid (ผังซ้าย/ขวาใช้ร่วมกัน) · ฝากเงินทำในกระเป๋าของสมาชิกเอง ระบบแค่อ่านเชน
 */
class BinaryController extends Controller
{
    public function __construct(protected BinaryPlan $plans, protected AdminRegistry $registry) {}

    /** 'on' = เปิดให้ทุกคน · 'preview' = ปิดอยู่แต่แอดมินดูได้ · null = ปิด */
    protected function access(Request $request): ?string
    {
        if ($this->plans->enabled()) {
            return 'on';
        }
        $admin = $request->session()->get('admin.address');

        return is_string($admin) && $this->registry->roleFor($admin) !== null ? 'preview' : null;
    }

    public function page(Request $request, BinaryLedger $ledger): View
    {
        $access = $this->access($request) ?? abort(404);
        $plan = $this->plans->current();
        $ref = $request->query('ref', $request->cookie(GridController::REF_COOKIE));
        $wallet = $this->plans->wallet();
        $source = $this->plans->source();

        return view('pages.binary', [
            'title' => __('binary.title'),
            'description' => __('binary.description', ['price' => $plan['price']]),
            'plan' => $plan,
            'ready' => $this->plans->ready(),
            'wallet' => $wallet !== null ? Address::checksum($wallet) : null,
            'rest' => $this->plans->rest(),
            'ends' => $this->plans->endsAt(),
            'source' => $source !== null ? BinaryPlan::allocation($source) : null,
            'totals' => $ledger->book()['totals'],
            'preview' => $access === 'preview',
            'ref' => Address::isValid($ref) && ! Address::isZero($ref) ? Address::checksum($ref) : null,
            'side' => GridPlacer::side($request->query('side', $request->cookie(GridController::SIDE_COOKIE))),
        ]);
    }

    /** ลิงก์ชวนของแผน Binary: จำผู้ชวน (และขา) ไว้ในคุกกี้ แล้วพาไปหน้า /binary */
    public function ref(Request $request, string $wallet, ?string $side = null): RedirectResponse
    {
        if ($this->access($request) === null) {
            return redirect(SiteLocale::route('home'));
        }
        $to = redirect(SiteLocale::route('binary'));
        if (Address::isValid($wallet) && ! Address::isZero($wallet)) {
            $to->withCookie(Cookie::make(GridController::REF_COOKIE, strtolower($wallet), 60 * 24 * 30, '/', null, null, true, false, 'lax'));
            $side = GridPlacer::side($side);
            $to->withCookie($side !== null
                ? Cookie::make(GridController::SIDE_COOKIE, strtolower($side), 60 * 24 * 30, '/', null, null, true, false, 'lax')
                : Cookie::forget(GridController::SIDE_COOKIE));
        }

        return $to;
    }

    public function nonce(Request $request, SiweAuth $siwe, GridBinder $binder): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->input('address');
        if (! is_string($address) || ! Address::isValid($address)) {
            return $this->fail('address');
        }
        if ($request->input('purpose') === 'bind') {
            $ref = $request->input('referrer');
            $ref = is_string($ref) ? $ref : '';
            if ($error = $binder->check($address, $ref)) {
                return $this->fail($error);
            }
            $msg = $siwe->issue($request, $address, '/binary', __('binary.sign.bind', ['ref' => Address::checksum($ref)]), [
                'purpose' => 'bind', 'referrer' => strtolower($ref), 'side' => GridPlacer::side($request->input('side')),
            ]);
        } else {
            $msg = $siwe->issue($request, $address, '/binary', __('binary.sign.login'), ['purpose' => 'login']);
        }

        return response()->json(['message' => $msg->toString()]);
    }

    public function verify(Request $request, SiweAuth $siwe, GridBinder $binder): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $text = $request->input('message');
        $signature = $request->input('signature');
        if (! is_string($text) || ! is_string($signature) || strlen($text) > 2000 || strlen($signature) > 200) {
            return $this->fail('format');
        }
        try {
            $address = $siwe->verify($request, $text, $signature, '/binary');
        } catch (SiweException $e) {
            return $this->fail($e->reason === 'address' ? 'address_mismatch' : $e->reason);
        }

        $extra = $siwe->verifiedExtra;
        $bindTo = ($extra['purpose'] ?? null) === 'bind' ? (string) ($extra['referrer'] ?? '') : null;
        // ผู้ชวนที่บันทึกต้องเป็นคนเดียวกับที่เจ้าของกระเป๋าเห็นในข้อความที่เซ็น
        if ($bindTo !== null && (! Address::isValid($bindTo) || ! str_contains($text, Address::checksum($bindTo)))) {
            return $this->fail('format');
        }

        $request->session()->regenerate();
        $request->session()->put(GridController::SESSION, $address);

        if ($bindTo !== null) {
            $result = $binder->bind($address, $bindTo, $text, $signature, $request->ip(), GridPlacer::side($extra['side'] ?? null));
            if (is_string($result)) {
                return $this->fail($result, ['signed_in' => true]);
            }

            return response()->json(['ok' => true, 'bound' => true])
                ->withCookie(Cookie::forget(GridController::REF_COOKIE))->withCookie(Cookie::forget(GridController::SIDE_COOKIE));
        }

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->forget('grid');

        return response()->json(['ok' => true]);
    }

    /**
     * วิธีวางคนใหม่ที่เราชวน (mode: weak | strong | left | right | fill | manual) — ไม่ย้ายคนที่วางไปแล้ว
     * ยังรับ side (L | R | ว่าง) แบบเดิมได้: L → left · R → right · ว่าง → weak
     */
    public function side(Request $request): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->session()->get(GridController::SESSION);
        if (! is_string($address)) {
            return response()->json(['message' => __('binary.err.login'), 'code' => 'login'], 401);
        }
        $mode = $request->has('mode')
            ? GridPlacer::mode($request->input('mode'))
            : match (GridPlacer::side($request->input('side'))) {
                GridPlacer::LEFT => GridPlacer::LEFT_EDGE,
                GridPlacer::RIGHT => GridPlacer::RIGHT_EDGE,
                default => GridPlacer::WEAK,
            };
        if ($mode === null) {
            return response()->json(['message' => __('binary.tree.err.mode'), 'code' => 'mode'], 422);
        }
        $row = app(TreeEditor::class)->setMode($address, $mode);

        return response()->json(['ok' => true, 'mode' => $mode, 'pref' => $row->bin_side_pref]);
    }

    /**
     * ผังทีมของเรา (root = เราหรือคนในทีมเรา) + คิวรอจัดสายของคนที่เราชวน
     */
    public function team(Request $request, BinaryLedger $ledger, TreeView $view): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->session()->get(GridController::SESSION);
        if (! is_string($address)) {
            return response()->json(['message' => __('binary.err.login'), 'code' => 'login'], 401);
        }
        $address = strtolower($address);
        $book = $ledger->book();
        $root = $request->query('root');
        $root = is_string($root) && Address::isValid($root) ? strtolower($root) : $address;
        $window = $view->window($root, 2, $book, $address) ?? $view->window($address, 2, $book, $address);

        return response()->json([
            'window' => $window,
            'queue' => $view->queue($book, $address),
            'mode' => GridPlacer::modeOf(GridMember::query()->where('wallet', $address)->first()),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * แม่ทีมจัดสายคนที่ตัวเองชวนซึ่งรออยู่ในคิว: เลือกช่องเอง (parent + leg) หรือให้ระบบเลือก (mode)
     * วางได้เฉพาะช่องว่างในทีมตัวเอง — ย้ายคนที่วางไปแล้วทำไม่ได้ (หลังบ้านเท่านั้น)
     */
    public function teamPlace(Request $request, TreeEditor $editor): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->session()->get(GridController::SESSION);
        if (! is_string($address)) {
            return response()->json(['message' => __('binary.err.login'), 'code' => 'login'], 401);
        }
        $wallet = (string) $request->input('wallet');
        $result = $request->filled('mode')
            ? $editor->placeAuto($wallet, (string) $request->input('mode'), $address, 'member', $address)
            : $editor->place($wallet, (string) $request->input('parent'), (string) $request->input('leg'), $address, 'member', null, $address);
        if (is_string($result)) {
            $key = 'binary.tree.err.'.$result;
            $message = __($key);

            return response()->json(['message' => $message === $key ? __('binary.tree.err.format') : $message, 'code' => $result], 422);
        }

        return response()->json(['ok' => true, 'message' => __('binary.team.place_ok')]);
    }

    public function me(Request $request, BinaryLedger $ledger, GridBinder $binder): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->session()->get(GridController::SESSION);
        if (! is_string($address)) {
            return response()->json(['message' => __('binary.err.login'), 'code' => 'login'], 401);
        }
        $address = strtolower($address);
        $book = $ledger->book();
        $plan = $this->plans->current();
        $n = $book['nodes'][$address] ?? [];
        $row = GridMember::query()->where('wallet', $address)->first();
        $week = max(0, $book['nowWeek']);
        $w = $book['weeks'][$week] ?? null;
        $mine = null;
        foreach ($w['rows'] ?? [] as $r) {
            if ($r['wallet'] === $address) {
                $mine = ['matched' => $r['matched'], 'share' => $r['share'], 'capped' => $r['capped']];
            }
        }
        $short = fn (?string $a) => $a ? Idtc::short($a) : null;
        $me = Address::checksum($address);
        $wallet = $this->plans->wallet();
        $usdt = Idtc::contract('usdt');
        $referrer = $n['referrer'] ?? null;
        $ends = $this->plans->endsAt();
        // ยอดในรอบที่ประกาศบนเชนแล้ว + proof — กดรับเองด้วยกระเป๋า (สถานะรับแล้วอ่านสดจากเชน)
        $claims = [];
        foreach (['usdt' => BinaryRounds::USDT, 'idtc' => BinaryRounds::IDTC] as $name => $stream) {
            $c = BinaryRounds::of($stream)->claimsFor($address);
            $claims[$name] = [
                'contract' => $c['contract'] ? Address::checksum($c['contract']) : null,
                'error' => $c['error'] !== null,
                'items' => array_map(fn ($i) => ['round' => $i['round'], 'amount' => $i['amount'], 'value' => $i['idtc'], 'claimed' => $i['claimed'], 'proof' => $i['proof']], $c['items']),
            ];
        }

        return response()->json([
            'address' => $me,
            'referrer' => $referrer ? Address::checksum($referrer) : null,
            'can_bind' => $binder->canBind($address),
            'held' => (bool) ($n['held'] ?? false),
            // รับฝากได้จริงไหม ณ ตอนนี้ (เปิด + ตั้งค่าครบ + ยังไม่สิ้นสุด + โควตายังเหลือ)
            'open' => $this->plans->ready() && ($ends === null || $ends->isFuture()) && $book['totals']['quota_left'] > 0,
            'wallet' => $wallet !== null ? Address::checksum($wallet) : null,
            'usdt' => $usdt !== null ? Address::checksum($usdt) : null,
            'plan' => ['price' => $plan['price'], 'min_usdt' => $plan['min_usdt'], 'directs' => $plan['directs']],
            'quota_left' => $book['totals']['quota_left'],
            'mine' => [
                'usdt' => $n['usdt'] ?? 0.0, 'idtc' => $n['idtc'] ?? 0.0, 'deposits' => $n['deposits'] ?? 0,
                'ready' => $n['ready'] ?? 0.0, 'wait' => $n['wait'] ?? 0.0, 'held' => $n['held_amt'] ?? 0.0,
                'qualified' => (bool) ($n['q'] ?? false),
                'leg' => $n['leg'] ?? null, 'parent' => $short($n['parent'] ?? null),
                'left' => ['count' => $n['nl'] ?? 0, 'usdt' => $n['l'] ?? 0.0, 'carry' => $n['cl'] ?? 0.0, 'directs' => $n['dl'] ?? 0],
                'right' => ['count' => $n['nr'] ?? 0, 'usdt' => $n['r'] ?? 0.0, 'carry' => $n['cr'] ?? 0.0, 'directs' => $n['dr'] ?? 0],
            ],
            'week' => ['pool' => $w['pool'] ?? 0.0, 'mine' => $mine, 'ends_at' => GridPlan::weekStart($week + 1)->getTimestamp()],
            'links' => [
                'auto' => SiteLocale::route('bref', ['wallet' => $me]),
                'left' => SiteLocale::route('bref', ['wallet' => $me, 'side' => 'l']),
                'right' => SiteLocale::route('bref', ['wallet' => $me, 'side' => 'r']),
            ],
            'claims' => $claims,
            'pref' => GridPlacer::side($row?->bin_side_pref),
            'mode' => GridPlacer::modeOf($row),
            'waiting' => GridMember::query()->where('referrer', $address)->whereNotNull('bin_wait_at')->whereNull('bin_parent')->count(),
            // ตัวเองอยู่ในคิวรอจัดสายของผู้ชวน (ยังไม่มีตำแหน่ง)
            'pending' => $row?->bin_wait_at !== null && $row->bin_parent === null,
            'deposits' => BinaryDeposit::query()->where('wallet', $address)->orderByDesc('block_number')->orderByDesc('log_index')->limit(20)->get()
                ->map(fn (BinaryDeposit $d) => [
                    'at' => $d->block_time?->getTimestamp(), 'usdt' => $d->usdt, 'idtc' => $d->idtc, 'tx' => $d->tx_hash,
                    'status' => $d->status !== BinaryDeposit::OK && $d->refunded_at !== null ? 'refunded' : $d->status,
                ])->all(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @param array<string, mixed> $extra */
    protected function fail(string $code, array $extra = []): JsonResponse
    {
        $key = 'grid.err.'.$code;
        $message = __($key);

        return response()->json(['message' => $message === $key ? __('grid.err.format') : $message, 'code' => $code] + $extra, 422);
    }
}
