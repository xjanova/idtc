<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BinaryController;
use App\Http\Controllers\Admin\BinaryRoundsController;
use App\Http\Controllers\Admin\BinaryTreeController;
use App\Http\Controllers\Admin\ChainController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GridController;
use App\Http\Controllers\Admin\GridRoundsController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\SalesController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\TxController;
use App\Http\Controllers\Admin\WalletController;
use Illuminate\Support\Facades\Route;

/*
| หลังบ้าน IDTC (ระบบควบคุมเหรียญ) — เข้าด้วยการเซ็นข้อความในกระเป๋า + รหัสผ่าน (owner ต้องมีเสมอ)
| สิทธิ์รายเมนู: admin.can:<ส่วน> = ดู · admin.can:<ส่วน>,edit = แก้/สั่งงาน (App\Services\Admin\AdminPermissions)
|   owner ได้ทุกส่วน + จัดการผู้ดูแล (admin.role:owner)
| ยังไม่มีผู้ดูแลเลย: /admin/setup ให้คนแรกตั้งตัวเองเป็น owner — มีผู้ดูแลแล้ว = 404
| เซิร์ฟเวอร์ไม่ถือคีย์: คำสั่งบนเชนทุกคำสั่งเซ็นในกระเป๋าบนเบราว์เซอร์ แล้วส่ง tx hash มาบันทึก
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::get('setup', [SetupController::class, 'show'])->name('setup');

    Route::middleware('throttle:admin-auth')->group(function () {
        Route::post('auth/nonce', [AuthController::class, 'nonce'])->name('auth.nonce');
        Route::post('auth/verify', [AuthController::class, 'verify'])->name('auth.verify');
        Route::post('auth/password', [AuthController::class, 'password'])->name('auth.password');
        Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
    });

    Route::middleware('admin')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('password', [PasswordController::class, 'edit'])->name('password');
        Route::post('password', [PasswordController::class, 'update'])->middleware('throttle:admin-auth')->name('password.update');

        Route::get('/', [DashboardController::class, 'index'])->middleware('admin.can:dashboard')->name('dashboard');

        Route::middleware('admin.can:sales')->group(function () {
            Route::get('sales', [SalesController::class, 'index'])->name('sales');
            Route::get('sales/export', [SalesController::class, 'export'])->name('sales.export');
        });
        Route::post('sales/sync', [SalesController::class, 'sync'])->middleware('admin.can:sales,edit')->name('sales.sync');

        Route::get('presale', [ChainController::class, 'presale'])->middleware('admin.can:presale')->name('presale');
        Route::get('vesting', [ChainController::class, 'vesting'])->middleware('admin.can:vesting')->name('vesting');
        Route::get('pools', [ChainController::class, 'pools'])->middleware('admin.can:pools')->name('pools');

        Route::get('wallets', [WalletController::class, 'index'])->middleware('admin.can:wallets')->name('wallets');
        Route::post('wallets', [WalletController::class, 'update'])->middleware('admin.can:wallets,edit')->name('wallets.update');

        Route::get('content', [ContentController::class, 'edit'])->middleware('admin.can:content')->name('content');
        Route::post('content', [ContentController::class, 'update'])->middleware('admin.can:content,edit')->name('content.update');

        Route::get('settings', [SettingsController::class, 'edit'])->middleware('admin.can:settings')->name('settings');
        Route::middleware('admin.can:settings,edit')->group(function () {
            Route::post('settings/contracts', [SettingsController::class, 'contracts'])->name('settings.contracts');
            Route::post('settings/indexer', [SettingsController::class, 'indexer'])->name('settings.indexer');
        });

        Route::get('audit', [AuditController::class, 'index'])->middleware('admin.can:audit')->name('audit');

        // ธุรกรรมบนเชน: สิทธิ์ขึ้นกับคำสั่ง (presale/feeder → presale · vesting → vesting · rewards → grid_rounds) ตรวจใน TxController
        Route::post('tx', [TxController::class, 'store'])->name('tx.store');
        Route::post('tx/{log}/check', [TxController::class, 'check'])->whereNumber('log')->name('tx.check');

        Route::get('grid', [GridController::class, 'index'])->middleware('admin.can:grid')->name('grid');

        Route::middleware('admin.can:grid_members')->group(function () {
            Route::get('grid/members', [GridController::class, 'members'])->name('grid.members');
            Route::get('grid/members/{wallet}', [GridController::class, 'member'])->where('wallet', '0x[0-9a-fA-F]{40}')->name('grid.member');
        });
        Route::middleware('admin.can:grid_members,edit')->group(function () {
            Route::post('grid/members/{wallet}/hold', [GridController::class, 'hold'])->where('wallet', '0x[0-9a-fA-F]{40}')->name('grid.hold');
            Route::post('grid/members/{wallet}/unhold', [GridController::class, 'unhold'])->where('wallet', '0x[0-9a-fA-F]{40}')->name('grid.unhold');
        });

        Route::get('grid/map', [GridController::class, 'map'])->middleware('admin.can:grid_map')->name('grid.map');

        Route::middleware('admin.can:grid_ledger')->group(function () {
            Route::get('grid/ledger', [GridController::class, 'ledger'])->name('grid.ledger');
            Route::get('grid/ledger/export', [GridController::class, 'export'])->name('grid.export');
        });

        Route::get('grid/plan', [GridController::class, 'plan'])->middleware('admin.can:grid_plan')->name('grid.plan');
        Route::post('grid/plan', [GridController::class, 'planStore'])->middleware('admin.can:grid_plan,edit')->name('grid.plan.store');

        Route::middleware('admin.can:grid_rounds')->group(function () {
            Route::get('grid/rounds', [GridRoundsController::class, 'index'])->name('grid.rounds');
            Route::get('grid/rounds/{round}', [GridRoundsController::class, 'show'])->whereNumber('round')->name('grid.round');
            Route::get('grid/rounds/{round}/export', [GridRoundsController::class, 'export'])->whereNumber('round')->name('grid.round.export');
        });
        Route::middleware('admin.can:grid_rounds,edit')->group(function () {
            Route::post('grid/rounds', [GridRoundsController::class, 'build'])->name('grid.rounds.build');
            Route::post('grid/rounds/{round}/discard', [GridRoundsController::class, 'discard'])->whereNumber('round')->name('grid.round.discard');
        });

        // แผน Binary โปรโมชั่น (idtc.online/binary): ดู/แก้อัตรา ตามสิทธิ์ส่วน binary · กระเป๋ารับเงินและเปิด/ปิด = owner (ด้านล่าง)
        Route::get('binary', [BinaryController::class, 'index'])->middleware('admin.can:binary')->name('binary');
        // รอบจ่ายของแผน Binary: {stream} = usdt (รางวัลจับคู่) | idtc (ส่ง IDTC ที่ซื้อ)
        Route::middleware('admin.can:binary')->group(function () {
            Route::get('binary/rounds/{stream}', [BinaryRoundsController::class, 'index'])->whereIn('stream', ['usdt', 'idtc'])->name('binary.rounds');
            Route::get('binary/round/{round}', [BinaryRoundsController::class, 'show'])->whereNumber('round')->name('binary.round');
            Route::get('binary/round/{round}/export', [BinaryRoundsController::class, 'export'])->whereNumber('round')->name('binary.round.export');
        });
        Route::middleware('admin.can:binary,edit')->group(function () {
            Route::post('binary/rounds/{stream}', [BinaryRoundsController::class, 'build'])->whereIn('stream', ['usdt', 'idtc'])->name('binary.rounds.build');
            Route::post('binary/round/{round}/discard', [BinaryRoundsController::class, 'discard'])->whereNumber('round')->name('binary.round.discard');
            Route::post('binary/plan', [BinaryController::class, 'plan'])->name('binary.plan');
            Route::post('binary/sync', [BinaryController::class, 'sync'])->name('binary.sync');
            Route::post('binary/deposits/{deposit}/refund', [BinaryController::class, 'refund'])->whereNumber('deposit')->name('binary.refund');
        });

        // ผังสายงาน Binary: ดู/ตรวจ = binary · จัด/ย้าย/ต่อสาย/ตั้งวิธีวาง = binary แก้ไขได้ · เปลี่ยนผู้แนะนำ = owner (ด้านล่าง)
        Route::middleware('admin.can:binary')->group(function () {
            Route::get('binary/tree', [BinaryTreeController::class, 'index'])->name('binary.tree');
            Route::get('binary/tree/data', [BinaryTreeController::class, 'data'])->name('binary.tree.data');
            Route::get('binary/tree/lists', [BinaryTreeController::class, 'lists'])->name('binary.tree.lists');
            Route::post('binary/tree/check', [BinaryTreeController::class, 'check'])->name('binary.tree.check');
            Route::post('binary/tree/referrer/check', [BinaryTreeController::class, 'referrerCheck'])->name('binary.tree.referrer.check');
        });
        Route::middleware('admin.can:binary,edit')->group(function () {
            Route::post('binary/tree/place', [BinaryTreeController::class, 'place'])->name('binary.tree.place');
            Route::post('binary/tree/auto', [BinaryTreeController::class, 'auto'])->name('binary.tree.auto');
            Route::post('binary/tree/mode', [BinaryTreeController::class, 'mode'])->name('binary.tree.mode');
        });

        Route::middleware('admin.role:owner')->group(function () {
            Route::post('binary/settings', [BinaryController::class, 'settings'])->name('binary.settings');
            Route::post('binary/toggle', [BinaryController::class, 'toggle'])->name('binary.toggle');
            Route::post('binary/tree/referrer', [BinaryTreeController::class, 'referrer'])->name('binary.tree.referrer');
            Route::get('admins', [AdminUserController::class, 'index'])->name('admins');
            Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
            Route::post('admins/password-reset', [AdminUserController::class, 'resetPassword'])->name('admins.password.reset');
            Route::patch('admins/{admin}', [AdminUserController::class, 'update'])->whereNumber('admin')->name('admins.update');
            Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin')->name('admins.destroy');
            Route::post('grid/toggle', [GridController::class, 'toggle'])->name('grid.toggle');
        });
    });
});
