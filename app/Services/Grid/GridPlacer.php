<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use LogicException;

/**
 * วางตำแหน่งในผัง Binary (สองขา) ตอนผูกผู้แนะนำ
 *
 * กติกา:
 *  - คนใหม่ลงใต้ผู้แนะนำเสมอ ขาที่เลือก = ขาจากลิงก์ชวน (/r/{wallet}/l|r) → ขาที่ผู้แนะนำตั้งไว้ → อัตโนมัติ (ขาที่คนน้อยกว่า เท่ากัน = ซ้าย)
 *  - ขานั้นมีคนแล้ว = ต่อท้ายขอบนอกของขานั้นลงไปเรื่อย ๆ (ซ้าย→ซ้าย→… หรือ ขวา→ขวา→…) จนเจอที่ว่าง (spillover)
 *  - วางครั้งเดียว ไม่ย้าย · หนึ่งตำแหน่งมีได้คนเดียว (unique index + ผู้เรียกถือ Cache lock LOCK ระหว่างวาง)
 *  - คนที่ผูกผู้แนะนำไว้ก่อนมีผัง Binary ถูกวางย้อนหลังตามลำดับที่ผูก (placeMissing) ก่อนวางคนใหม่ทุกครั้ง
 *
 * ผังอยู่ใต้สายแนะนำเสมอ: ทุกคนในขาของ X เป็นสายล่างทางการแนะนำของ X → ไม่เกิดวงวน (ตอนผูกตรวจสายแนะนำแล้ว)
 */
final class GridPlacer
{
    public const LEFT = 'L';

    public const RIGHT = 'R';

    public const LOCK = 'idtc:grid:place';

    /** เดินลงขาเดียวลึกเกินนี้ถือว่าข้อมูลผิดปกติ */
    public const MAX_WALK = 1_000_000;

    /** แปลงค่าที่รับมา (l/r/left/right ตัวเล็กใหญ่) เป็น L | R | null */
    public static function side(mixed $v): ?string
    {
        return match (is_string($v) ? strtolower(trim($v)) : null) {
            'l', 'left' => self::LEFT,
            'r', 'right' => self::RIGHT,
            default => null,
        };
    }

    /**
     * วางสมาชิกหนึ่งคน (ต้องมี referrer แล้ว) — เรียกใน DB transaction ขณะถือ LOCK
     *
     * @return array{parent: string, leg: string}|null null = ไม่ต้องวาง (ไม่มีผู้แนะนำ หรือวางไปแล้ว)
     */
    public function place(GridMember $row, ?string $side = null): ?array
    {
        if ($row->referrer === null || $row->bin_parent !== null) {
            return null;
        }
        $slots = $this->slots();
        // คนที่ผูกก่อนหน้าแต่ยังไม่ได้วาง (ผูกก่อนมีผัง Binary) ต้องได้ที่ก่อน — ลำดับในผังตรงกับลำดับที่ผูก
        $this->placeMissingIn($slots, $row->wallet);

        return $this->placeIn($slots, $row, $side);
    }

    /** วางทุกคนที่ผูกผู้แนะนำแล้วแต่ยังไม่มีตำแหน่ง ตามลำดับที่ผูก — คืนจำนวนที่วาง (เรียกขณะถือ LOCK) */
    public function placeMissing(): int
    {
        $slots = $this->slots();

        return $this->placeMissingIn($slots, null);
    }

    /** @return int จำนวนกระเป๋าที่ผูกผู้แนะนำแล้วแต่ยังไม่มีตำแหน่ง */
    public function missing(): int
    {
        return GridMember::query()->whereNotNull('referrer')->whereNull('bin_parent')->count();
    }

    /** @param array<string, array<string, string>> $slots */
    private function placeMissingIn(array &$slots, ?string $except): int
    {
        $rows = GridMember::query()->whereNotNull('referrer')->whereNull('bin_parent')
            ->when($except !== null, fn ($q) => $q->where('wallet', '!=', $except))
            ->orderBy('bound_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $this->placeIn($slots, $row, null, true);
        }

        return $rows->count();
    }

    /**
     * @param  array<string, array<string, string>>  $slots  parent => [L => wallet, R => wallet]
     * @return array{parent: string, leg: string}
     */
    private function placeIn(array &$slots, GridMember $row, ?string $side, bool $backfill = false): array
    {
        $sponsor = (string) $row->referrer;
        $side = self::side($side)
            ?? self::side(GridMember::query()->where('wallet', $sponsor)->value('bin_side_pref'))
            ?? $this->weaker($slots, $sponsor);

        $cur = $sponsor;
        for ($i = 0; isset($slots[$cur][$side]); $i++) {
            $cur = $slots[$cur][$side];
            if ($cur === $row->wallet || $i >= self::MAX_WALK) {
                throw new LogicException('grid binary tree is inconsistent near '.$cur);
            }
        }

        $row->fill([
            'bin_parent' => $cur,
            'bin_leg' => $side,
            // วางย้อนหลัง = นับตั้งแต่วันที่ผูก (การซื้อหลังผูกนับเป็นยอดขา)
            'bin_placed_at' => $backfill ? ($row->bound_at ?? now()) : now(),
        ])->save();
        $slots[$cur][$side] = $row->wallet;

        return ['parent' => $cur, 'leg' => $side];
    }

    /** @return array<string, array<string, string>> parent => [L => wallet, R => wallet] */
    private function slots(): array
    {
        $slots = [];
        foreach (GridMember::query()->whereNotNull('bin_parent')->toBase()->get(['wallet', 'bin_parent', 'bin_leg']) as $m) {
            $slots[$m->bin_parent][$m->bin_leg] = $m->wallet;
        }

        return $slots;
    }

    /**
     * ขาที่มีคนน้อยกว่าใต้กระเป๋านี้ (เท่ากัน = ซ้าย)
     *
     * @param  array<string, array<string, string>>  $slots
     */
    private function weaker(array $slots, string $wallet): string
    {
        $count = function (?string $root) use ($slots): int {
            $n = 0;
            $stack = $root === null ? [] : [$root];
            while ($stack) {
                $w = array_pop($stack);
                $n++;
                foreach ($slots[$w] ?? [] as $child) {
                    $stack[] = $child;
                }
            }

            return $n;
        };

        return $count($slots[$wallet][self::RIGHT] ?? null) < $count($slots[$wallet][self::LEFT] ?? null) ? self::RIGHT : self::LEFT;
    }
}
