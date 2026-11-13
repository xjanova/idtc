<?php

namespace App\Services\Payout;

use App\Models\GridRound;
use App\Models\GridRoundItem;
use App\Services\Chain\RewardsReader;
use App\Services\Chain\RpcException;
use App\Services\Grid\GridMerkle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * รอบจ่ายผ่านสัญญา GridRewards (Merkle): สร้างรายชื่อจากยอดที่ถึงกำหนด → เจ้าของประกาศ root บนสัญญา → สมาชิกกดรับเอง
 *
 * ใช้ร่วมกันหลาย "สาย" (stream) — แต่ละสายมีสัญญาของตัวเอง เหรียญของตัวเอง และที่มาของยอดของตัวเอง:
 *   grid      รางวัล IDTC Grid (IDTC)                         → App\Services\Grid\GridRounds
 *   bin_usdt  รางวัลจับคู่ของแผน Binary โปรโมชั่น (USDT)      → App\Services\Binary\BinaryRounds
 *   bin_idtc  IDTC ที่ซื้อในแผน Binary โปรโมชั่น (IDTC)       → App\Services\Binary\BinaryRounds
 * ทุกสายเก็บในตาราง grid_rounds / grid_round_items แยกกันด้วยคอลัมน์ stream — ทุก query ในคลาสนี้กรอง stream เสมอ
 *
 * กติกาที่เหมือนกันทุกสาย:
 *  - ยอดของรอบ = ยอดถึงกำหนด − ยอดที่จัดสรรไปแล้วในรอบ draft/live → สร้างกี่รอบก็ไม่จ่ายซ้ำ
 *    ยอดที่ลดลงภายหลังไม่ทวงคืน แค่ไม่จ่ายเพิ่ม
 *  - มีรอบ draft ค้างได้ทีละชุด (ประกาศหรือทิ้งก่อนสร้างใหม่) · เลขรอบบนเชน = roundCount ตอนสร้าง
 *  - สถานะตัดสินจากเชนเสมอ (reconcile): root ตรง = live · ยกเลิกบนเชน = revoked · เลขรอบนั้นเป็น root อื่น = stale
 *  - เปลี่ยนที่อยู่สัญญา: รอบ draft ของสัญญาเดิมถูกทิ้งเอง (leaf ผูกกับที่อยู่สัญญา ประกาศที่สัญญาใหม่ไม่ได้)
 *  - รอบที่ถูกยกเลิก: อ่านจากเชนว่าใครรับไปแล้ว — คนที่รับแล้ว (หรือยังอ่านไม่ได้) ยังนับว่าจัดสรรแล้ว ไม่จ่ายซ้ำ
 *  - จำนวนคิดเป็นหน่วย 0.000001 ของเหรียญ ปัดลง (เศษไม่จ่าย) แล้วแปลงเป็นหน่วยเล็กสุดของเหรียญนั้น (raw())
 */
abstract class PayoutRounds
{
    /** อ่านสถานะรับแล้วของรอบที่ถูกยกเลิก ครั้งละไม่เกินเท่านี้ต่อการ reconcile (หน้าเว็บไม่ช้า) */
    public const CLAIM_SCAN = 2000;

    protected RewardsReader $reader;

    /** ชื่อสาย — ค่าในคอลัมน์ grid_rounds.stream */
    abstract public function stream(): string;

    /**
     * ยอดถึงกำหนดของแต่ละกระเป๋า ณ ตอนนี้ (หน่วยเต็มของเหรียญ) — ไม่รวมกระเป๋าที่ถูกพัก
     *
     * @return array<string, float> กระเป๋า => ยอดถึงกำหนดสะสม
     */
    abstract protected function readyNow(CarbonImmutable $now): array;

    /** สัญลักษณ์เหรียญที่สายนี้จ่าย (ใช้ในข้อความหลังบ้าน) */
    abstract public function unit(): string;

