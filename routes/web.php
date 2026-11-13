<?php

use App\Http\Controllers\GridController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

/*
| หน้าสาธารณะของ idtc.online — สองภาษา
|   ไทย (ค่าเริ่มต้น) ที่ URL เดิม: /, /presale, ...   ชื่อ route: home, presale, ...
|   อังกฤษ ใต้ /en: /en, /en/presale, ...              ชื่อ route: en.home, en.presale, ...
| ลิงก์ใน Blade ใช้ lroute('presale') ให้ได้ภาษาเดียวกับหน้าที่เปิดอยู่ (App\Support\SiteLocale)
| หลังบ้าน (เข้าด้วยกระเป๋า) อยู่ใน routes/admin.php — ภาษาไทยอย่างเดียว
*/

$pages = function (): void {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/presale', [PageController::class, 'presale'])->name('presale');
    Route::get('/transparency', [PageController::class, 'transparency'])->name('transparency');
    Route::get('/whitepaper', [PageController::class, 'whitepaper'])->name('whitepaper');
    Route::get('/add-network', [PageController::class, 'addNetwork'])->name('add-network');
    Route::get('/rewards', [PageController::class, 'rewards'])->name('rewards');

    // IDTC Grid — ปิดอยู่ (หลังบ้าน) = 404 · API อยู่ใต้ภาษาของหน้า ข้อความตอบกลับจึงเป็นภาษาเดียวกับหน้า
    Route::get('/grid', [GridController::class, 'page'])->name('grid');
    // /r/{wallet} = ลิงก์ชวน · /r/{wallet}/l|r = ระบุขาในผัง Binary (ซ้าย/ขวา)
    Route::get('/r/{wallet}/{side?}', [GridController::class, 'ref'])->where('wallet', '0x[0-9a-fA-F]{40}')->where('side', '[lrLR]')->name('ref');
    Route::get('/grid/me', [GridController::class, 'me'])->name('grid.me');
    Route::post('/grid/logout', [GridController::class, 'logout'])->name('grid.logout');
    Route::middleware('throttle:grid-auth')->group(function () {
        Route::post('/grid/auth/nonce', [GridController::class, 'nonce'])->name('grid.nonce');
        Route::post('/grid/auth/verify', [GridController::class, 'verify'])->name('grid.verify');
        Route::post('/grid/side', [GridController::class, 'side'])->name('grid.side');
    });
};

Route::middleware('site.locale:th')->group($pages);
Route::prefix('en')->name('en.')->middleware('site.locale:en')->group($pages);

// ที่อยู่เดิมของเว็บเก่า (static) — ลิงก์ที่ถูกแชร์ไว้ยังพาไปหน้าที่ถูก
Route::redirect('/index.html', '/', 301);
Route::redirect('/whitepaper.html', '/whitepaper', 301);
Route::redirect('/sale.html', '/presale', 301);
Route::redirect('/dashboard.html', '/transparency', 301);

if (file_exists(__DIR__.'/admin.php')) {
    require __DIR__.'/admin.php';
}
