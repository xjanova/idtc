<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GridMember;
use App\Models\GridTreeChange;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Binary\BinaryLedger;
use App\Services\Chain\Address;
use App\Services\Grid\GridPlacer;
use App\Services\Grid\TreeEditor;
use App\Services\Grid\TreeView;
use App\Support\SiteLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หลังบ้าน > Binary > ผังสายงาน: ดูผัง · จัดสาย (คิวรอจัดสาย) · ย้ายสายพร้อมทีม · ต่อสาย · ตั้งวิธีวางของสมาชิก · เปลี่ยนผู้แนะนำ (owner)
 *
 * ดู = สิทธิ์ binary · จัด/ย้าย/ต่อสาย/ตั้งวิธีวาง = binary แก้ไขได้ · เปลี่ยนผู้แนะนำ = owner (routes/admin.php)
 * ทุกการเปลี่ยนมีผลตั้งแต่สัปดาห์ขายปัจจุบัน สัปดาห์ที่ปิดแล้วคิดด้วยผังเดิม (BinaryTree)
 */
class BinaryTreeController extends Controller
{
    public function __construct(protected TreeView $view, protected TreeEditor $editor, protected BinaryLedger $ledger) {}

    public function index(Request $request): View
    {
        $book = $this->ledger->book();
        $root = $this->rootOf($request->query('root'), $book);
        $me = app(AdminContext::class);

        return view('admin.binary.tree', [
            'title' => 'ผังสายงาน Binary · จัดสาย',
            'treeJs' => [
                'root' => $root,
                'canEdit' => $me->canEdit('binary'),
                'owner' => $me->isOwner(),
                'modes' => array_combine(GridPlacer::MODES, array_map(fn ($m) => __('binary.mode.'.$m, [], 'th'), GridPlacer::MODES)),
                'routes' => [
                    'data' => route('admin.binary.tree.data'),
                    'lists' => route('admin.binary.tree.lists'),
                    'check' => route('admin.binary.tree.check'),
                    'place' => route('admin.binary.tree.place'),
                    'auto' => route('admin.binary.tree.auto'),
                    'mode' => route('admin.binary.tree.mode'),
                    'referrerCheck' => route('admin.binary.tree.referrer.check'),
                    'referrer' => route('admin.binary.tree.referrer'),
                    'member' => route('admin.grid.member', ['wallet' => '__W__']),
                ],
                // ลิงก์ชวนของแผน Binary (ไทย) — แอดมินส่ง QR ให้หัวหน้าทีมได้
                'refBase' => SiteLocale::route('bref', ['wallet' => '0x0000000000000000000000000000000000000000'], 'th'),
            ],
            'counts' => [
                'waiting' => GridMember::query()->whereNotNull('bin_wait_at')->whereNull('bin_parent')->count(),
                'changes' => GridTreeChange::query()->count(),
            ],
        ]);
    }

    /** ผังรอบกระเป๋า root (ไม่ระบุ = บนสุดของผังที่ใหญ่ที่สุด) */
    public function data(Request $request): JsonResponse
    {
        $book = $this->ledger->book();
        $root = $this->rootOf($request->query('root'), $book);
        if ($root === null) {
            return response()->json(['window' => null]);
        }
        $depth = max(1, min(4, (int) $request->query('depth', 3)));
        $wallet = strtolower($root);
        $n = $book['nodes'][$wallet] ?? null;
        $row = GridMember::query()->where('wallet', $wallet)->first();

        return response()->json([
            'window' => $this->view->window($wallet, $depth, $book),
            'focus' => [
                'wallet' => Address::checksum($wallet),
                'referrer' => $row?->referrer ? Address::checksum($row->referrer) : null,
                'mode' => GridPlacer::modeOf($row),
                'waiting' => $row?->bin_wait_at !== null && $row->bin_parent === null,
                'placed' => $row?->bin_parent !== null,
                'usdt' => (float) ($n['usdt'] ?? 0.0),
                'deposits' => (int) ($n['deposits'] ?? 0),
                'ready' => (float) ($n['ready'] ?? 0.0), 'wait' => (float) ($n['wait'] ?? 0.0),
                'since' => isset($n['since']) ? $n['since'] + 1 : null,
                'history' => $this->view->history(20, $wallet),
            ],
        ]);
    }

    /** คิวรอจัดสาย · คนนอกผัง (ต่อสายได้) · ประวัติล่าสุด */
    public function lists(): JsonResponse
    {
        $book = $this->ledger->book();

        return response()->json([
            'queue' => $this->view->queue($book),
            'outside' => $this->view->outside($book),
            'history' => $this->view->history(100),
        ]);
    }

    /** ตรวจก่อนจัด/ย้าย/ต่อสาย — ไม่เปลี่ยนอะไร */
    public function check(Request $request): JsonResponse
    {
        $r = $this->editor->check((string) $request->input('wallet'), (string) $request->input('parent'), (string) $request->input('leg'));

        return response()->json($this->explain($r));
    }

    public function place(Request $request): JsonResponse
    {
        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 300) {
            return response()->json(['message' => 'เขียนเหตุผล 4–300 ตัวอักษร (ขึ้นในประวัติการย้าย)'], 422);
        }
        $me = app(AdminContext::class);
        $result = $this->editor->place((string) $request->input('wallet'), (string) $request->input('parent'), (string) $request->input('leg'), $me->address, $me->role, $reason);
        if (is_string($result)) {
            return response()->json(['message' => $this->message($result), 'code' => $result], 422);
        }
        Audit::record($result->kind === GridTreeChange::MOVE ? 'binary.tree.move' : 'binary.tree.place', [
            'wallet' => $result->wallet, 'from' => $result->from_parent ? $result->from_leg.':'.$result->from_parent : null,
            'to' => $result->to_leg.':'.$result->to_parent, 'team' => $result->team, 'week' => $result->week + 1, 'reason' => $reason,
        ]);

