<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BinaryDeposit;
use App\Models\BinaryPlanVersion;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Binary\BinaryLedger;
use App\Services\Binary\BinaryPlan;
use App\Services\Binary\DepositIndexer;
use App\Services\Chain\Address;
use App\Services\Chain\RpcClient;
use App\Services\Chain\RpcException;
use App\Services\Grid\GridPlan;
use App\Support\Idtc;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * หลังบ้านของแผน Binary โปรโมชั่น (idtc.online/binary)
 *
 *  - ตั้งค่ารับเงิน (กระเป๋า · โควตา · ก้อนที่มา · ปลายทาง USDT · วันสิ้นสุด) และเปิด/ปิด = owner เท่านั้น
 *    (เปลี่ยนกระเป๋ารับเงินได้ = เปลี่ยนที่ที่เงินของสมาชิกไป)
 *  - อัตราของแผน (ราคา · ขั้นต่ำ · กองรางวัล · จับคู่ · เพดาน · เพื่อนตรงต่อขา) = สิทธิ์ edit ของส่วน binary · มีผลสัปดาห์ถัดไป
 *  - รายการที่ไม่นับ (โอนตอนปิด/ต่ำกว่าขั้นต่ำ/เกินโควตา) ต้องคืนเงินเองจากกระเป๋า แล้วบันทึก tx ที่นี่ — ระบบไม่มีคีย์ คืนเองไม่ได้
 */
class BinaryController extends Controller
{
    /** ก้อนที่ใช้เป็นแหล่ง IDTC ของแผนได้ (ก้อนอื่นผูกกับสัญญาล็อก/presale/พูลแล้ว) */
    public const SOURCES = ['operations', 'reserve'];

    public function __construct(protected BinaryPlan $plans, protected BinaryLedger $ledger, protected Settings $settings) {}

    public function index(DepositIndexer $indexer): View
    {
        $book = $this->ledger->compute();

        return view('admin.binary.index', [
            'title' => 'แผน Binary (โปรโมชั่น)',
            'book' => $book,
            'enabled' => $this->plans->enabled(),
            'ready' => $this->plans->ready(),
            'missing' => $this->plans->missing(),
            'wallet' => $this->plans->wallet(),
            'quota' => $this->plans->quota(),
            'source' => $this->plans->source(),
            'rest' => $this->plans->rest(),
            'ends' => $this->plans->endsAt(),
            'sources' => array_filter(array_map(fn ($k) => BinaryPlan::allocation($k), self::SOURCES)),
            'plan' => $this->plans->pending()['plan'] ?? $this->plans->current(),
            'current' => $this->plans->current(),
            'pending' => $this->plans->pending(),
            'versions' => array_reverse($this->plans->versions()),
            'nowWeek' => GridPlan::weekAt(now()),
            'state' => $indexer->state(),
            'usdt' => Idtc::contract('usdt'),
            'refunds' => BinaryDeposit::query()->where('status', '!=', BinaryDeposit::OK)->orderByRaw('refunded_at is not null')->orderByDesc('block_number')->limit(100)->get(),
            'recent' => BinaryDeposit::query()->where('status', BinaryDeposit::OK)->orderByDesc('block_number')->orderByDesc('log_index')->limit(50)->get(),
        ]);
    }

