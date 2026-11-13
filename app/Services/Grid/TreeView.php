<?php

namespace App\Services\Grid;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Models\GridTreeChange;
use App\Services\Binary\BinaryPlan;
use App\Services\Chain\Address;
use App\Support\Idtc;
use Carbon\CarbonImmutable;

/**
 * ข้อมูลผัง Binary สำหรับหน้าจอ (หลังบ้าน /admin/binary/tree และผังทีมของแม่ทีมบน /binary)
 *
 * window(): ราก + $depth ชั้นลงไป เป็นแถวละ 2^i ช่อง — ช่องหนึ่งเป็น null (ไม่มีอะไร), ช่องว่างที่วางได้ ({empty, parent, leg}) หรือสมาชิก
 * ตัวเลขยอดมาจาก BinaryLedger::book() (USDT ของแผน Binary) · ผังเป็นผังปัจจุบัน
 */
final class TreeView
{
    public function __construct(protected GridPlacer $placer, protected BinaryPlan $plans) {}

    /**
     * @param  array<string, mixed>  $book  BinaryLedger::book()
     * @param  string|null  $scope  แม่ทีม — ขึ้นบนได้ไม่เกินตัวเอง และแสดงเฉพาะทีมตัวเอง
     * @return array<string, mixed>|null null = rootไม่อยู่ในขอบเขต
     */
    public function window(string $root, int $depth, array $book, ?string $scope = null): ?array
    {
        $root = strtolower($root);
        $slots = $this->placer->slots();
        $parentOf = [];
        foreach ($slots as $p => $kids) {
            foreach ($kids as $leg => $c) {
                $parentOf[$c] = [$p, $leg];
            }
        }
        if ($scope !== null) {
            $scope = strtolower($scope);
            if (! isset(GridPlacer::team($slots, $scope)[$root])) {
                return null;
            }
        }

        // แถวของผัง (ความกว้างคงที่ 2^i)
        $levels = [[$root]];
        for ($i = 1; $i <= $depth; $i++) {
            $row = [];
            foreach ($levels[$i - 1] as $cell) {
                foreach ([GridPlacer::LEFT, GridPlacer::RIGHT] as $leg) {
                    if (is_string($cell)) {
                        $row[] = $slots[$cell][$leg] ?? ['empty' => true, 'parent' => $cell, 'leg' => $leg];
                    } else {
                        $row[] = null;
                    }
                }
            }
            $levels[] = $row;
        }
        $wallets = [];
        foreach ($levels as $row) {
            foreach ($row as $cell) {
                if (is_string($cell)) {
                    $wallets[] = $cell;
                }
            }
        }
        $rows = GridMember::query()->whereIn('wallet', $wallets)->get()->keyBy('wallet');
        $waiting = GridMember::query()->whereIn('referrer', $wallets)->whereNotNull('bin_wait_at')->whereNull('bin_parent')
            ->selectRaw('referrer, count(*) as n')->groupBy('referrer')->pluck('n', 'referrer');
        $min = (float) $this->plans->current()['min_usdt'];

        $levels = array_map(fn ($row) => array_map(function ($cell) use ($book, $rows, $waiting, $min, $parentOf, $slots, $scope) {
            if (! is_string($cell)) {
                return $cell === null ? null : ['empty' => true, 'parent' => Address::checksum($cell['parent']), 'leg' => $cell['leg']];
            }

            return $this->node($cell, $book['nodes'][$cell] ?? [], $rows[$cell] ?? null, (int) ($waiting[$cell] ?? 0), $min, $parentOf, $slots, $scope);
        }, $row), $levels);

        // ทางขึ้น: คนเหนือรากจนถึงบนสุด (แม่ทีม: ถึงตัวเอง)
        $crumbs = [];
        $cur = $root;
        for ($i = 0; $i < 200 && isset($parentOf[$cur]) && $cur !== $scope; $i++) {
            $cur = $parentOf[$cur][0];
            $crumbs[] = ['wallet' => Address::checksum($cur), 'short' => Idtc::short($cur)];
        }
        $edge = [];
        foreach ([GridPlacer::LEFT, GridPlacer::RIGHT] as $leg) {
            $cur = $root;
            for ($i = 0; $i < GridPlacer::MAX_WALK && isset($slots[$cur][$leg]); $i++) {
                $cur = $slots[$cur][$leg];
            }
            $edge[$leg] = $cur !== $root ? Address::checksum($cur) : null;
        }

        return [
            'root' => Address::checksum($root),
            'up' => $crumbs !== [] ? $crumbs[0]['wallet'] : null,
            'crumbs' => array_reverse($crumbs),
            'edge' => $edge,
            'levels' => $levels,
        ];
    }

    /**
     * คิวรอจัดสาย (ทั้งระบบ หรือเฉพาะคนที่ $sponsor ชวน)
     *
     * @param  array<string, mixed>  $book
     * @return list<array<string, mixed>>
     */
    public function queue(array $book, ?string $sponsor = null): array
    {
        $weekEnd = GridPlan::weekStart(max(0, GridPlan::weekAt(now())) + 1)->getTimestamp();

        return GridMember::query()->whereNotNull('bin_wait_at')->whereNull('bin_parent')
            ->when($sponsor !== null, fn ($q) => $q->where('referrer', strtolower((string) $sponsor)))
            ->orderBy('bin_wait_at')->limit(500)->get()
            ->map(fn (GridMember $m) => [
                'wallet' => Address::checksum($m->wallet),
                'short' => Idtc::short($m->wallet),
                'referrer' => $m->referrer ? Address::checksum($m->referrer) : null,
                'referrer_short' => $m->referrer ? Idtc::short($m->referrer) : null,
                'since' => $m->bin_wait_at?->getTimestamp(),
                'side' => $m->bin_wait_side,
                'usdt' => (float) ($book['nodes'][$m->wallet]['usdt'] ?? 0.0),
                // จัดก่อนสัปดาห์นี้ปิด = ยอดของสัปดาห์นี้นับให้สายบนครบ
                'week_ends' => $weekEnd,
            ])->values()->all();
    }

