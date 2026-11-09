<?php

namespace App\Services\Grid;

use App\Models\GridRound;
use App\Models\GridRoundItem;
use App\Services\Chain\RewardsReader;
use App\Services\Chain\RpcException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * รอบจ่ายรางวัล Grid: สร้างรายชื่อจากยอดครบระยะ → เจ้าของประกาศ root บนสัญญา GridRewards → สมาชิกกดรับเอง
 *
 *  - ยอดของรอบ = ยอด "ครบระยะถือ" (GridLedger: ready รวม Sprint) − ยอดที่จัดสรรไปแล้วในรอบ draft/live
 *    → สร้างกี่รอบก็ไม่จ่ายซ้ำ · ยอดที่ลดลงภายหลัง (พักรางวัล/ระดับตก) ไม่ทวงคืน แค่ไม่จ่ายเพิ่ม
 *  - ก่อนปิดการขาย แบ่งตามแผน "instant": ส่วนรับได้ทันที (รอบ instant) + ส่วนที่ปลดเมื่อปิดการขาย (รอบ deferred)
 *  - มีรอบ draft ค้างได้ทีละชุด (ประกาศหรือทิ้งก่อนสร้างใหม่) · เลขรอบบนเชน = roundCount ตอนสร้าง
 *  - สถานะตัดสินจากเชนเสมอ (reconcile): root ตรง = live · ยกเลิกบนเชน = revoked · เลขรอบนั้นเป็น root อื่น = stale
 *  - เปลี่ยนที่อยู่สัญญา: รอบ draft ของสัญญาเดิมถูกทิ้งเอง (leaf ผูกกับที่อยู่สัญญา ประกาศที่สัญญาใหม่ไม่ได้)
 *  - รอบที่ถูกยกเลิก: อ่านจากเชนว่าใครรับไปแล้ว — คนที่รับแล้ว (หรือยังอ่านไม่ได้) ยังนับว่าจัดสรรแล้ว ไม่จ่ายซ้ำ
 *  - จำนวนเก็บเป็นหน่วยเล็กสุด ปัดลงที่ 0.000001 IDTC (เศษคืนเข้างบ)
 */
final class GridRounds
{
    public const MIN_IDTC = 1.0;

    /** อ่านสถานะรับแล้วของรอบที่ถูกยกเลิก ครั้งละไม่เกินเท่านี้ต่อการ reconcile (หน้าเว็บไม่ช้า) */
    public const CLAIM_SCAN = 2000;

    public function __construct(protected GridLedger $ledger, protected GridPlan $plans, protected RewardsReader $reader) {}