    /** ยอดขั้นต่ำต่อกระเป๋าที่จะเข้ารอบ (หน่วยเต็ม) */
    abstract public function minDue(): float;

    /** หน่วย 0.000001 ของเหรียญ → หน่วยเล็กสุดของเหรียญ (ข้อความ) */
    abstract public function raw(int $micro): string;

    /**
     * ยอดถึงกำหนดแบบจำนวนเต็มแม่นยำ (หน่วย 0.000001 ของเหรียญ) — สายที่มีตัวเลขแม่นยำอยู่แล้วให้คืนค่านี้
     * แล้วรอบจ่ายจะคิดด้วยจำนวนเต็มทั้งเส้น (ยอดถึงกำหนด − ยอดจัดสรร) ไม่ผ่านทศนิยมลอยตัว · null = ใช้ readyNow()
     *
     * @return array<string, int>|null
     */
    protected function readyMicroNow(CarbonImmutable $now): ?array
    {
        return null;
    }

    /** หน่วยเล็กสุดของเหรียญ → หน่วย 0.000001 (กลับด้านของ raw() — raw() เติมศูนย์ต่อท้ายเท่านั้น) */
    protected function microOf(string $raw): int
    {
        $pad = strlen($this->raw(1)) - 1;

        return (int) ($pad > 0 ? (substr($raw, 0, -$pad) ?: '0') : $raw);
    }

