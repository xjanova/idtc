<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\AdminSession;
use App\Services\Admin\Audit;
use App\Services\Auth\SiweAuth;
use App\Services\Auth\SiweException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ตั้งค่าครั้งแรก: ยังไม่มีผู้ดูแลเลย (IDTC_ADMIN_WALLETS ว่าง + ตาราง admins ว่าง) — คนแรกที่เซ็นกระเป๋า
 * และตั้งรหัสผ่านได้ จะเป็นเจ้าของ (owner) · เจ้าของโครงการเลือกแบบใครมาก่อนได้ก่อนเอง หลังรับทราบความเสี่ยง
 *
 * กันสองคนเป็น owner พร้อมกัน: Cache lock (ทั้งเว็บมีได้ทีละคำขอ) + DB transaction ที่ตรวจซ้ำว่ายังไม่มีผู้ดูแล
 * มีผู้ดูแลแล้ว = ทุก route ของหน้านี้ตอบ 404
 */
class SetupController extends Controller
{
    public const LOCK = 'idtc:admin-setup';

    public function show(AdminRegistry $registry): Response
    {
        abort_if($registry->hasAnyAdmin(), 404);

        return response()->view('admin.setup', ['title' => 'ตั้งค่าหลังบ้านครั้งแรก'])->withHeaders(AuthController::headers());
    }

    public function store(Request $request, SiweAuth $siwe, AdminRegistry $registry, AdminPasswords $passwords, AdminSession $session): JsonResponse
    {
        abort_if($registry->hasAnyAdmin(), 404);

        // ตรวจรหัสผ่านก่อนลายเซ็น — รหัสผ่านไม่ผ่านจะได้ไม่เสีย nonce ต้องเซ็นใหม่
        $password = $request->input('password');
        $error = $passwords->validateNew($password, $request->input('password_confirmation'));
        if ($error !== null) {
            return response()->json(['message' => $error, 'code' => 'invalid'], 422);
        }
        if (! $request->boolean('ack')) {
            return response()->json(['message' => 'ติ๊กยืนยันว่าเข้าใจว่ากระเป๋านี้จะเป็นเจ้าของหลังบ้านก่อน', 'code' => 'ack'], 422);
        }

        $text = $request->input('message');
        $signature = $request->input('signature');
        if (! is_string($text) || ! is_string($signature) || strlen($text) > 2000 || strlen($signature) > 200) {
            return response()->json(['message' => 'ข้อมูลลายเซ็นไม่ครบ กดตั้งค่าใหม่', 'code' => 'format'], 422);
        }
        try {
            $address = $siwe->verify($request, $text, $signature);
        } catch (SiweException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 422);
        }

        $lock = Cache::lock(self::LOCK, 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'มีคนกำลังตั้งค่าหลังบ้านอยู่ในขณะนี้ — รอสักครู่แล้วโหลดหน้าใหม่', 'code' => 'busy'], 409);
        }
        try {
            $created = DB::transaction(function () use ($registry, $passwords, $address, $password) {
                if ($registry->hasAnyAdmin()) {
                    return false;
                }
                Admin::query()->create(['address' => $address, 'role' => 'owner', 'label' => 'เจ้าของ (ตั้งค่าครั้งแรก)', 'added_by' => null]);
                $passwords->set($address, (string) $password);

                return true;
            });
        } finally {
            $lock->release();
        }

        if (! $created) {
            Audit::record('admins.setup_denied', ['address' => $address]);
            abort(404);
        }

        Audit::record('admins.setup', ['address' => $address, 'new' => 'owner'], new AdminContext($address, 'owner'));

        return response()->json(['redirect' => $session->admit($request, $address, 'owner')], 201);
    }
}
