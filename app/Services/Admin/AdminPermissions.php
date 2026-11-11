<?php

namespace App\Services\Admin;

/**
 * สิทธิ์รายเมนูของหลังบ้าน: แต่ละส่วนเป็น ไม่มีสิทธิ์ · view (ดู) · edit (ดู + แก้/สั่งงาน)
 *
 * owner ได้ทุกอย่างเสมอ (ไม่อ่านค่าที่ติ๊ก) · viewer/admin เป็นชุดสิทธิ์สำเร็จรูปที่เติมช่องติ๊กให้
 * ส่วนที่ไม่มีอะไรให้แก้ (READ_ONLY) มีแค่ view — ติ๊ก edit มาก็เก็บเป็น view
 */
final class AdminPermissions
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    /** @var array<string, array{label: string, group: string, route: string}> */
    public const SECTIONS = [
        'dashboard' => ['label' => 'ภาพรวม', 'group' => 'ทั่วไป', 'route' => 'admin.dashboard'],
        'sales' => ['label' => 'ยอดขาย', 'group' => 'ทั่วไป', 'route' => 'admin.sales'],
        'presale' => ['label' => 'Presale', 'group' => 'ควบคุมเหรียญ', 'route' => 'admin.presale'],
        'vesting' => ['label' => 'สัญญาล็อก', 'group' => 'ควบคุมเหรียญ', 'route' => 'admin.vesting'],
        'pools' => ['label' => 'พูลสภาพคล่อง', 'group' => 'ควบคุมเหรียญ', 'route' => 'admin.pools'],
        'wallets' => ['label' => 'กระเป๋าโครงการ', 'group' => 'ควบคุมเหรียญ', 'route' => 'admin.wallets'],
        'grid' => ['label' => 'ภาพรวม Grid', 'group' => 'IDTC Grid', 'route' => 'admin.grid'],
        'grid_members' => ['label' => 'สมาชิกและสายงาน', 'group' => 'IDTC Grid', 'route' => 'admin.grid.members'],
        'grid_map' => ['label' => 'ผังสายงาน', 'group' => 'IDTC Grid', 'route' => 'admin.grid.map'],
        'grid_ledger' => ['label' => 'รางวัลรายการซื้อ', 'group' => 'IDTC Grid', 'route' => 'admin.grid.ledger'],
        'grid_plan' => ['label' => 'แผนรางวัล', 'group' => 'IDTC Grid', 'route' => 'admin.grid.plan'],
        'grid_rounds' => ['label' => 'รอบจ่ายรางวัล', 'group' => 'IDTC Grid', 'route' => 'admin.grid.rounds'],
        'content' => ['label' => 'เนื้อหาและประกาศ', 'group' => 'เว็บไซต์', 'route' => 'admin.content'],
        'settings' => ['label' => 'ตั้งค่าเหรียญ', 'group' => 'เว็บไซต์', 'route' => 'admin.settings'],
        'audit' => ['label' => 'บันทึกการทำงาน', 'group' => 'เว็บไซต์', 'route' => 'admin.audit'],
    ];

    /** ส่วนที่มีแต่การดู ไม่มีปุ่มแก้/คำสั่ง (เปิด-ปิด Grid เป็นของ owner เท่านั้น) */
    public const READ_ONLY = ['dashboard', 'pools', 'grid', 'grid_map', 'grid_ledger', 'audit'];

    /** @return array<string, string> section => view|edit ครบทุกส่วน */
    public static function full(): array
    {
        $out = [];
        foreach (array_keys(self::SECTIONS) as $s) {
            $out[$s] = self::editable($s) ? self::EDIT : self::VIEW;
        }

        return $out;
    }

    /**
     * ชุดสิทธิ์สำเร็จรูปตาม role: viewer = ดูทุกส่วน · admin = ดู + แก้ทุกส่วน · owner = ทุกอย่าง
     *
     * @return array<string, string>
     */
    public static function preset(string $role): array
    {
        return match ($role) {
            'owner', 'admin' => self::full(),
            'viewer' => array_fill_keys(array_keys(self::SECTIONS), self::VIEW),
            default => [],
        };
    }

    public static function editable(string $section): bool
    {
        return isset(self::SECTIONS[$section]) && ! in_array($section, self::READ_ONLY, true);
    }

    /**
     * ทำความสะอาดค่าที่ส่งมา — รับทั้ง ['sales' => 'edit'] และรูปแบบฟอร์ม ['sales' => ['view' => '1', 'edit' => '1']]
     * ส่วน/ระดับที่ไม่รู้จักถูกทิ้ง · edit แปลว่าดูได้ด้วยเสมอ · ส่วน READ_ONLY ลดเป็น view
     *
     * @return array<string, string>
     */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_keys(self::SECTIONS) as $s) {
            $v = $input[$s] ?? null;
            $level = match (true) {
                $v === self::EDIT, is_array($v) && ! empty($v['edit']) => self::EDIT,
                $v === self::VIEW, is_array($v) && ! empty($v['view']) => self::VIEW,
                default => null,
            };
            if ($level === self::EDIT && ! self::editable($s)) {
                $level = self::VIEW;
            }
            if ($level !== null) {
                $out[$s] = $level;
            }
        }

        return $out;
    }

    /** @param array<string, string> $perms */
    public static function hasAnyEdit(array $perms): bool
    {
        return in_array(self::EDIT, $perms, true);
    }

    /**
     * role ที่เก็บคู่กับสิทธิ์ที่ติ๊กเอง (ใช้แสดงผล) — มีสิทธิ์แก้สักส่วน = admin ไม่มี = viewer
     *
     * @param  array<string, string>  $perms
     */
    public static function roleFor(array $perms): string
    {
        return self::hasAnyEdit($perms) ? 'admin' : 'viewer';
    }

    /** @param array<string, string> $perms */
    public static function isPreset(array $perms, string $role): bool
    {
        $preset = self::preset($role);
        ksort($preset);
        ksort($perms);

        return $preset === $perms;
    }
}
