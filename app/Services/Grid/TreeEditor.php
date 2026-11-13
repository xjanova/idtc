<?php

namespace App\Services\Grid;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Models\GridTreeChange;
use App\Models\Purchase;
use App\Services\Chain\Address;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * จัดการผัง Binary หลังบ้านและโดยแม่ทีม — ทุกการเปลี่ยนบันทึกลง grid_tree_changes
 *
 *  - จัดสาย: วางคนที่อยู่ในคิวรอจัดสายลงช่องว่าง (แม่ทีมทำได้เฉพาะคนที่ตัวเองชวน และเฉพาะช่องในทีมตัวเอง)
 *  - ย้ายสาย: ย้ายคนที่อยู่ในผังแล้วพร้อมทีมทั้งหมดใต้เขาไปช่องว่างอื่น (แอดมิน)
 *  - ต่อสาย: วางคนที่ยังไม่อยู่ในผัง (บนสุดของผังตัวเอง หรือฝาก/ซื้อโดยไม่มีผู้แนะนำ) พร้อมทีมของเขาลงช่องว่าง (แอดมิน)
 *  - เปลี่ยนผู้แนะนำ: owner เท่านั้น (ตรวจที่ controller) — ตำแหน่งในผังไม่เปลี่ยนตาม
 *
 * ทุกการเปลี่ยนมีผลตั้งแต่สัปดาห์ขายปัจจุบัน — ตัวคำนวณ (BinaryTree / GridLedger) ใช้ผังเดิมกับสัปดาห์ที่ปิดไปแล้ว
 * ทำทีละรายการทั้งระบบ (Cache lock เดียวกับการผูก/วางอัตโนมัติ) · ห้ามวางลงในทีมของตัวเอง (กันวงวน)
 */
final class TreeEditor
{
    /** สายบนลึกเกินนี้ถือว่าข้อมูลผิดปกติ */
    public const MAX_WALK = 10000;

    public function __construct(protected GridPlacer $placer) {}

    /**
     * ตรวจก่อนจัด/ย้าย/ต่อสาย (และตรวจซ้ำตอนบันทึก)
     *
     * @param  string|null  $scope  แม่ทีมที่ทำรายการเอง (null = แอดมิน)
     * @return array{error: ?string, warnings: list<string>, info: array<string, mixed>}
     *                                                                                   error/warnings = รหัสใน lang binary.tree.err.* / binary.tree.warn.*
     */
    public function check(string $wallet, string $parent, string $leg, ?string $scope = null): array
    {
        $out = fn (?string $error, array $warnings = [], array $info = []) => ['error' => $error, 'warnings' => $warnings, 'info' => $info];
        $leg = GridPlacer::side($leg);
        if (! Address::isValid($wallet) || Address::isZero($wallet)) {
            return $out('wallet');
        }
        if (! Address::isValid($parent) || Address::isZero($parent)) {
            return $out('parent');
        }
        if ($leg === null) {
            return $out('leg');
        }
        $wallet = strtolower($wallet);
        $parent = strtolower($parent);
        if ($wallet === $parent) {
            return $out('self');
        }
        $row = GridMember::query()->where('wallet', $wallet)->first();
        if ($row === null && ! $this->known($wallet)) {
            return $out('unknown_wallet');
        }
        if (! $this->known($parent)) {
            return $out('unknown_parent');
        }
        if ($row?->bin_parent === $parent && $row->bin_leg === $leg) {
            return $out('same');
        }
        $slots = $this->placer->slots();
        if (isset($slots[$parent][$leg])) {
            return $out('slot_taken');
        }
        $team = GridPlacer::team($slots, $wallet);
        if (isset($team[$parent])) {
            return $out('cycle');
        }
        if ($scope !== null) {
            $scope = strtolower($scope);
            if ($row === null || $row->referrer !== $scope) {
                return $out('not_yours');
            }
            if ($row->bin_wait_at === null || $row->bin_parent !== null) {
                return $out('not_waiting');
            }
            if (! isset(GridPlacer::team($slots, $scope)[$parent])) {
                return $out('outside_team');
            }
        }

        $warnings = [];
        $referrer = $row?->referrer;
        if ($referrer !== null && ! isset(GridPlacer::team($slots, $referrer)[$parent])) {
            $warnings[] = 'outside_sponsor';   // ผู้แนะนำจะไม่นับเขาเป็นเพื่อนตรงในขาของตัวเอง
        }
        if (count($team) > 1) {
            $warnings[] = 'team';
        }
        if ($row?->held_at !== null) {
            $warnings[] = 'held';
        }
        $week = max(0, GridPlan::weekAt(now()));

        return $out(null, $warnings, [
            'kind' => $row?->bin_parent === null ? GridTreeChange::PLACE : GridTreeChange::MOVE,
            'wallet' => $wallet, 'referrer' => $referrer,
            'from_parent' => $row?->bin_parent, 'from_leg' => $row?->bin_leg,
            'to_parent' => $parent, 'to_leg' => $leg,
            'team' => count($team), 'week' => $week, 'week_start' => GridPlan::weekStart($week)->getTimestamp(),
            'waiting' => $row?->bin_wait_at !== null,
        ]);
    }

