<?php

namespace App\Http\Controllers;

use App\Services\Grid\GridPlan;
use App\Support\Launch;
use App\Support\SiteLocale;
use App\Support\Whitepaper;
use Illuminate\Contracts\View\View;

/**
 * หน้าสาธารณะ — ภาษามาจาก URL (middleware site.locale) ข้อความอยู่ใน lang/{th,en}/*.php
 */
class PageController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function presale(): View
    {
        return view('pages.presale', [
            'title' => __('presale.title'),
            'description' => __('presale.description', ['price' => config('idtc.sale.price_usd')]),
        ]);
    }

    public function transparency(): View
    {
        return view('pages.transparency', [
            'title' => __('transparency.title'),
            'description' => __('transparency.description'),
        ]);
    }

    public function whitepaper(): View
    {
        return view('pages.whitepaper', [
            'title' => __('whitepaper.title'),
            'description' => __('whitepaper.description'),
            'doc' => Whitepaper::render(Whitepaper::path(SiteLocale::current())),
        ]);
    }

    /** แผนรางวัล IDTC Grid แบบอ่านอย่างเดียว — ไม่ต้องเชื่อมกระเป๋า ตัวเลขทุกตัวมาจากแผนที่ใช้อยู่ */
    public function rewards(GridPlan $plans): View
    {
        $plan = $plans->current();
        $pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v * 100, 2), '0'), '.');
        $sale = config('idtc.sale');
        // tokensOut = payAmount(หน่วยเล็กสุดของ USDT) × num ÷ den (หน่วย wei ของ IDTC)
        $perUsdt = 10 ** (int) $sale['pay_decimals'] * (float) $sale['rate_num'] / (float) $sale['rate_den'] / 1e18;
        $start = Launch::at('start');
        $weekDate = static fn (int $week, int $shiftDays = 0): ?string => $start ? Launch::date($start->addDays($week * 7 + $shiftDays)) : null;

        // ช่วงอัตราของวง 1 ตามสัปดาห์ขาย: ช่วงโบนัสแต่ละช่วง + ช่วงหลังโบนัส
        $phases = [];
        foreach ($plan['boost'] as $b) {
            $phases[] = [
                'label' => __('rewards.rings.week_range', ['from' => $b['from'] + 1, 'to' => $b['to']]),
                'dates' => $start ? __('rewards.rings.dates', ['from' => $weekDate($b['from']), 'to' => $weekDate($b['to'], -1)]) : null,
                'rate' => (float) $plan['levels'][0] + (float) $b['add'],
                'add' => (float) $b['add'],
            ];
        }
        $after = $plan['boost'] === [] ? 0 : max(array_column($plan['boost'], 'to'));
        $phases[] = [
            'label' => __('rewards.rings.week_after', ['from' => $after + 1]),
            'dates' => $start ? __('rewards.rings.dates_from', ['from' => $weekDate($after)]) : null,
            'rate' => (float) $plan['levels'][0],
            'add' => 0.0,
        ];

        $alloc = GridPlan::operationsAllocation();

        return view('pages.rewards', [
            'title' => __('rewards.title'),
            'description' => __('rewards.description', [
                'budget' => SiteLocale::big($plan['budget']),
                'r1' => $pct($plan['levels'][0]), 'r2' => $pct($plan['levels'][1]), 'r3' => $pct($plan['levels'][2]),
                'hold' => $plan['hold_days'],
            ]),
            'plan' => $plan,
            'pct' => $pct,
            'phases' => $phases,
            'perUsdt' => $perUsdt,
            'alloc' => $alloc,
            'allocShare' => $alloc > 0 ? $pct((float) $plan['budget'] / $alloc) : null,
            'open' => $plans->enabled(),
        ]);
    }

    public function addNetwork(): View
    {
        return view('pages.add-network', ['title' => __('addnet.title'), 'autoAdd' => true]);
    }
}
