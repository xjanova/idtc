<?php

namespace App\Http\Controllers;

use App\Support\Whitepaper;
use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function presale(): View
    {
        return view('pages.presale', [
            'title' => 'Presale',
            'description' => 'ซื้อ IDTC ราคาคงที่ $'.config('idtc.sale.price_usd').' ด้วย USDT บนเชน TPIX ได้เหรียญทันทีในธุรกรรมเดียว ค่าแก๊ส 0',
        ]);
    }

    public function transparency(): View
    {
        return view('pages.transparency', [
            'title' => 'ความโปร่งใส',
            'description' => 'ดูยอด IDTC ที่ล็อกในสัญญา TrancheVesting งวดปลดถัดไป และสัญญาทั้งหมดของ IDTC อ่านสดจากเชน TPIX',
        ]);
    }

    public function whitepaper(): View
    {
        $path = (string) config('idtc.whitepaper_path', resource_path('content/whitepaper.th.md'));

        return view('pages.whitepaper', [
            'title' => 'ไวท์เปเปอร์',
            'description' => 'IDTC Whitepaper ฉบับที่ 2 — tokenomics การล็อก 70% presale ราคาคงที่ และที่อยู่สัญญาทั้งหมดบนเชน TPIX',
            'doc' => Whitepaper::render($path),
        ]);
    }

    public function addNetwork(): View
    {
        return view('pages.add-network', ['title' => 'เพิ่มเชน TPIX และเหรียญ IDTC', 'autoAdd' => true]);
    }
}