    /**
     * จัด/ย้าย/ต่อสาย — ผ่านการตรวจแล้วบันทึก + ประวัติ
     *
     * @param  string  $role  owner | admin | member
     * @return GridTreeChange|string แถวประวัติ หรือรหัสข้อผิดพลาด (busy = มีคนกำลังวาง/ย้ายอยู่ ลองใหม่)
     */
    public function place(string $wallet, string $parent, string $leg, string $actor, string $role, ?string $reason = null, ?string $scope = null): GridTreeChange|string
    {
        return $this->locked(function () use ($wallet, $parent, $leg, $actor, $role, $reason, $scope) {
            $check = $this->check($wallet, $parent, $leg, $scope);
            if ($check['error'] !== null) {
                return $check['error'];
            }
            $i = $check['info'];
            $row = GridMember::query()->firstOrNew(['wallet' => $i['wallet']]);
            $row->fill([
                'bin_parent' => $i['to_parent'],
                'bin_leg' => $i['to_leg'],
                'bin_placed_at' => $row->bin_placed_at ?? now(),   // เวลาวางครั้งแรก — ย้ายไม่เปลี่ยน (BinaryTree ใช้)
                'bin_wait_at' => null,
                'bin_wait_side' => null,
            ])->save();

            return $this->log($i['wallet'], $i['kind'], [
                'from_parent' => $i['from_parent'], 'from_leg' => $i['from_leg'], 'to_parent' => $i['to_parent'], 'to_leg' => $i['to_leg'], 'team' => $i['team'],
            ], $actor, $role, $reason);
        });
    }

    /**
     * จัดสายจากคิวแบบกดทีเดียว: ให้ระบบเลือกช่องตามวิธีวาง (weak | strong | left | right | fill) ในทีมของผู้แนะนำ
     */
    public function placeAuto(string $wallet, string $mode, string $actor, string $role, ?string $scope = null): GridTreeChange|string
    {
        $mode = GridPlacer::mode($mode);
        if ($mode === null || $mode === GridPlacer::MANUAL) {
            return 'mode';
        }

        // คำนวณช่องในล็อกเดียวกับที่วาง ช่องจะไม่ถูกคนอื่นแย่งไประหว่างนั้น
        return $this->locked(function () use ($wallet, $mode, $actor, $role, $scope) {
            $row = GridMember::query()->where('wallet', strtolower($wallet))->first();
            if ($row === null || $row->referrer === null) {
                return $row === null ? 'unknown_wallet' : 'no_referrer';
            }
            if ($row->bin_parent !== null) {
                return 'not_waiting';
            }
            $t = $this->placer->target($row->referrer, $mode);
            $check = $this->check($row->wallet, $t['parent'], $t['leg'], $scope);
            if ($check['error'] !== null) {
                return $check['error'];
            }
            $i = $check['info'];
            $row->fill([
                'bin_parent' => $i['to_parent'], 'bin_leg' => $i['to_leg'], 'bin_placed_at' => $row->bin_placed_at ?? now(),
                'bin_wait_at' => null, 'bin_wait_side' => null,
            ])->save();

            return $this->log($row->wallet, $i['kind'], [
                'from_parent' => null, 'from_leg' => null, 'to_parent' => $i['to_parent'], 'to_leg' => $i['to_leg'], 'team' => $i['team'],
            ], $actor, $role, 'auto:'.$mode);
        });
    }

