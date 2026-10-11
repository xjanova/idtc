<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * แปลงไวท์เปเปอร์ (Markdown ใน resources/content) เป็น HTML สำหรับหน้า /whitepaper
 *
 * เนื้อหาไม่ถูกแก้ — แค่แทน {{contracts_table}} ด้วยตารางสัญญาจริง ใส่ id ให้หัวข้อ h2 (ทำสารบัญ)
 * และห่อตารางด้วยกล่องเลื่อนแนวนอนสำหรับจอเล็ก
 */
class Whitepaper
{
    public const PLACEHOLDER = '{{contracts_table}}';

    /** @return array{title: string, html: string, toc: list<array{id: string, num: string, text: string}>}|null */
    public static function render(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $markdown = (string) file_get_contents($path);
        if (trim($markdown) === '') {
            return null;
        }

        // หัวเรื่อง (# บรรทัดแรก) ไปแสดงบนปก ไม่ซ้ำในเนื้อหา
        $title = 'IDTC Whitepaper';
        if (preg_match('/^\#\s+(.+)$/m', $markdown, $m, PREG_OFFSET_CAPTURE)) {
            $title = trim($m[1][0]);
            $markdown = substr_replace($markdown, '', $m[0][1], strlen($m[0][0]));
        }

        $markdown = str_replace(static::PLACEHOLDER, ContractList::markdownTable(), $markdown);

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $toc = [];
        $used = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/su', function (array $h) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($h[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $num = preg_match('/^(\d+)\.\s*/u', $text, $n) ? $n[1] : '';
            $label = $num !== '' ? trim(substr($text, strlen($n[0]))) : $text;
            $id = $num !== '' ? 'section-'.$num : 'section-'.(count($toc) + 1);
            while (isset($used[$id])) {
                $id .= '-x';
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'num' => $num, 'text' => $label];

            return '<h2 id="'.$id.'">'.$h[1].'</h2>';
        }, $html) ?? $html;

        $html = str_replace(['<table>', '</table>'], ['<div class="wp-table"><table>', '</table></div>'], $html);
        // ลิงก์ออกนอกเว็บเปิดแท็บใหม่
        $html = preg_replace('/<a href="(https?:\/\/[^"]+)">/u', '<a href="$1" target="_blank" rel="noopener">', $html) ?? $html;

        return ['title' => $title, 'html' => $html, 'toc' => $toc];
    }
}