    /** ค่าที่มีผลทันที — owner เท่านั้น */
    public function settings(Request $request, RpcClient $rpc): RedirectResponse
    {
        $wallet = trim((string) $request->input('wallet', ''));
        $quota = trim((string) $request->input('quota', ''));
        $source = (string) $request->input('source', '');
        $rest = (string) $request->input('rest', '');
        $ends = trim((string) $request->input('ends', ''));
        $sold = (float) BinaryDeposit::query()->where('status', BinaryDeposit::OK)->sum('idtc');

        $errors = [];
        $reserved = array_map('strtolower', array_filter(array_map(fn ($k) => Idtc::contract($k), ['token', 'usdt', 'presale', 'vesting', 'feeder', 'rewards', 'pair_usdt', 'pair_tpix'])));
        if (! Address::isValid($wallet) || Address::isZero($wallet)) {
            $errors['wallet'] = 'ที่อยู่กระเป๋าต้องขึ้นต้นด้วย 0x ตามด้วยตัวอักษรฐานสิบหก 40 ตัว';
        } elseif (! Address::hasValidChecksum($wallet)) {
            $errors['wallet'] = 'ตัวพิมพ์เล็ก/ใหญ่ของที่อยู่ไม่ตรง checksum (อาจพิมพ์ผิด) — คัดลอกจากกระเป๋าโดยตรง';
        } elseif (in_array(strtolower($wallet), $reserved, true)) {
            $errors['wallet'] = 'ที่อยู่นี้เป็นสัญญาของโครงการ ใช้เป็นกระเป๋ารับ USDT ไม่ได้';
        }
        $alloc = in_array($source, self::SOURCES, true) ? BinaryPlan::allocation($source) : null;
        if ($alloc === null) {
            $errors['source'] = 'เลือกก้อนที่ IDTC ของแผนนี้มาจาก';
        }
        if (! preg_match('/^\d{1,15}$/', $quota) || (float) $quota <= 0) {
            $errors['quota'] = 'โควตาต้องเป็นจำนวนเต็ม IDTC มากกว่า 0';
        } elseif ((float) $quota + 1e-6 < $sold) {
            $errors['quota'] = 'โควตาต้องไม่น้อยกว่าที่ขายไปแล้ว ('.number_format($sold).' IDTC)';
        } elseif ($alloc !== null) {
            // ก้อนดำเนินงานถูกกันให้งบรางวัล Grid ไปแล้วส่วนหนึ่ง
            $taken = $source === 'operations' ? (float) app(GridPlan::class)->current()['budget'] : 0.0;
            if ((float) $quota > (float) $alloc['amount'] - $taken + 1e-6) {
                $errors['quota'] = 'โควตาเกินก้อน "'.$alloc['name'].'" ที่เหลือ ('.number_format((float) $alloc['amount'] - $taken).' IDTC'.($taken > 0 ? ' หลังหักงบรางวัล Grid' : '').')';
            }
        }
        if (! isset(BinaryPlan::REST[$rest])) {
            $errors['rest'] = 'เลือกว่า USDT ส่วนที่เหลือไปที่ไหน';
        }
        $endsAt = null;
        if ($ends !== '') {
            try {
                $endsAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) ? CarbonImmutable::parse($ends.' 23:59:59', 'Asia/Bangkok') : null;
            } catch (Throwable) {
                $endsAt = null;
            }
            if ($endsAt === null) {
                $errors['ends'] = 'วันสิ้นสุดต้องเป็นวันที่ (ปี-เดือน-วัน) หรือเว้นว่าง';
            }
        }
        if ($errors) {
            return back()->withInput()->withErrors($errors)->with('error', 'ยังบันทึกไม่ได้: '.reset($errors));
        }

        $by = app(AdminContext::class)->address;
        $old = ['wallet' => $this->plans->wallet(), 'quota' => $this->plans->quota(), 'source' => $this->plans->source(), 'rest' => $this->plans->rest(), 'ends' => $this->plans->endsAt()?->toIso8601String()];
        $new = ['wallet' => strtolower($wallet), 'quota' => (float) $quota, 'source' => $source, 'rest' => $rest, 'ends' => $endsAt?->toIso8601String()];
        if ($old == $new) {
            return back()->with('status', 'ค่าไม่ได้เปลี่ยน');
        }
        if ($old['wallet'] !== $new['wallet']) {
            // อ่านยอดฝากของกระเป๋าใหม่ตั้งแต่บล็อกนี้ — อ่านเชนไม่ได้ = ยังไม่เปลี่ยนกระเป๋า (ไม่เดาบล็อกเริ่ม)
            try {
                $head = $rpc->blockNumber();
            } catch (RpcException $e) {
                return back()->withInput()->with('error', 'อ่านบล็อกล่าสุดจากเชนไม่ได้ จึงยังไม่เปลี่ยนกระเป๋า: '.$e->getMessage());
            }
            $this->settings->put('binary.start_block', $head, $by);
            $this->settings->put('binary.wallet', $new['wallet'], $by);
        }
        $this->settings->put('binary.quota', $quota, $by);
        $this->settings->put('binary.source', $source, $by);
        $this->settings->put('binary.rest', $rest, $by);
        $this->settings->put('binary.ends', $new['ends'], $by);
        Audit::record('binary.settings', ['old' => $old, 'new' => $new]);