        return response()->json(['ok' => true, 'message' => $result->kind === GridTreeChange::MOVE
            ? 'ย้ายสายแล้ว ('.$result->team.' คน) — มีผลตั้งแต่สัปดาห์ขายที่ '.($result->week + 1)
            : 'จัดสายแล้ว — มีผลตั้งแต่สัปดาห์ขายที่ '.($result->week + 1)]);
    }

    /** จัดสายจากคิวแบบให้ระบบเลือกช่อง (วิธีวาง weak/strong/left/right/fill ในทีมของผู้แนะนำ) */
    public function auto(Request $request): JsonResponse
    {
        $me = app(AdminContext::class);
        $result = $this->editor->placeAuto((string) $request->input('wallet'), (string) $request->input('mode'), $me->address, $me->role);
        if (is_string($result)) {
            return response()->json(['message' => $this->message($result), 'code' => $result], 422);
        }
        Audit::record('binary.tree.place', ['wallet' => $result->wallet, 'to' => $result->to_leg.':'.$result->to_parent, 'mode' => $request->input('mode'), 'week' => $result->week + 1]);

        return response()->json(['ok' => true, 'message' => 'จัดสายแล้ว — ลง'.($result->to_leg === 'L' ? 'ซ้าย' : 'ขวา').'ของ '.Address::checksum((string) $result->to_parent)]);
    }

    public function mode(Request $request): JsonResponse
    {
        $wallet = (string) $request->input('wallet');
        $mode = GridPlacer::mode($request->input('mode'));
        if (! Address::isValid($wallet) || $mode === null) {
            return response()->json(['message' => 'ข้อมูลไม่ถูกต้อง'], 422);
        }
        if (! $this->editor->known($wallet)) {
            return response()->json(['message' => $this->message('unknown_wallet')], 422);
        }
        $old = GridPlacer::modeOf(GridMember::query()->where('wallet', strtolower($wallet))->first());
        $this->editor->setMode($wallet, $mode);
        Audit::change('binary.tree.mode', $old, $mode, ['wallet' => strtolower($wallet)]);

        return response()->json(['ok' => true, 'message' => 'ตั้งวิธีวางคนใหม่เป็น "'.__('binary.mode.'.$mode, [], 'th').'" แล้ว — คนที่วางไปแล้วไม่ย้าย']);
    }

    public function referrerCheck(Request $request): JsonResponse
    {
        return response()->json($this->explain($this->editor->checkReferrer((string) $request->input('wallet'), (string) $request->input('referrer'))));
    }

    /** เปลี่ยนผู้แนะนำ — owner เท่านั้น */
    public function referrer(Request $request): JsonResponse
    {
        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 300) {
            return response()->json(['message' => 'เขียนเหตุผล 8–300 ตัวอักษร (เปลี่ยนผู้แนะนำกระทบรางวัลของการซื้อหลังจากนี้)'], 422);
        }
        $me = app(AdminContext::class);
        $result = $this->editor->changeReferrer((string) $request->input('wallet'), (string) $request->input('referrer'), $me->address, $reason);
        if (is_string($result)) {
            return response()->json(['message' => $this->message($result), 'code' => $result], 422);
        }
        Audit::record('binary.tree.referrer', ['wallet' => $result->wallet, 'old' => $result->from_referrer, 'new' => $result->to_referrer, 'reason' => $reason]);

        return response()->json(['ok' => true, 'message' => 'เปลี่ยนผู้แนะนำแล้ว — การซื้อ/ฝากก่อนหน้านี้ยังนับให้สายเดิม']);
    }

    /** root ที่จะแสดง: ที่ขอมา (ต้องเป็นที่อยู่ถูกต้อง) หรือบนสุดของผังที่ใหญ่ที่สุด */
    private function rootOf(mixed $q, array $book): ?string
    {
        if (is_string($q) && Address::isValid($q) && ! Address::isZero($q)) {
            return Address::checksum($q);
        }
        $best = null;
        foreach ($book['nodes'] as $w => $n) {
            if ($n['parent'] === null && ($n['bl'] !== null || $n['br'] !== null)) {
                $size = $n['nl'] + $n['nr'];
                if ($best === null || $size > $best[1]) {
                    $best = [$w, $size];
                }
            }
        }

        return $best ? Address::checksum($best[0]) : null;
    }

    /**
     * @param  array{error: ?string, warnings: list<string>, info: array<string, mixed>}  $r
     * @return array<string, mixed>
     */
    private function explain(array $r): array
    {
        $c = fn (?string $a) => $a ? Address::checksum($a) : null;
        $info = $r['info'];
        foreach (['wallet', 'referrer', 'from_parent', 'to_parent', 'from_referrer', 'to_referrer'] as $k) {
            if (array_key_exists($k, $info)) {
                $info[$k] = $c($info[$k]);
            }
        }
        if (isset($info['week'])) {
            $info['week'] += 1;
        }

        return [
            'ok' => $r['error'] === null,
            'error' => $r['error'] !== null ? $this->message($r['error']) : null,
            'warnings' => array_map(fn ($w) => __('binary.tree.warn.'.$w, [], 'th'), $r['warnings']),
            'info' => $info,
        ];
    }

    private function message(string $code): string
    {
        $key = 'binary.tree.err.'.$code;
        $msg = __($key, [], 'th');

        return $msg === $key ? __('binary.tree.err.format', [], 'th') : $msg;
    }
}