    /**
     * ตรวจก่อนเปลี่ยนผู้แนะนำ
     *
     * @return array{error: ?string, warnings: list<string>, info: array<string, mixed>}
     */
    public function checkReferrer(string $wallet, string $referrer): array
    {
        $out = fn (?string $error, array $warnings = [], array $info = []) => ['error' => $error, 'warnings' => $warnings, 'info' => $info];
        if (! Address::isValid($wallet) || Address::isZero($wallet)) {
            return $out('wallet');
        }
        if (! Address::isValid($referrer) || Address::isZero($referrer)) {
            return $out('referrer');
        }
        $wallet = strtolower($wallet);
        $referrer = strtolower($referrer);
        if ($wallet === $referrer) {
            return $out('self');
        }
        $row = GridMember::query()->where('wallet', $wallet)->first();
        if ($row === null && ! $this->known($wallet)) {
            return $out('unknown_wallet');
        }
        if ($row?->referrer === $referrer) {
            return $out('same');
        }
        // เดินขึ้นจากผู้แนะนำใหม่ — เจอตัวเอง = ผู้แนะนำใหม่อยู่ในสายล่างทางการแนะนำของเรา (วงวน)
        $cur = $referrer;
        for ($i = 0; $i < self::MAX_WALK && $cur !== null; $i++) {
            if ($cur === $wallet) {
                return $out('cycle');
            }
            $cur = GridMember::query()->where('wallet', $cur)->value('referrer');
        }

        $warnings = [];
        if (! $this->known($referrer)) {
            $warnings[] = 'referrer_new';
        }
        if ($row?->bin_parent !== null && ! isset(GridPlacer::team($this->placer->slots(), $referrer)[$wallet])) {
            $warnings[] = 'not_under_referrer';
        }
        $week = max(0, GridPlan::weekAt(now()));

        return $out(null, $warnings, [
            'wallet' => $wallet, 'from_referrer' => $row?->referrer, 'to_referrer' => $referrer,
            'week' => $week, 'week_start' => GridPlan::weekStart($week)->getTimestamp(),
            'waiting' => $row?->bin_wait_at !== null,
        ]);
    }

    /**
     * เปลี่ยนผู้แนะนำ (owner) — รางวัลของการซื้อก่อนหน้ายังเป็นของสายเดิม · ตำแหน่งในผังไม่ย้ายตาม
     */
    public function changeReferrer(string $wallet, string $referrer, string $actor, string $reason): GridTreeChange|string
    {
        return $this->locked(function () use ($wallet, $referrer, $actor, $reason) {
            $check = $this->checkReferrer($wallet, $referrer);
            if ($check['error'] !== null) {
                return $check['error'];
            }
            $i = $check['info'];
            $row = GridMember::query()->firstOrNew(['wallet' => $i['wallet']]);
            $row->referrer = $i['to_referrer'];
            $row->bound_at ??= now();   // ไม่เคยผูก = นับตั้งแต่ตอนนี้
            $row->save();

            return $this->log($i['wallet'], GridTreeChange::REFERRER, ['from_referrer' => $i['from_referrer'], 'to_referrer' => $i['to_referrer']], $actor, 'owner', $reason);
        });
    }

    /** ตั้งวิธีวางคนใหม่ของกระเป๋านี้ (ไม่ย้ายใครที่วางไปแล้ว · คนที่รออยู่ยังรอจนกว่าจะถูกจัด) */
    public function setMode(string $wallet, string $mode): GridMember
    {
        $row = GridMember::query()->firstOrNew(['wallet' => strtolower($wallet)]);
        GridPlacer::applyMode($row, $mode);
        $row->save();

        return $row;
    }

    /** กระเป๋านี้อยู่ในระบบแล้วไหม (มีแถวสมาชิก / เป็นผู้แนะนำหรือคนเหนือในผังของใคร / เคยฝากหรือซื้อ) — กันพิมพ์ที่อยู่ผิด */
    public function known(string $wallet): bool
    {
        $wallet = strtolower($wallet);

        return GridMember::query()->where('wallet', $wallet)->orWhere('referrer', $wallet)->orWhere('bin_parent', $wallet)->exists()
            || BinaryDeposit::query()->where('wallet', $wallet)->where('status', BinaryDeposit::OK)->exists()
            || Purchase::query()->where('buyer', $wallet)->exists();
    }

    /**
     * @param  \Closure(): (GridTreeChange|string)  $fn
     */
    private function locked(\Closure $fn): GridTreeChange|string
    {
        $lock = Cache::lock(GridPlacer::LOCK, 30);
        try {
            $lock->block(8);
        } catch (LockTimeoutException) {
            return 'busy';
        }
        try {
            return DB::transaction($fn);
        } catch (UniqueConstraintViolationException) {
            return 'slot_taken';
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $fields */
    private function log(string $wallet, string $kind, array $fields, string $actor, string $role, ?string $reason): GridTreeChange
    {
        $now = now();

        return GridTreeChange::query()->create($fields + [
            'wallet' => $wallet,
            'kind' => $kind,
            'effective_at' => $now,
            'week' => max(0, GridPlan::weekAt($now)),
            'actor' => strtolower($actor),
            'actor_role' => $role,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 300) : null,
        ]);
    }
}