        return redirect()->route('admin.binary')->with('status', $old['wallet'] !== $new['wallet'] && $old['wallet'] !== null
            ? 'บันทึกแล้ว — เปลี่ยนกระเป๋ารับ USDT: ระบบนับเฉพาะการโอนเข้ากระเป๋าใหม่ตั้งแต่บล็อกนี้ แจ้งสมาชิกและตรวจหน้า /binary ว่าขึ้นที่อยู่ใหม่'
            : 'บันทึกค่าของแผนแล้ว');
    }

    /** อัตราตามสัปดาห์ — มีผลสัปดาห์ถัดไป (ยังไม่มียอดฝาก = มีผลทันทีตั้งแต่สัปดาห์แรก) */
    public function plan(Request $request): RedirectResponse
    {
        $pct = fn ($v) => is_numeric($v) ? (float) $v / 100 : -1.0;
        $price = trim((string) $request->input('price', ''));
        $plan = BinaryPlan::normalize([
            'price' => preg_match('/^\d{1,6}(\.\d{1,12})?$/', $price) ? $price : '0',
            'min_usdt' => is_numeric($request->input('min_usdt')) ? (float) $request->input('min_usdt') : -1,
            'pool' => $pct($request->input('pool')),
            'pair' => $pct($request->input('pair')),
            'cap' => $pct($request->input('cap')),
            'directs' => is_numeric($request->input('directs')) ? (int) $request->input('directs') : -1,
        ]);
        $note = trim((string) $request->input('note', ''));
        $errors = BinaryPlan::errors($plan);
        if (mb_strlen($note) < 8 || mb_strlen($note) > 500) {
            $errors['note'] = 'เขียนเหตุผลของการแก้ 8–500 ตัวอักษร (ขึ้นในบันทึกการทำงาน)';
        }
        if ($errors) {
            return back()->withInput()->withErrors($errors)->with('error', 'อัตรายังบันทึกไม่ได้: '.reset($errors));
        }

        $before = $this->plans->pending()['plan'] ?? $this->plans->current();
        if ($before == $plan) {
            return back()->with('status', 'อัตราไม่ได้เปลี่ยน');
        }
        $week = GridPlan::weekAt(now());
        $taken = BinaryDeposit::query()->exists();
        $from = ! $taken ? 0 : ($week < 0 ? 0 : $week + 1);
        $by = app(AdminContext::class)->address;
        $row = BinaryPlanVersion::query()->where('from_week', '>=', $from)->orderByDesc('id')->first() ?? new BinaryPlanVersion;
        $row->fill(['from_week' => $from, 'plan' => $plan, 'note' => $note, 'created_by' => $by])->save();
        BinaryPlanVersion::query()->where('from_week', '>=', $from)->whereKeyNot($row->id)->delete();
        $this->plans->forget();
        Audit::record('binary.plan', ['from_week' => $from + 1, 'note' => $note, 'old' => $before, 'new' => $plan]);

        return redirect()->route('admin.binary')->with('status', $taken
            ? 'บันทึกอัตราแล้ว — มีผลตั้งแต่สัปดาห์ที่ '.($from + 1).' (ยอดฝากของสัปดาห์นี้ใช้อัตราเดิม)'
            : 'บันทึกอัตราแล้ว — ยังไม่มียอดฝาก จึงมีผลทันที');
    }

    /** เปิด/ปิดรับฝาก — owner เท่านั้น · เปิดได้เมื่อตั้งค่าครบ */
    public function toggle(Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        $old = $this->plans->enabled();
        if ($old === $on) {
            return back()->with('status', $on ? 'แผนเปิดอยู่แล้ว' : 'แผนปิดอยู่แล้ว');
        }
        if ($on && ($missing = $this->plans->missing())) {
            return back()->with('error', 'ยังเปิดไม่ได้: '.implode(' · ', $missing));
        }
        $this->settings->put('binary.enabled', $on, app(AdminContext::class)->address);
        Audit::change('binary.enabled', $old, $on);

        return back()->with('status', $on
            ? 'เปิดแผน Binary แล้ว — USDT ที่โอนเข้ากระเป๋า Binary ตั้งแต่ตอนนี้นับเป็นยอดซื้อ'
            : 'ปิดแผน Binary แล้ว — USDT ที่โอนเข้ามาหลังจากนี้ไม่นับ และจะขึ้นในรายการต้องคืนเงิน · ยอดเดิมและรางวัลยังอยู่ครบ');
    }

    public function sync(DepositIndexer $indexer): RedirectResponse
    {
        $r = $indexer->run();

        return back()->with($r['ok'] ? 'status' : 'error', $r['message'].($r['inserted'] ? ' · รายการใหม่ '.$r['inserted'] : ''));
    }

    /** บันทึกว่าคืนเงินรายการที่ไม่นับแล้ว (แอดมินโอนคืนเองจากกระเป๋า Binary แล้วใส่ tx hash) */
    public function refund(Request $request, BinaryDeposit $deposit): RedirectResponse
    {
        if ($deposit->status === BinaryDeposit::OK) {
            return back()->with('error', 'รายการนี้นับเป็นยอดซื้อแล้ว ไม่ใช่รายการคืนเงิน');
        }
        if ($deposit->refunded_at !== null) {
            return back()->with('status', 'รายการนี้บันทึกว่าคืนแล้ว');
        }
        $tx = strtolower(trim((string) $request->input('tx_hash', '')));
        if (! preg_match('/^0x[0-9a-f]{64}$/', $tx) || $tx === $deposit->tx_hash) {
            return back()->with('error', 'ใส่ tx hash ของการโอนคืน (0x ตามด้วย 64 ตัวอักษร) — ไม่ใช่ tx ของการฝาก');
        }
        if (BinaryDeposit::query()->where('refund_tx', $tx)->exists()) {
            return back()->with('error', 'tx นี้ถูกใช้บันทึกการคืนเงินของรายการอื่นแล้ว');
        }
        $deposit->fill(['refunded_at' => now(), 'refund_tx' => $tx, 'refunded_by' => app(AdminContext::class)->address])->save();
        Audit::record('binary.refund', ['deposit' => $deposit->tx_hash, 'wallet' => $deposit->wallet, 'usdt' => $deposit->usdt, 'reason' => $deposit->status], null, null, null);

        return back()->with('status', 'บันทึกการคืนเงินแล้ว');
    }
}
