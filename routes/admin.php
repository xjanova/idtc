<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ChainController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SalesController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TxController;
use App\Http\Controllers\Admin\WalletController;
use Illuminate\Support\Facades\Route;

/*
| หลังบ้าน IDTC (ระบบควบคุมเหรียญ) — เข้าด้วยการเซ็นข้อความในกระเป๋า
| สิทธิ์: viewer ดูอย่างเดียว · admin แก้ค่าตั้ง + ใช้คำสั่งบนเชน · owner จัดการผู้ดูแล
| เซิร์ฟเวอร์ไม่ถือคีย์: คำสั่งบนเชนทุกคำสั่งเซ็นในกระเป๋าบนเบราว์เซอร์ แล้วส่ง tx hash มาบันทึก
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');

    Route::middleware('throttle:admin-auth')->group(function () {
        Route::post('auth/nonce', [AuthController::class, 'nonce'])->name('auth.nonce');
        Route::post('auth/verify', [AuthController::class, 'verify'])->name('auth.verify');
    });

    Route::middleware('admin')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('sales', [SalesController::class, 'index'])->name('sales');
        Route::get('sales/export', [SalesController::class, 'export'])->name('sales.export');
        Route::get('presale', [ChainController::class, 'presale'])->name('presale');
        Route::get('vesting', [ChainController::class, 'vesting'])->name('vesting');
        Route::get('pools', [ChainController::class, 'pools'])->name('pools');
        Route::get('wallets', [WalletController::class, 'index'])->name('wallets');
        Route::get('content', [ContentController::class, 'edit'])->name('content');
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::get('audit', [AuditController::class, 'index'])->name('audit');

        Route::middleware('admin.role:admin')->group(function () {
            Route::post('sales/sync', [SalesController::class, 'sync'])->name('sales.sync');
            Route::post('content', [ContentController::class, 'update'])->name('content.update');
            Route::post('settings/contracts', [SettingsController::class, 'contracts'])->name('settings.contracts');
            Route::post('settings/indexer', [SettingsController::class, 'indexer'])->name('settings.indexer');
            Route::post('wallets', [WalletController::class, 'update'])->name('wallets.update');
            Route::post('tx', [TxController::class, 'store'])->name('tx.store');
            Route::post('tx/{log}/check', [TxController::class, 'check'])->whereNumber('log')->name('tx.check');
        });

        Route::middleware('admin.role:owner')->group(function () {
            Route::get('admins', [AdminUserController::class, 'index'])->name('admins');
            Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
            Route::patch('admins/{admin}', [AdminUserController::class, 'update'])->whereNumber('admin')->name('admins.update');
            Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin')->name('admins.destroy');
        });
    });
});
