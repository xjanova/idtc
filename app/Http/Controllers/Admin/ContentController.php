<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * เนื้อหาและประกาศ: แถบประกาศบนหน้าเว็บสาธารณะ + ลิงก์โซเชียล
 */
class ContentController extends Controller
{
    public const SOCIAL = [
        'telegram' => 'Telegram',
        'line' => 'LINE OpenChat',
        'facebook' => 'Facebook',
        'x' => 'X (Twitter)',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'discord' => 'Discord',
    ];

    public const ANNOUNCEMENT_MAX = 240;

    public function edit(Settings $settings): View
    {
        return view('admin.content', [
            'title' => 'เนื้อหาและประกาศ',
            'announcement' => (string) $settings->get('announcement', ''),
            'announcementEn' => (string) $settings->get('announcement_en', ''),
            'social' => $settings->social(),
            'networks' => self::SOCIAL,
            'max' => self::ANNOUNCEMENT_MAX,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $section = $request->input('section');
        $by = app(AdminContext::class)->address;

        if ($section === 'announcement') {
            $v = Validator::make($request->all(), [
                'announcement' => ['nullable', 'string', 'max:'.self::ANNOUNCEMENT_MAX],
                'announcement_en' => ['nullable', 'string', 'max:'.self::ANNOUNCEMENT_MAX],
            ], [
                'announcement.max' => 'ประกาศยาวได้ไม่เกิน '.self::ANNOUNCEMENT_MAX.' ตัวอักษร',
                'announcement.string' => 'ประกาศต้องเป็นข้อความ',
                'announcement_en.max' => 'ประกาศภาษาอังกฤษยาวได้ไม่เกิน '.self::ANNOUNCEMENT_MAX.' ตัวอักษร',
                'announcement_en.string' => 'ประกาศภาษาอังกฤษต้องเป็นข้อความ',
            ]);
            if ($v->fails()) {
                return back()->withErrors($v)->withInput()->with('error', $v->errors()->first());
            }
            // ข้อความล้วน บรรทัดเดียว — layout แสดงด้วย {{ }} อยู่แล้ว (escape HTML)
            $clean = fn (string $field) => trim(preg_replace('/\s+/u', ' ', (string) $request->input($field, '')) ?? '');
            $text = $clean('announcement');
            [$old] = $settings->put('announcement', $text !== '' ? $text : null, $by);
            Audit::change('content.announcement', $old, $text !== '' ? $text : null);
            // หน้าภาษาอังกฤษ (/en) แสดงเฉพาะประกาศภาษาอังกฤษ — เว้นว่าง = หน้าอังกฤษไม่มีแถบประกาศ
            // (ฟอร์มเก่าที่ไม่ส่งช่องนี้มา ไม่ลบค่าเดิม)
            if ($request->has('announcement_en')) {
                $textEn = $clean('announcement_en');
                [$oldEn] = $settings->put('announcement_en', $textEn !== '' ? $textEn : null, $by);
                if ($oldEn !== ($textEn !== '' ? $textEn : null)) {
                    Audit::change('content.announcement_en', $oldEn, $textEn !== '' ? $textEn : null);
                }
            }

            return back()->with('status', $text !== '' ? 'บันทึกประกาศแล้ว แสดงบนหน้าเว็บทันที' : 'ปิดแถบประกาศแล้ว');
        }

        if ($section === 'social') {
            $rules = $messages = [];
            foreach (self::SOCIAL as $k => $name) {
                $rules["social.{$k}"] = ['nullable', 'string', 'max:300', 'url:https'];
                $messages["social.{$k}.url"] = "ลิงก์ {$name} ต้องเป็น URL ที่ขึ้นต้นด้วย https://";
                $messages["social.{$k}.max"] = "ลิงก์ {$name} ยาวเกินไป";
            }
            $v = Validator::make($request->all(), $rules, $messages);
            if ($v->fails()) {
                return back()->withErrors($v)->withInput()->with('error', $v->errors()->first());
            }
            $new = [];
            foreach (self::SOCIAL as $k => $_) {
                $url = trim((string) $request->input("social.{$k}", ''));
                if ($url !== '') {
                    $new[$k] = $url;
                }
            }
            [$old] = $settings->put('social', $new, $by);
            Audit::change('content.social', $old ?: [], $new);

            return back()->with('status', 'บันทึกลิงก์โซเชียลแล้ว');
        }

        return back()->with('error', 'ไม่รู้จักส่วนที่จะบันทึก');
    }
}
