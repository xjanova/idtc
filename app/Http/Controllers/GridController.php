<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminRegistry;
use App\Services\Auth\SiweAuth;
use App\Services\Auth\SiweException;
use App\Services\Chain\Address;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlan;
use App\Support\Idtc;
use App\Support\SiteLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * หน้าสาธารณะ IDTC Grid (/grid) + ลิงก์ชวน (/r/{wallet}) + API ของสมาชิก
 *
 * สมาชิกเข้าระบบด้วยการเซ็นข้อความ (EIP-4361, URI /grid) — ใช้ session แยกจากหลังบ้าน (grid.address)
 * ระบบปิดอยู่ (หลังบ้าน grid.enabled) = ทุกเส้นทางตอบ 404 ยกเว้นผู้ที่เข้าหลังบ้านไว้ (ดูตัวอย่างได้)
 */
class GridController extends Controller
{
    public const REF_COOKIE = 'idtc_ref';

    public const SESSION = 'grid.address';

    public function __construct(protected GridPlan $plans, protected AdminRegistry $registry) {}

    /** 'on' = เปิดให้ทุกคน · 'preview' = ปิดอยู่แต่แอดมินดูได้ · null = ปิด */
    protected function access(Request $request): ?string
    {
        if ($this->plans->enabled()) {
            return 'on';
        }
        $admin = $request->session()->get('admin.address');

        return is_string($admin) && $this->registry->roleFor($admin) !== null ? 'preview' : null;
    }

    public function page(Request $request): View
    {
        $access = $this->access($request) ?? abort(404);
        $plan = $this->plans->current();
        $ref = $request->query('ref', $request->cookie(self::REF_COOKIE));

        return view('pages.grid', [
            'title' => __('grid.title'),
            'description' => __('grid.description', ['budget' => number_format((float) $plan['budget'])]),
            'plan' => $plan,
            'preview' => $access === 'preview',
            'ref' => Address::isValid($ref) && ! Address::isZero($ref) ? Address::checksum($ref) : null,
        ]);
    }

    public function ref(Request $request, string $wallet): RedirectResponse
    {
        if ($this->access($request) === null) {
            return redirect(SiteLocale::route('home'));
        }
        $to = redirect(SiteLocale::route('grid'));
        if (Address::isValid($wallet) && ! Address::isZero($wallet)) {
            $to->withCookie(Cookie::make(self::REF_COOKIE, strtolower($wallet), 60 * 24 * 30, '/', null, null, true, false, 'lax'));
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
            $msg = $siwe->issue($request, $address, '/grid', __('grid.sign.bind', ['ref' => Address::checksum($ref)]), [
                'purpose' => 'bind', 'referrer' => strtolower($ref),
            ]);
        } else {
            $msg = $siwe->issue($request, $address, '/grid', __('grid.sign.login'), ['purpose' => 'login']);
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
            $address = $siwe->verify($request, $text, $signature, '/grid');
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
        $request->session()->put(self::SESSION, $address);

        if ($bindTo !== null) {
            $result = $binder->bind($address, $bindTo, $text, $signature, $request->ip());
            if (is_string($result)) {
                // ลายเซ็นถูกต้อง (เข้าระบบแล้ว) แต่ผูกไม่ได้ เช่น เพิ่งซื้อไประหว่างรอเซ็น
                return $this->fail($result, ['signed_in' => true]);
            }

            return response()->json(['ok' => true, 'bound' => true])->withCookie(Cookie::forget(self::REF_COOKIE));
        }

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->forget('grid');

        return response()->json(['ok' => true]);
    }

    public function me(Request $request, GridLedger $ledger, GridBinder $binder): JsonResponse
    {
        $this->access($request) ?? abort(404);
        $address = $request->session()->get(self::SESSION);
        if (! is_string($address)) {
            return response()->json(['message' => __('grid.err.login'), 'code' => 'login'], 401);
        }
        $book = $ledger->book();
        $plan = $this->plans->current();
        $n = $book->node($address);
        $short = fn (?string $w) => $w ? Idtc::short($w) : null;
        $rankKey = GridLedger::RANKS[$n['rank'] ?? 0];

        $rings = [];
        foreach ($book->rings($address, 3, 100) as $d => $ring) {
            $rings[$d] = [
                'count' => $ring['count'],
                'items' => array_map(fn ($c) => [
                    'wallet' => $short($c['wallet']), 'rank' => GridLedger::RANKS[$c['rank']],
                    'own' => round($c['own_usdt'], 2), 'team' => $c['team_count'],
                ], $ring['items']),
            ];
        }

        $recent = array_map(fn ($r) => [
            'at' => $r['at'], 'kind' => $r['kind'], 'ring' => $r['ring'], 'from' => $short($r['buyer']),
            'amount' => $r['amount'], 'status' => $r['status'],
        ], $book->rewardsTo($address, 30));

        $week = max(0, $book->nowWeek);
        $sp = $book->sprint[$week] ?? null;
        $mine = null;
        foreach ($sp['rows'] ?? [] as $row) {
            if ($row['wallet'] === strtolower($address)) {
                $mine = ['points' => $row['points'], 'share' => $row['share'], 'capped' => $row['capped']];
            }
        }

        $req = $plan['rank'];
        $rank = $n['rank'] ?? 0;
        $own = $n['own_usdt'] ?? 0.0;
        $directs = $n['directs'] ?? 0;
        $team = $n['team_usdt'] ?? 0.0;
        $next = match ($rank) {
            0 => ['key' => 'spark', 'needs' => [['k' => 'own', 'have' => $own, 'need' => $req['spark_own']]]],
            1 => ['key' => 'cell', 'needs' => [['k' => 'directs', 'have' => $directs, 'need' => $req['cell_d']]]],
            2 => ['key' => 'grid', 'needs' => [['k' => 'directs', 'have' => $directs, 'need' => $req['grid_d']], ['k' => 'team', 'have' => $team, 'need' => $req['grid_t']]]],
            3 => ['key' => 'core', 'needs' => [['k' => 'directs', 'have' => $directs, 'need' => $req['core_d']], ['k' => 'team', 'have' => $team, 'need' => $req['core_t']]]],
            default => null,
        };

        $referrer = $n['referrer'] ?? null;

        return response()->json([
            'address' => Address::checksum($address),
            'referrer' => $referrer ? Address::checksum($referrer) : null,
            'can_bind' => $binder->canBind($address),
            'link' => SiteLocale::route('ref', ['wallet' => Address::checksum($address)]),
            'held' => (bool) ($n['held'] ?? false),
            'rank' => $rankKey,
            'own' => $own, 'directs' => $directs, 'team' => $team, 'team_count' => $n['team_count'] ?? 0,
            'next' => $next,
            'rewards' => ['ready' => $n['ready'] ?? 0.0, 'wait' => $n['wait'] ?? 0.0, 'held' => $n['held_amt'] ?? 0.0, 'sprint' => $n['sprint'] ?? 0.0],
            'rings' => $rings,
            'recent' => $recent,
            'sprint' => [
                'week' => $book->nowWeek,
                'pool' => $sp['pool'] ?? 0.0,
                'cap' => $sp['cap'] ?? 0.0,
                'ends_at' => GridPlan::weekStart($week + 1)->getTimestamp(),
                'eligible' => $rank >= 3,
                'mine' => $mine,
            ],
            'hold_days' => $plan['hold_days'],
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