    /** @return array<string, float> กระเป๋า => IDTC ที่จัดสรรแล้ว (draft + live + รอบยกเลิกที่รับไปแล้วหรือยังไม่รู้) */
    public function allocated(): array
    {
        return GridRoundItem::query()
            ->join('grid_rounds', 'grid_rounds.id', '=', 'grid_round_items.round_id')
            ->where(fn ($q) => $q->whereIn('grid_rounds.status', GridRound::ALLOCATED)
                ->orWhere(fn ($r) => $r->where('grid_rounds.status', GridRound::REVOKED)
                    ->where(fn ($c) => $c->whereNull('grid_round_items.claimed')->orWhere('grid_round_items.claimed', true))))
            ->groupBy('grid_round_items.wallet')
            ->selectRaw('grid_round_items.wallet as wallet, sum(grid_round_items.amount_idtc) as total')
            ->pluck('total', 'wallet')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * ยอดที่รอบถัดไปจะจ่าย
     *
     * @return list<array{wallet: string, ready: float, allocated: float, due: float}>
     */
    public function due(GridBook $book): array
    {
        $alloc = $this->allocated();
        $rows = [];
        foreach ($book->nodes as $w => $n) {
            $ready = (float) $n['ready'];
            $due = $ready - ($alloc[$w] ?? 0.0);
            if ($n['held'] || $due + 1e-9 < self::MIN_IDTC) {
                continue;
            }
            $rows[] = ['wallet' => $w, 'ready' => $ready, 'allocated' => $alloc[$w] ?? 0.0, 'due' => $due];
        }
        usort($rows, fn ($a, $b) => $b['due'] <=> $a['due']);

        return $rows;
    }

    public function draft(): ?GridRound
    {
        $contract = $this->reader->address();

        return $contract === null ? null : GridRound::query()->where('contract', strtolower($contract))
            ->where('status', GridRound::DRAFT)->orderBy('chain_round')->first();
    }

    /**
     * สร้างรอบจ่าย — กันกดพร้อมกัน (สองแท็บ/สองแอดมิน) ด้วย lock: ได้รายชื่อชุดเดียวเสมอ
     *
     * @return array{ok: bool, message: string, rounds: list<GridRound>}
     */
    public function build(?string $by, ?string $note, ?CarbonImmutable $now = null): array
    {
        $lock = Cache::lock('idtc:grid:rounds:build', 120);
        if (! $lock->get()) {
            return $this->fail('มีการสร้างรอบจ่ายอื่นกำลังทำอยู่ — รอสักครู่แล้วรีเฟรชหน้า');
        }
        try {
            return $this->buildLocked($by, $note, $now);
        } finally {
            $lock->release();
        }
    }

    /**
     * สร้างรอบจ่ายจากยอดครบระยะที่ยังไม่ได้จัดสรร
     *
     * @return array{ok: bool, message: string, rounds: list<GridRound>}
     */
    protected function buildLocked(?string $by, ?string $note, ?CarbonImmutable $now): array
    {
        $now ??= CarbonImmutable::now();
        $contract = $this->reader->address();
        if ($contract === null) {
            return $this->fail('ยังไม่ได้ตั้งที่อยู่สัญญาจ่ายรางวัล (GridRewards) — ตั้งที่หน้าตั้งค่าก่อน');
        }
        $this->reconcile();
        if ($this->draft() !== null) {
            return $this->fail('มีรอบที่สร้างแล้วยังไม่ได้ประกาศ — ประกาศบนเชนหรือทิ้งรอบนั้นก่อน');
        }
        $chain = $this->reader->read();
        if (! $chain['ok']) {
            return $this->fail('อ่านสัญญาจ่ายรางวัลไม่ได้: '.($chain['error'] ?? 'ไม่ทราบสาเหตุ'));
        }

        $rows = $this->due($this->ledger->book($now, true));
        if ($rows === []) {
            return $this->fail('ยังไม่มียอดครบระยะที่ยังไม่ได้จัดสรร (ขั้นต่ำ '.number_format(self::MIN_IDTC).' IDTC ต่อกระเป๋า)');
        }

        $instant = (float) $this->plans->current($now)['instant'];
        $saleEnd = CarbonImmutable::parse((string) config('idtc.sale.end'))->setTimezone(config('app.timezone')); // คอลัมน์เวลาเก็บตามเขตเวลาแอป
        $split = $instant > 0 && $instant < 1 && $now->lt($saleEnd);
        $ppm = (int) round($instant * 1_000_000);

        $parts = $split ? ['instant' => [], 'deferred' => []] : ['full' => []];
        foreach ($rows as $r) {
            $micro = (int) floor($r['due'] * 1_000_000 + 1e-6);
            if ($split) {
                // micro × ppm ล้น int64 ได้เมื่อยอดใหญ่ → แยกส่วนเต็มกับเศษ (ผลเท่ากันพอดี)
                $now1 = intdiv($micro, 1_000_000) * $ppm + intdiv(($micro % 1_000_000) * $ppm, 1_000_000);
                $parts['instant'][$r['wallet']] = $now1;
                $parts['deferred'][$r['wallet']] = $micro - $now1;
            } else {
                $parts['full'][$r['wallet']] = $micro;
            }
        }

        $next = (int) $chain['count'];
        $made = [];
        DB::transaction(function () use ($parts, $contract, $by, $note, $saleEnd, &$next, &$made) {
            foreach ($parts as $kind => $amounts) {
                $amounts = array_filter($amounts, fn ($m) => $m > 0);
                if ($amounts === []) {
                    continue;
                }
                ksort($amounts, SORT_STRING); // ลำดับคงที่ → root เดิมเสมอสำหรับรายชื่อเดิม
                $wallets = array_keys($amounts);
                $leaves = [];
                foreach ($wallets as $w) {
                    $leaves[] = GridMerkle::leaf($contract, $next, $w, self::wei($amounts[$w]));
                }
                $tree = new GridMerkle($leaves);
                $totalMicro = array_sum($amounts);
                $round = GridRound::query()->create([
                    'contract' => strtolower($contract), 'chain_round' => $next, 'kind' => $kind, 'status' => GridRound::DRAFT,
                    'root' => $tree->root(), 'total' => self::wei($totalMicro), 'total_idtc' => $totalMicro / 1_000_000,
                    'items' => count($wallets), 'unlock_at' => $kind === 'deferred' ? $saleEnd : null,
                    'note' => $note, 'created_by' => $by,
                ]);
                $rowsOut = [];
                foreach ($wallets as $i => $w) {
                    $rowsOut[] = [
                        'round_id' => $round->id, 'wallet' => $w, 'amount' => self::wei($amounts[$w]),
                        'amount_idtc' => $amounts[$w] / 1_000_000, 'proof' => json_encode($tree->proof($i)),
                    ];
                }
                foreach (array_chunk($rowsOut, 500) as $chunk) {
                    GridRoundItem::query()->insert($chunk);
                }
                $made[] = $round;
                $next++;
            }
        });

        $sum = array_sum(array_map(fn ($r) => $r->total_idtc, $made));

        return ['ok' => true, 'rounds' => $made, 'message' => 'สร้างรอบจ่ายแล้ว '.count($made).' รอบ · '.number_format(count($rows)).' กระเป๋า · รวม '
            .number_format($sum, 2).' IDTC — โอน IDTC เข้าสัญญาให้พอ แล้วกดประกาศทีละรอบตามลำดับ'];
    }

    /**
     * ตัดสินสถานะจากเชน — คืนจำนวนรอบที่สถานะเปลี่ยน
     */
    public function reconcile(): int
    {
        $contract = $this->reader->address();
        if ($contract === null) {
            return 0;
        }
        // draft ของสัญญาอื่น (เปลี่ยนที่อยู่สัญญาแล้ว) ประกาศไม่ได้อีก — ทิ้ง ยอดกลับไปรอรอบถัดไป
        $changed = GridRound::query()->where('status', GridRound::DRAFT)->where('contract', '!=', strtolower($contract))
            ->update(['status' => GridRound::DISCARDED, 'updated_at' => now()]);
        $this->reader->forget();
        $chain = $this->reader->read();
        if (! $chain['ok']) {
            return $changed;
        }
        $open = GridRound::query()->where('contract', strtolower($contract))->whereIn('status', [GridRound::DRAFT, GridRound::LIVE])->get();
        foreach ($open as $round) {
            $on = $chain['rounds'][$round->chain_round] ?? null;
            if ($on === null) {
                continue; // ยังไม่ได้ประกาศ
            }
            $status = $on['root'] !== strtolower($round->root)
                ? ($round->status === GridRound::DRAFT ? GridRound::STALE : $round->status)
                : ($on['revoked'] ? GridRound::REVOKED : GridRound::LIVE);
            if ($status !== $round->status) {
                $round->status = $status;
                if ($status === GridRound::LIVE) {
                    $round->live_at ??= now();
                }
                $round->save();
                $changed++;
            }
        }
        $this->scanRevoked($contract);

        return $changed;
    }

    /** รอบที่ถูกยกเลิก: บันทึกว่าใครรับไปแล้ว (หลังยกเลิกรับไม่ได้อีก ผลจึงคงที่) · อ่านไม่ได้ = คงเป็น null ไว้ลองใหม่ */
    protected function scanRevoked(string $contract): void
    {
        $left = self::CLAIM_SCAN;
        $rounds = GridRound::query()->where('contract', strtolower($contract))->where('status', GridRound::REVOKED)
            ->whereHas('entries', fn ($q) => $q->whereNull('claimed'))->orderBy('chain_round')->get();
        foreach ($rounds as $round) {
            while ($left > 0) {
                $items = $round->entries()->whereNull('claimed')->orderBy('id')->limit(min(500, $left))->get();
                if ($items->isEmpty()) {
                    break;
                }
                try {
                    $read = $this->reader->claimedBy($round->chain_round, $items->pluck('wallet')->all());
                } catch (RpcException) {
                    return;
                }
                $known = 0;
                foreach ($items as $i) {
                    $c = $read[$i->wallet] ?? null;
                    if ($c !== null) {
                        $i->claimed = (bool) $c;
                        $i->save();
                        $known++;
                    }
                }
                $left -= $items->count();
                if ($known === 0) {
                    return; // อ่านไม่ได้ทั้งชุด — ลองใหม่รอบหน้า
                }
            }
        }
    }

    public function discard(GridRound $round): bool
    {
        if ($round->status !== GridRound::DRAFT) {
            return false;
        }
        // ทิ้งทั้งชุดที่สร้างพร้อมกัน (รอบหลังอิงเลขรอบของรอบก่อน)
        GridRound::query()->where('contract', $round->contract)->where('status', GridRound::DRAFT)->where('chain_round', '>=', $round->chain_round)
            ->update(['status' => GridRound::DISCARDED, 'updated_at' => now()]);

        return true;
    }

    /**
     * รางวัลในรอบที่ประกาศแล้วของกระเป๋านี้ พร้อม proof และสถานะรับแล้ว (อ่านจากเชนสด)
     *
     * @return array{contract: string|null, items: list<array<string, mixed>>, error: string|null}
     */
    public function claimsFor(string $wallet): array
    {
        $contract = $this->reader->address();
        $wallet = strtolower($wallet);
        if ($contract === null) {
            return ['contract' => null, 'items' => [], 'error' => null];
        }
        $items = GridRoundItem::query()->with('round')->where('wallet', $wallet)
            ->whereHas('round', fn ($q) => $q->where('status', GridRound::LIVE)->where('contract', strtolower($contract)))
            ->get();
        $error = null;
        try {
            $claimed = $this->reader->claimed($wallet, $items->map(fn ($i) => $i->round->chain_round)->all());
        } catch (RpcException $e) {
            $claimed = [];
            $error = $e->getMessage();
        }
        $out = [];
        foreach ($items->sortBy(fn ($i) => $i->round->chain_round) as $i) {
            $out[] = [
                'round' => $i->round->chain_round, 'kind' => $i->round->kind, 'amount' => $i->amount, 'idtc' => $i->amount_idtc,
                'unlock_at' => $i->round->unlock_at?->getTimestamp(), 'claimed' => $claimed[$i->round->chain_round] ?? null, 'proof' => $i->proof,
            ];
        }

        return ['contract' => $contract, 'items' => $out, 'error' => $error];
    }

    /** หน่วย 0.000001 IDTC → หน่วยเล็กสุด (18 ทศนิยม) */
    public static function wei(int $micro): string
    {
        return $micro <= 0 ? '0' : $micro.str_repeat('0', (int) config('idtc.token.decimals', 18) - 6);
    }

    /** @return array{ok: false, message: string, rounds: list<GridRound>} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rounds' => []];
    }
}
