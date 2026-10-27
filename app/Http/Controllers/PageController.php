<?php

namespace App\Http\Controllers;

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

    public function trade(): View
    {
        return view('pages.trade', [
            'title' => __('trade.title'),
            'description' => __('trade.description'),
        ]);
    }

    public function bridge(): View
    {
        return view('pages.bridge', [
            'title' => __('bridge.title'),
            'description' => __('bridge.description'),
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

    public function addNetwork(): View
    {
        return view('pages.add-network', ['title' => __('addnet.title'), 'autoAdd' => true]);
    }
}
