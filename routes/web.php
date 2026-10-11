<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

/*
| หน้าสาธารณะของ idtc.online
| หลังบ้าน (เข้าด้วยกระเป๋า) อยู่ใน routes/admin.php
*/

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/presale', [PageController::class, 'presale'])->name('presale');
Route::get('/transparency', [PageController::class, 'transparency'])->name('transparency');
Route::get('/whitepaper', [PageController::class, 'whitepaper'])->name('whitepaper');
Route::get('/add-network', [PageController::class, 'addNetwork'])->name('add-network');

// ที่อยู่เดิมของเว็บเก่า (static) — ลิงก์ที่ถูกแชร์ไว้ยังพาไปหน้าที่ถูก
Route::redirect('/index.html', '/', 301);
Route::redirect('/whitepaper.html', '/whitepaper', 301);
Route::redirect('/sale.html', '/presale', 301);
Route::redirect('/dashboard.html', '/transparency', 301);

if (file_exists(__DIR__.'/admin.php')) {
    require __DIR__.'/admin.php';
}
