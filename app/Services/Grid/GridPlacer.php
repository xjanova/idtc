<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use LogicException;

/**
 * วางตำแหน่งในผัง Binary (สองขา) ตอนผูกผู้แนะนำ
 *
 * กติกา:
 *  - คนใหม่ลงใต้ผู้แนะนำเสมอ ตามวิธีวางที่ผู้แนะนำเลือก (bin_mode — ดู MODES):
 *      weak   ขาที่คนน้อยกว่า (เท่ากัน = ซ้าย) แล้วต่อท้ายขอบนอกของขานั้น — ค่าเริ่มต้น (แบบเดิม)
 *      strong ขาที่คนมากกว่า (เท่ากัน = ซ้าย) แล้วต่อท้ายขอบนอก
 *      left / right  ขาซ้าย/ขวาเสมอ แล้วต่อท้ายขอบนอก
 *      fill   เต็มชั้น อุดฟันหลอ: ช่องว่างแรกในทีม ไล่จากชั้นบนลงล่าง ซ้ายไปขวา
 *      manual รอจัดสาย: ยังไม่วาง เข้าคิวให้ผู้แนะนำ (หรือแอดมิน) เลือกช่องเอง — TreeEditor
 *  - ลิงก์ชวนที่ระบุขา (/r|b/{wallet}/l|r) ชนะขาของวิธีวาง (fill = อุดช่องว่างเฉพาะในขานั้น · manual = แค่คำแนะนำในคิว)
 *  - ต่อท้ายขอบนอก = ขานั้นมีคนแล้ว ลงต่อไปทางเดิม (ซ้าย→ซ้าย→… หรือ ขวา→ขวา→…) จนเจอที่ว่าง (spillover)
 *  - หนึ่งตำแหน่งมีได้คนเดียว (unique index + ผู้เรียกถือ Cache lock LOCK ระหว่างวาง)
 *  - คนที่ผูกผู้แนะนำไว้ก่อนมีผัง Binary ถูกวางย้อนหลังตามลำดับที่ผูก (placeMissing) ก่อนวางคนใหม่ทุกครั้ง
 *    (คนที่อยู่ในคิวรอจัดสายไม่ถูกวางอัตโนมัติ)
 *  - ย้ายตำแหน่งหลังวางแล้ว = TreeEditor เท่านั้น (บันทึกประวัติ ตัวคำนวณจึงไม่แบ่งยอดสัปดาห์เก่าใหม่)
 */
final class GridPlacer
{
    public const LEFT = 'L';

    public const RIGHT = 'R';

    public const LOCK = 'idtc:grid:place';

    /** เดินลงผังลึก/กว้างเกินนี้ถือว่าข้อมูลผิดปกติ */
    public const MAX_WALK = 1_000_000;

    public const WEAK = 'weak';

    public const STRONG = 'strong';

    public const LEFT_EDGE = 'left';

    public const RIGHT_EDGE = 'right';

    public const FILL = 'fill';

    public const MANUAL = 'manual';

    /** วิธีวางทั้งหมด (ลำดับนี้คือลำดับในตัวเลือกบนหน้าเว็บ) */
    public const MODES = [self::WEAK, self::STRONG, self::LEFT_EDGE, self::RIGHT_EDGE, self::FILL, self::MANUAL];

    /** แปลงค่าที่รับมา (l/r/left/right ตัวเล็กใหญ่) เป็น L | R | null */
    public static function side(mixed $v): ?string
    {
        return match (is_string($v) ? strtolower(trim($v)) : null) {
            'l', 'left' => self::LEFT,
            'r', 'right' => self::RIGHT,
            default => null,
        };
    }

    /** ตรวจชื่อวิธีวาง — ไม่รู้จัก = null */
    public static function mode(mixed $v): ?string
    {
        $v = is_string($v) ? strtolower(trim($v)) : null;

        return in_array($v, self::MODES, true) ? $v : null;
    }

