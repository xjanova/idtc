<?php

namespace App\Services\Admin;

use App\Models\Purchase;
use App\Support\AdminFormat;
use App\Support\Idtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * ตัวเลขยอดขายจากตาราง purchases (ที่ดึงจากเชนแล้ว) — วันตัดตามเวลาไทย
 */
class SalesStats
{
    /** @return Builder<Purchase> */
    public function query(): Builder
    {
        $q = Purchase::query();
        $presale = Idtc::contract('presale');
        if ($presale !== null) {
            $q->where('contract', strtolower($presale));
        }

        return $q;
    }

    public function todayStart(): CarbonImmutable
    {
        return CarbonImmutable::now(AdminFormat::TZ)->startOfDay();
    }

    /** @return array{count: int, usdt: float, idtc: float} */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->query()
            ->where('block_time', '>=', $from->utc())
            ->where('block_time', '<', $to->utc())
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(pay_usdt), 0) as u, COALESCE(SUM(tokens_idtc), 0) as t')
            ->first();

        return ['count' => (int) ($row->c ?? 0), 'usdt' => (float) ($row->u ?? 0), 'idtc' => (float) ($row->t ?? 0)];
    }

    /** @return array{count: int, usdt: float, idtc: float, buyers: int} */
    public function totals(): array
    {
        $row = $this->query()
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(pay_usdt), 0) as u, COALESCE(SUM(tokens_idtc), 0) as t, COUNT(DISTINCT buyer) as b')
            ->first();

        return ['count' => (int) ($row->c ?? 0), 'usdt' => (float) ($row->u ?? 0), 'idtc' => (float) ($row->t ?? 0), 'buyers' => (int) ($row->b ?? 0)];
    }

    /** ผู้ซื้อที่ซื้อครั้งแรกวันนี้ */
    public function newBuyersSince(CarbonImmutable $since): int
    {
        return $this->query()
            ->selectRaw('buyer, MIN(block_time) as first_at')
            ->groupBy('buyer')
            ->havingRaw('MIN(block_time) >= ?', [$since->utc()->format('Y-m-d H:i:s')])
            ->get()
            ->count();
    }

    /**
     * ยอดขายรายวัน (USDT) ย้อนหลัง $days วัน รวมวันนี้ — วันที่ไม่มียอด = 0
     *
     * @return list<array{date: string, label: string, usdt: float, idtc: float, count: int}>
     */
    public function series(int $days): array
    {
        $start = $this->todayStart()->subDays($days - 1);
        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->addDays($i);
            $buckets[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'label' => AdminFormat::dayMonth($d), 'usdt' => 0.0, 'idtc' => 0.0, 'count' => 0];
        }

        $this->query()
            ->where('block_time', '>=', $start->utc())
            ->select(['block_time', 'pay_usdt', 'tokens_idtc'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$buckets) {
                foreach ($rows as $row) {
                    $key = $row->block_time?->setTimezone(AdminFormat::TZ)->format('Y-m-d');
                    if ($key !== null && isset($buckets[$key])) {
                        $buckets[$key]['usdt'] += $row->pay_usdt;
                        $buckets[$key]['idtc'] += $row->tokens_idtc;
                        $buckets[$key]['count']++;
                    }
                }
            });

        return array_values($buckets);
    }
}
