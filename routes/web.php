<?php

use App\Http\Controllers\BridgeController;
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
    Route::get('/trade', [PageController::class, 'trade'])->name('trade');
    Route::get('/bridge', [PageController::class, 'bridge'])->name('bridge');
    Route::get('/transparency', [PageController::class, 'transparency'])->name('transparency');
    Route::get('/whitepaper', [PageController::class, 'whitepaper'])->name('whitepaper');
    Route::get('/add-network', [PageController::class, 'addNetwork'])->name('add-network');
};

Route::middleware('site.locale:th')->group($pages);
Route::prefix('en')->name('en.')->middleware('site.locale:en')->group($pages);

// สถานะสะพาน USDT (BSC ⇄ TPIX) สำหรับหน้า /bridge — อ่านอย่างเดียว ผ่านเซิร์ฟเวอร์เพราะ tpix.online ไม่เปิด CORS ให้
Route::prefix('api/bridge')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/info', [BridgeController::class, 'info'])->name('bridge.info');
    Route::get('/deposit/{txHash}', [BridgeController::class, 'deposit'])->name('bridge.deposit');
    Route::get('/withdrawal/{txHash}', [BridgeController::class, 'withdrawal'])->name('bridge.withdrawal');
});

// ที่อยู่เดิมของเว็บเก่า (static) — ลิงก์ที่ถูกแชร์ไว้ยังพาไปหน้าที่ถูก
Route::redirect('/index.html', '/', 301);
Route::redirect('/whitepaper.html', '/whitepaper', 301);
Route::redirect('/sale.html', '/presale', 301);
Route::redirect('/dashboard.html', '/transparency', 301);

if (file_exists(__DIR__.'/admin.php')) {
    require __DIR__.'/admin.php';
}