    /** วิธีวางของกระเป๋านี้ (ยังไม่ได้ตั้ง bin_mode = ตามค่าเดิม bin_side_pref: L → left · R → right · ว่าง → weak) */
    public static function modeOf(?GridMember $row): string
    {
        return self::mode($row?->bin_mode) ?? match (self::side($row?->bin_side_pref)) {
            self::LEFT => self::LEFT_EDGE,
            self::RIGHT => self::RIGHT_EDGE,
            default => self::WEAK,
        };
    }

    /** ตั้งวิธีวาง (ยังไม่ save) — เก็บ bin_side_pref ให้ตรงกันสำหรับหน้า/โค้ดที่อ่านค่าเดิม */
    public static function applyMode(GridMember $row, string $mode): void
    {
        $row->bin_mode = $mode;
        $row->bin_side_pref = match ($mode) {
            self::LEFT_EDGE => self::LEFT,
            self::RIGHT_EDGE => self::RIGHT,
            default => null,
        };
    }

    /**
     * วางสมาชิกหนึ่งคน (ต้องมี referrer แล้ว) — เรียกใน DB transaction ขณะถือ LOCK
     * ผู้แนะนำเลือก "รอจัดสาย" = เข้าคิว (bin_wait_at) ไม่วาง
     *
     * @return array{parent: string, leg: string}|null null = ไม่ต้องวาง (ไม่มีผู้แนะนำ วางแล้ว หรือเข้าคิวรอจัดสาย)
     */
    public function place(GridMember $row, ?string $side = null): ?array
    {
        if ($row->referrer === null || $row->bin_parent !== null || $row->bin_wait_at !== null) {
            return null;
        }
        $slots = $this->slots();
        // คนที่ผูกก่อนหน้าแต่ยังไม่ได้วาง (ผูกก่อนมีผัง Binary) ต้องได้ที่ก่อน — ลำดับในผังตรงกับลำดับที่ผูก
        $this->placeMissingIn($slots, $row->wallet);

        if (self::modeOf(GridMember::query()->where('wallet', $row->referrer)->first()) === self::MANUAL) {
            $row->fill(['bin_wait_at' => now(), 'bin_wait_side' => self::side($side)])->save();

            return null;
        }

        return $this->placeIn($slots, $row, $side);
    }

    /** วางทุกคนที่ผูกผู้แนะนำแล้วแต่ยังไม่มีตำแหน่ง (ไม่รวมคิวรอจัดสาย) ตามลำดับที่ผูก — คืนจำนวนที่วาง (เรียกขณะถือ LOCK) */
    public function placeMissing(): int
    {
        $slots = $this->slots();

        return $this->placeMissingIn($slots, null);
    }

    /** @return int จำนวนกระเป๋าที่ผูกผู้แนะนำแล้วแต่ยังไม่มีตำแหน่ง (ไม่รวมคิวรอจัดสาย) */
    public function missing(): int
    {
        return GridMember::query()->whereNotNull('referrer')->whereNull('bin_parent')->whereNull('bin_wait_at')->count();
    }

    /**
     * ช่องที่วิธีวาง $mode เลือกในทีมของ $root (ผังปัจจุบัน) — ใช้ตอนจัดสายจากคิวแบบกดทีเดียว
     *
     * @param  array<string, array<string, string>>|null  $slots
     * @return array{parent: string, leg: string}
     */
    public function target(string $root, string $mode, ?string $side = null, ?array $slots = null): array
    {
        $slots ??= $this->slots();
        [$parent, $leg] = $this->pick($slots, $root, $mode === self::MANUAL ? self::WEAK : $mode, self::side($side), null);

        return ['parent' => $parent, 'leg' => $leg];
    }

    /** @return array<string, array<string, string>> parent => [L => wallet, R => wallet] (ผังปัจจุบัน) */
    public function slots(): array
    {
        $slots = [];
        foreach (GridMember::query()->whereNotNull('bin_parent')->toBase()->get(['wallet', 'bin_parent', 'bin_leg']) as $m) {
            $slots[$m->bin_parent][$m->bin_leg] = $m->wallet;
        }

        return $slots;
    }

