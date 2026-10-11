<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * ค่าตั้งจากหลังบ้าน (ตาราง settings) — แคชทั้งชุดไว้ ล้างทุกครั้งที่บันทึก
 *
 * คีย์ที่ใช้:
 *   announcement        ข้อความประกาศบนหน้าเว็บภาษาไทย (ว่าง = ไม่แสดง)
 *   announcement_en     ข้อความประกาศบนหน้าเว็บภาษาอังกฤษ (ว่าง = หน้าอังกฤษไม่แสดงแถบประกาศ)
 *   social              ['telegram' => url, ...]
 *   project_wallets     [['label' => ..., 'address' => ...], ...]
 *   indexer.start_block บล็อกเริ่มดึงยอด presale
 *   contracts           ['token' => 0x.., 'vesting' => .., 'presale' => .., 'pair_usdt' => .., 'pair_tpix' => ..]
 */
class Settings
{
    public const CACHE_KEY = 'idtc:settings:v1';

    /** ที่อยู่สัญญาที่หลังบ้านแก้ทับได้ (ที่เหลือใน config/idtc.php เป็นของเชน TPIX ที่ไม่เปลี่ยน) */
    public const CONTRACT_KEYS = ['token', 'vesting', 'presale', 'pair_usdt', 'pair_tpix'];

    /** @var array<string, mixed>|null */
    protected ?array $memo = null;

    /** จำไว้ในหน่วยความจำของ process ได้นานเท่านี้ (กัน process ที่รันนานเห็นค่าเก่า) */
    protected const MEMO_SECONDS = 10;

    protected int $memoAt = 0;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->memo !== null && time() - $this->memoAt < self::MEMO_SECONDS) {
            return $this->memo;
        }
        $this->memoAt = time();

        try {
            return $this->memo = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
                ->get(['key', 'value'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => $s->value])
                ->all());
        } catch (Throwable) {
            // ยังไม่ได้ migrate หรือฐานข้อมูลอ่านไม่ได้ — หน้าเว็บสาธารณะต้องยังเปิดได้ด้วยค่าใน config
            return $this->memo = [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    /**
     * บันทึกค่า แล้วคืน [ค่าเดิม, ค่าใหม่] ไว้ลงบันทึกการทำงาน
     *
     * @return array{0: mixed, 1: mixed}
     */
    public function put(string $key, mixed $value, ?string $by = null): array
    {
        $old = Setting::query()->find($key)?->value;
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by]);
        $this->flush();

        return [$old, $value];
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = null;
    }

    /** @return array<string, string> ที่อยู่สัญญาที่แอดมินตั้งไว้ (เฉพาะที่ถูกรูปแบบ) */
    public function contractOverrides(): array
    {
        $contracts = $this->get('contracts', []);
        if (! is_array($contracts)) {
            return [];
        }

        return array_filter(
            array_intersect_key($contracts, array_flip(self::CONTRACT_KEYS)),
            fn ($v) => Idtc::isAddress($v),
        );
    }

    /** ประกาศของหน้าภาษาที่ระบุ — หน้าภาษาอังกฤษใช้ announcement_en (ว่าง = ไม่แสดง ไม่เอาข้อความไทยไปแสดงแทน) */
    public function announcement(string $locale = SiteLocale::DEFAULT): ?string
    {
        $key = $locale === SiteLocale::DEFAULT ? 'announcement' : 'announcement_'.$locale;
        $text = trim((string) $this->get($key, ''));

        return $text !== '' ? $text : null;
    }

    /** @return array<string, string> */
    public function social(): array
    {
        $social = $this->get('social', []);

        return is_array($social) ? array_filter($social, fn ($v) => is_string($v) && $v !== '') : [];
    }

    /** @return list<array{label: string, address: string}> */
    public function projectWallets(): array
    {
        $list = $this->get('project_wallets', []);
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, fn ($w) => is_array($w) && Idtc::isAddress($w['address'] ?? null)));
    }

    public function indexerStartBlock(): ?int
    {
        $v = $this->get('indexer.start_block');

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : null;
    }
}