    /**
     * คนที่ยังไม่อยู่ในผัง — ต่อสายได้: บนสุดของผังแยก (มีทีม) และกระเป๋าเดี่ยวที่ฝาก/เป็นสมาชิกแต่ไม่มีตำแหน่ง
     *
     * @param  array<string, mixed>  $book
     * @return list<array<string, mixed>>
     */
    public function outside(array $book): array
    {
        $slots = $this->placer->slots();
        $placed = GridMember::query()->whereNotNull('bin_parent')->pluck('wallet')->flip();
        // คนในคิวรอจัดสายแสดงในคิวอยู่แล้ว — ไม่นับซ้ำในรายการนอกผัง
        $waiting = GridMember::query()->whereNotNull('bin_wait_at')->whereNull('bin_parent')->pluck('wallet')->flip();
        $out = [];
        $add = function (string $w, int $team) use (&$out, $book) {
            $n = $book['nodes'][$w] ?? [];
            $out[$w] = [
                'wallet' => Address::checksum($w), 'short' => Idtc::short($w), 'team' => $team,
                'usdt' => (float) ($n['usdt'] ?? 0.0), 'referrer' => isset($n['referrer']) && $n['referrer'] ? Address::checksum($n['referrer']) : null,
            ];
        };
        foreach (array_keys($slots) as $p) {
            if (! isset($placed[$p]) && ! isset($waiting[$p])) {
                $add($p, count(GridPlacer::team($slots, $p)));
            }
        }
        $singles = GridMember::query()->whereNull('bin_parent')->whereNull('bin_wait_at')->whereNotIn('wallet', array_keys($slots))->limit(300)->pluck('wallet')
            ->merge(BinaryDeposit::query()->where('status', BinaryDeposit::OK)->distinct()->limit(300)->pluck('wallet'));
        foreach ($singles as $w) {
            if (! isset($out[$w]) && ! isset($placed[$w]) && ! isset($slots[$w]) && ! isset($waiting[$w])) {
                $add($w, 1);
            }
        }
        usort($out, fn ($a, $b) => $b['team'] <=> $a['team'] ?: $b['usdt'] <=> $a['usdt']);

        return array_slice($out, 0, 300);
    }

    /** @return list<array<string, mixed>> ประวัติล่าสุด */
    public function history(int $limit = 100, ?string $wallet = null): array
    {
        $c = fn (?string $a) => $a ? Address::checksum($a) : null;

        return GridTreeChange::query()->when($wallet !== null, fn ($q) => $q->where('wallet', strtolower((string) $wallet)))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (GridTreeChange $h) => [
                'id' => $h->id, 'kind' => $h->kind, 'wallet' => $c($h->wallet), 'short' => Idtc::short($h->wallet),
                'from_parent' => $c($h->from_parent), 'from_leg' => $h->from_leg, 'to_parent' => $c($h->to_parent), 'to_leg' => $h->to_leg,
                'from_referrer' => $c($h->from_referrer), 'to_referrer' => $c($h->to_referrer),
                'week' => $h->week + 1, 'team' => $h->team, 'actor' => $c($h->actor), 'role' => $h->actor_role, 'reason' => $h->reason,
                'at' => CarbonImmutable::instance($h->effective_at)->getTimestamp(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $n  โหนดจาก BinaryLedger
     * @param  array<string, array{0: string, 1: string}>  $parentOf
     * @param  array<string, array<string, string>>  $slots
     * @return array<string, mixed>
     */
    private function node(string $w, array $n, ?GridMember $row, int $waiting, float $min, array $parentOf, array $slots, ?string $scope): array
    {
        $ref = $row?->referrer;
        $usdt = (float) ($n['usdt'] ?? 0.0);

        return [
            'wallet' => Address::checksum($w),
            'short' => Idtc::short($w),
            'parent' => isset($parentOf[$w]) ? Address::checksum($parentOf[$w][0]) : null,
            'leg' => $parentOf[$w][1] ?? null,
            'referrer' => $ref ? Address::checksum($ref) : null,
            'referrer_short' => $ref ? Idtc::short($ref) : null,
            // ผู้แนะนำอยู่เหนือในผังตรง ๆ / เป็นคนที่แม่ทีมชวนเอง
            'under_sponsor' => $ref !== null && ($parentOf[$w][0] ?? null) === $ref,
            'direct' => $scope !== null && $ref === $scope,
            'usdt' => $usdt,
            'active' => $usdt > 0 && $usdt + 1e-9 >= $min,
            'nl' => (int) ($n['nl'] ?? 0), 'nr' => (int) ($n['nr'] ?? 0),
            'l' => (float) ($n['l'] ?? 0.0), 'r' => (float) ($n['r'] ?? 0.0),
            'cl' => (float) ($n['cl'] ?? 0.0), 'cr' => (float) ($n['cr'] ?? 0.0),
            'dl' => (int) ($n['dl'] ?? 0), 'dr' => (int) ($n['dr'] ?? 0),
            'q' => (bool) ($n['q'] ?? false),
            'held' => $row?->held_at !== null,
            'mode' => GridPlacer::modeOf($row),
            'waiting' => $waiting,
            'kids' => count($slots[$w] ?? []),
            'placed_at' => $row?->bin_placed_at?->getTimestamp(),
        ];
    }
}