    /**
     * ทุกกระเป๋าในทีม (สายล่างในผัง) ของ $root รวมตัวเอง
     *
     * @param  array<string, array<string, string>>  $slots
     * @return array<string, true>
     */
    public static function team(array $slots, string $root): array
    {
        $out = [];
        $stack = [$root];
        while ($stack) {
            $x = array_pop($stack);
            if (isset($out[$x])) {
                continue;
            }
            $out[$x] = true;
            foreach ($slots[$x] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /** @param array<string, array<string, string>> $slots */
    private function placeMissingIn(array &$slots, ?string $except): int
    {
        $rows = GridMember::query()->whereNotNull('referrer')->whereNull('bin_parent')->whereNull('bin_wait_at')
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
        $mode = self::modeOf(GridMember::query()->where('wallet', $sponsor)->first());
        // วางย้อนหลังต้องได้ที่เสมอ — ผู้แนะนำที่เลือกรอจัดสายใช้ขาอ่อนแทน
        [$parent, $leg] = $this->pick($slots, $sponsor, $mode === self::MANUAL ? self::WEAK : $mode, self::side($side), $row->wallet);

        $row->fill([
            'bin_parent' => $parent,
            'bin_leg' => $leg,
            // วางย้อนหลัง = นับตั้งแต่วันที่ผูก (การซื้อหลังผูกนับเป็นยอดขา)
            'bin_placed_at' => $backfill ? ($row->bound_at ?? now()) : now(),
        ])->save();
        $slots[$parent][$leg] = $row->wallet;

        return ['parent' => $parent, 'leg' => $leg];
    }

    /**
     * @param  array<string, array<string, string>>  $slots
     * @return array{0: string, 1: string} [parent, leg]
     */
    private function pick(array $slots, string $root, string $mode, ?string $side, ?string $self): array
    {
        if ($mode === self::FILL) {
            return $this->firstOpen($slots, $root, $side, $self);
        }
        $side ??= match ($mode) {
            self::LEFT_EDGE => self::LEFT,
            self::RIGHT_EDGE => self::RIGHT,
            self::STRONG => $this->size($slots, $slots[$root][self::RIGHT] ?? null) > $this->size($slots, $slots[$root][self::LEFT] ?? null) ? self::RIGHT : self::LEFT,
            default => $this->size($slots, $slots[$root][self::RIGHT] ?? null) < $this->size($slots, $slots[$root][self::LEFT] ?? null) ? self::RIGHT : self::LEFT,
        };

        $cur = $root;
        for ($i = 0; isset($slots[$cur][$side]); $i++) {
            $cur = $slots[$cur][$side];
            if ($cur === $self || $i >= self::MAX_WALK) {
                throw new LogicException('grid binary tree is inconsistent near '.$cur);
            }
        }

        return [$cur, $side];
    }

    /**
     * เต็มชั้น อุดฟันหลอ: ช่องว่างแรกแบบไล่ทีละชั้น (ซ้ายก่อนขวา) — ระบุขา = ไล่เฉพาะในขานั้น
     *
     * @param  array<string, array<string, string>>  $slots
     * @return array{0: string, 1: string}
     */
    private function firstOpen(array $slots, string $root, ?string $side, ?string $self): array
    {
        if ($side !== null) {
            if (! isset($slots[$root][$side])) {
                return [$root, $side];
            }
            $queue = [$slots[$root][$side]];
        } else {
            $queue = [$root];
        }
        $seen = [];
        for ($h = 0; isset($queue[$h]); $h++) {
            $x = $queue[$h];
            if (isset($seen[$x]) || $x === $self || $h >= self::MAX_WALK) {
                throw new LogicException('grid binary tree is inconsistent near '.$x);
            }
            $seen[$x] = true;
            foreach ([self::LEFT, self::RIGHT] as $leg) {
                if (! isset($slots[$x][$leg])) {
                    return [$x, $leg];
                }
            }
            $queue[] = $slots[$x][self::LEFT];
            $queue[] = $slots[$x][self::RIGHT];
        }

        throw new LogicException('grid binary tree has no open slot under '.$root);
    }

    /**
     * จำนวนคนในทีมของ $root (รวมตัวเอง · null = 0)
     *
     * @param  array<string, array<string, string>>  $slots
     */
    private function size(array $slots, ?string $root): int
    {
        return $root === null ? 0 : count(self::team($slots, $root));
    }
}
