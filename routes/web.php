<?php

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
