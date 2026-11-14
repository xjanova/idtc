<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Binary\BinaryLedger;
use App\Services\Binary\BinaryMap;
use App\Services\Binary\BinaryPlan;
use App\Services\Chain\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * หลังบ้าน > Binary > ผังสายงาน: ต้นไม้สองขาของแผน Binary (ดูอย่างเดียว) — หน้าของตัวเอง ไม่ใช้ผังวงของ IDTC Grid
 *
 * สิทธิ์ดู = ส่วน binary (routes/admin.php) · จัด/ย้าย/ต่อสายอยู่ที่หน้า "จัดสาย" (BinaryTreeController)
 */
class BinaryMapController extends Controller
{
    public function __construct(protected BinaryLedger $ledger, protected BinaryMap $map, protected BinaryPlan $plans) {}

    public function index(Request $request): View
    {
        $book = $this->ledger->book();
        $depth = max(1, min(BinaryMap::MAX_DEPTH, (int) $request->query('depth', 6)));
        $min = (float) $this->plans->current()['min_usdt'];

        // กระเป๋าที่ขอ: ผิดรูปแบบหรือไม่มีในแผน = แจ้งบนหน้า แล้วแสดงผังหลักแทน (เป็นช่องค้นหา ไม่ใช่ลิงก์ตายตัว)
        $asked = trim((string) $request->query('root', ''));
        $root = null;
        $missing = null;
        if ($asked !== '') {
            $w = strtolower($asked);
            if (Address::isValid($asked) && ! Address::isZero($asked) && isset($book['nodes'][$w])) {
                $root = $w;
            } else {
                $missing = mb_substr($asked, 0, 64);
            }
        }

        $me = app(AdminContext::class);

        return view('admin.binary.map', [
            'title' => 'ผังสายงาน Binary',
            'slice' => $this->map->slice($book, $root, $depth, $min),
            'asked' => $root,
            'missing' => $missing,
            'depth' => $depth,
            'min' => $min,
            'canMember' => $me->canView('grid_members'),
        ]);
    }
}