    /** @return array<string, int> กระเป๋า => ยอดที่จัดสรรแล้วของสายนี้ หน่วย 0.000001 (จากจำนวนหน่วยเล็กสุดที่บันทึกในรอบ) */
    protected function allocatedMicro(): array
    {
        $out = [];
        $rows = GridRoundItem::query()
            ->join('grid_rounds', 'grid_rounds.id', '=', 'grid_round_items.round_id')
            ->where('grid_rounds.stream', $this->stream())
            ->where(fn ($q) => $q->whereIn('grid_rounds.status', GridRound::ALLOCATED)
                ->orWhere(fn ($r) => $r->where('grid_rounds.status', GridRound::REVOKED)
                    ->where(fn ($c) => $c->whereNull('grid_round_items.claimed')->orWhere('grid_round_items.claimed', true))))
            ->toBase()->get(['grid_round_items.wallet as wallet', 'grid_round_items.amount as amount']);
        foreach ($rows as $row) {
            $out[$row->wallet] = ($out[$row->wallet] ?? 0) + $this->microOf((string) $row->amount);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $ready  หน่วย 0.000001
     * @return list<array{wallet: string, ready: float, allocated: float, due: float, micro: int}>
     */
    protected function dueFromMicro(array $ready): array
    {
        $alloc = $this->allocatedMicro();
        $min = (int) round($this->minDue() * 1_000_000);
        $rows = [];
        foreach ($ready as $w => $micro) {
            $due = $micro - ($alloc[$w] ?? 0);
            if ($due < $min) {
                continue;
            }
            $rows[] = ['wallet' => $w, 'ready' => $micro / 1_000_000, 'allocated' => ($alloc[$w] ?? 0) / 1_000_000, 'due' => $due / 1_000_000, 'micro' => $due];
        }
        usort($rows, fn ($a, $b) => $b['micro'] <=> $a['micro']);

        return $rows;
    }

    /**
     * แบ่งรอบเป็น "รับได้ทันที" + "ปลดทีหลัง" ไหม — null = รอบเดียวเต็มจำนวน
     *
     * @return array{ppm: int, unlock: CarbonImmutable}|null
     */
    protected function split(CarbonImmutable $now): ?array
    {
        return null;
    }

    protected function lockKey(): string
    {
        return 'idtc:payout:build:'.$this->stream();
    }

    public function reader(): RewardsReader
    {
        return $this->reader;
    }

    /** @return array<string, float> กระเป๋า => ยอดที่จัดสรรแล้วของสายนี้ (draft + live + รอบยกเลิกที่รับไปแล้วหรือยังไม่รู้) */
    public function allocated(): array
    {
        return GridRoundItem::query()
            ->join('grid_rounds', 'grid_rounds.id', '=', 'grid_round_items.round_id')
            ->where('grid_rounds.stream', $this->stream())
            ->where(fn ($q) => $q->whereIn('grid_rounds.status', GridRound::ALLOCATED)
                ->orWhere(fn ($r) => $r->where('grid_rounds.status', GridRound::REVOKED)
                    ->where(fn ($c) => $c->whereNull('grid_round_items.claimed')->orWhere('grid_round_items.claimed', true))))
            ->groupBy('grid_round_items.wallet')
            ->selectRaw('grid_round_items.wallet as wallet, sum(grid_round_items.amount_idtc) as total')
            ->pluck('total', 'wallet')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * ยอดที่รอบถัดไปจะจ่าย จากยอดถึงกำหนดของแต่ละกระเป๋า
     *
     * @param  array<string, float>  $ready
     * @return list<array{wallet: string, ready: float, allocated: float, due: float}>
     */
    protected function dueFrom(array $ready): array
    {
        $alloc = $this->allocated();
        $rows = [];
        foreach ($ready as $w => $amount) {
            $due = (float) $amount - ($alloc[$w] ?? 0.0);
            if ($due + 1e-9 < $this->minDue()) {
                continue;
            }
            $rows[] = ['wallet' => $w, 'ready' => (float) $amount, 'allocated' => $alloc[$w] ?? 0.0, 'due' => $due];
        }
        usort($rows, fn ($a, $b) => $b['due'] <=> $a['due']);

        return $rows;
    }

    /** @return list<array{wallet: string, ready: float, allocated: float, due: float}> */
    public function dueNow(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $exact = $this->readyMicroNow($now);

        return $exact !== null ? $this->dueFromMicro($exact) : $this->dueFrom($this->readyNow($now));
    }

    public function draft(): ?GridRound
    {
        $contract = $this->reader->address();

        return $contract === null ? null : GridRound::query()->where('stream', $this->stream())->where('contract', strtolower($contract))
            ->where('status', GridRound::DRAFT)->orderBy('chain_round')->first();
    }

    /**
     * สร้างรอบจ่าย — กันกดพร้อมกัน (สองแท็บ/สองแอดมิน) ด้วย lock: ได้รายชื่อชุดเดียวเสมอ
     *
     * @return array{ok: bool, message: string, rounds: list<GridRound>}
     */
    public function build(?string $by, ?string $note, ?CarbonImmutable $now = null): array
    {
        $lock = Cache::lock($this->lockKey(), 120);
        if (! $lock->get()) {
            return $this->fail('มีการสร้างรอบจ่ายอื่นกำลังทำอยู่ — รอสักครู่แล้วรีเฟรชหน้า');
        }
        try {
            return $this->buildLocked($by, $note, $now);
        } finally {
            $lock->release();
        }
    }

    /** @return array{ok: bool, message: string, rounds: list<GridRound>} */
    protected function buildLocked(?string $by, ?string $note, ?CarbonImmutable $now): array
    {
        $now ??= CarbonImmutable::now();
        $unit = $this->unit();
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

        $rows = $this->dueNow($now);
        if ($rows === []) {
            return $this->fail('ยังไม่มียอดครบระยะที่ยังไม่ได้จัดสรร (ขั้นต่ำ '.rtrim(rtrim(number_format($this->minDue(), 2), '0'), '.').' '.$unit.' ต่อกระเป๋า)');
        }

        $split = $this->split($now);
        $ppm = $split['ppm'] ?? 0;
        $parts = $split !== null ? ['instant' => [], 'deferred' => []] : ['full' => []];
        foreach ($rows as $r) {
            $micro = $r['micro'] ?? (int) floor($r['due'] * 1_000_000 + 1e-6);
            if ($split !== null) {
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
        $unlock = $split['unlock'] ?? null;
        DB::transaction(function () use ($parts, $contract, $by, $note, $unlock, &$next, &$made) {
            foreach ($parts as $kind => $amounts) {
                $amounts = array_filter($amounts, fn ($m) => $m > 0);
                if ($amounts === []) {
                    continue;
                }
                ksort($amounts, SORT_STRING); // ลำดับคงที่ → root เดิมเสมอสำหรับรายชื่อเดิม
                $wallets = array_keys($amounts);
                $leaves = [];
                foreach ($wallets as $w) {
                    $leaves[] = GridMerkle::leaf($contract, $next, $w, $this->raw($amounts[$w]));
                }
                $tree = new GridMerkle($leaves);
                $totalMicro = array_sum($amounts);
                $round = GridRound::query()->create([
                    'stream' => $this->stream(),
                    'contract' => strtolower($contract), 'chain_round' => $next, 'kind' => $kind, 'status' => GridRound::DRAFT,
                    'root' => $tree->root(), 'total' => $this->raw($totalMicro), 'total_idtc' => $totalMicro / 1_000_000,
                    'items' => count($wallets), 'unlock_at' => $kind === 'deferred' ? $unlock : null,
                    'note' => $note, 'created_by' => $by,
                ]);
                $rowsOut = [];
                foreach ($wallets as $i => $w) {
                    $rowsOut[] = [
                        'round_id' => $round->id, 'wallet' => $w, 'amount' => $this->raw($amounts[$w]),
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
            .number_format($sum, 2).' '.$unit.' — โอน '.$unit.' เข้าสัญญาให้พอ แล้วกดประกาศทีละรอบตามลำดับ'];
    }

    /** ตัดสินสถานะจากเชน — คืนจำนวนรอบที่สถานะเปลี่ยน */
    public function reconcile(): int
    {
        $contract = $this->reader->address();
        if ($contract === null) {
            return 0;
        }
        // draft ของสัญญาอื่นในสายเดียวกัน (เปลี่ยนที่อยู่สัญญาแล้ว) ประกาศไม่ได้อีก — ทิ้ง ยอดกลับไปรอรอบถัดไป
        $changed = GridRound::query()->where('stream', $this->stream())->where('status', GridRound::DRAFT)->where('contract', '!=', strtolower($contract))
            ->update(['status' => GridRound::DISCARDED, 'updated_at' => now()]);
        $this->reader->forget();
        $chain = $this->reader->read();
        if (! $chain['ok']) {
            return $changed;
        }
        $open = GridRound::query()->where('stream', $this->stream())->where('contract', strtolower($contract))
            ->whereIn('status', [GridRound::DRAFT, GridRound::LIVE])->get();
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
        $rounds = GridRound::query()->where('stream', $this->stream())->where('contract', strtolower($contract))->where('status', GridRound::REVOKED)
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
        if ($round->status !== GridRound::DRAFT || $round->stream !== $this->stream()) {
            return false;
        }
        // ทิ้งทั้งชุดที่สร้างพร้อมกัน (รอบหลังอิงเลขรอบของรอบก่อน)
        GridRound::query()->where('stream', $this->stream())->where('contract', $round->contract)->where('status', GridRound::DRAFT)
            ->where('chain_round', '>=', $round->chain_round)
            ->update(['status' => GridRound::DISCARDED, 'updated_at' => now()]);

        return true;
    }

    /**
     * ยอดในรอบที่ประกาศแล้วของกระเป๋านี้ พร้อม proof และสถานะรับแล้ว (อ่านจากเชนสด)
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
            ->whereHas('round', fn ($q) => $q->where('stream', $this->stream())->where('status', GridRound::LIVE)->where('contract', strtolower($contract)))
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

    /** @return array{ok: false, message: string, rounds: list<GridRound>} */
    protected function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rounds' => []];
    }
}
