<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function presale(): View
    {
        return view('pages.presale', ['title' => 'Presale']);
    }

    public function transparency(): View
    {
        return view('pages.transparency', ['title' => 'ความโปร่งใส']);
    }

    public function whitepaper(): View
    {
        return view('pages.whitepaper', ['title' => 'ไวท์เปเปอร์']);
    }

    public function addNetwork(): View
    {
        return view('pages.add-network', ['title' => 'เพิ่มเชน TPIX และเหรียญ IDTC', 'autoAdd' => true]);
    }
}
