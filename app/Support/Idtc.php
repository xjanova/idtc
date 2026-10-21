<?php

namespace App\Support;

/**
 * ทางเข้าเดียวของค่าเหรียญ IDTC — ทั้ง Blade, controller และ JavaScript (ผ่าน publicConfig)
 *
 * ที่อยู่สัญญามาจาก config/idtc.php เป็นค่าเริ่มต้น แอดมินแก้ทับได้จากหลังบ้าน
 * (ส่วนหลังบ้านลงทะเบียน resolver ผ่าน Idtc::resolveContractsUsing())
 */
class Idtc
{
    /** @var (callable(): array<string, string|null>)|null */
    protected static $contractResolver = null;

    public static function resolveContractsUsing(?callable $resolver): void
    {
        static::$contractResolver = $resolver;
    }

    /** @return array<string, string|null> */
    public static function contracts(): array
    {
        $base = (array) config('idtc.contracts', []);
        $override = static::$contractResolver ? (array) call_user_func(static::$contractResolver) : [];

        $out = [];
        foreach ($base as $key => $value) {
            $candidate = $override[$key] ?? $value;
            $out[$key] = static::isAddress($candidate) ? $candidate : null;
        }

        return $out;
    }

    public static function contract(string $key): ?string
    {
        return static::contracts()[$key] ?? null;
    }

    public static function isDeployed(string $key): bool
    {
        return static::contract($key) !== null;
    }

    public static function isAddress(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/', $value) === 1;
    }

    public static function explorerAddress(?string $address): ?string
    {
        return $address ? rtrim((string) config('idtc.chain.explorer'), '/').'/address/'.$address : null;
    }

    public static function explorerTx(?string $hash): ?string
    {
        return $hash ? rtrim((string) config('idtc.chain.explorer'), '/').'/tx/'.$hash : null;
    }

    /** ย่อที่อยู่สำหรับแสดงผล: 0x1234…abcd */
    public static function short(?string $address, int $head = 6, int $tail = 4): string
    {
        if (! $address) {
            return '—';
        }

        return substr($address, 0, $head).'…'.substr($address, -$tail);
    }

    /**
     * ค่าที่ส่งให้ JavaScript (window.IDTC) — ห้ามมีความลับ
     *
     * @return array<string, mixed>
     */
    public static function publicConfig(): array
    {
        return [
            'chain' => [
                'id' => (int) config('idtc.chain.id'),
                'hex' => config('idtc.chain.hex'),
                'name' => config('idtc.chain.name'),
                'rpc' => config('idtc.chain.rpc_public'),
                'explorer' => config('idtc.chain.explorer'),
                'native' => config('idtc.chain.native'),
            ],
            'token' => [
                'symbol' => config('idtc.token.symbol'),
                'name' => config('idtc.token.name'),
                'decimals' => (int) config('idtc.token.decimals'),
                'logo' => url((string) config('idtc.token.logo')),
            ],
            'contracts' => static::contracts(),
            'sale' => config('idtc.sale'),
            'dex' => config('idtc.dex'),
            // ค่าสาธารณะของสะพาน BSC ⇄ TPIX — ที่อยู่ vault ไม่ส่งจากตรงนี้ หน้าเว็บรับจาก /api/bridge/info หลังตรวจแล้วเท่านั้น
            'bridge' => [
                'chain' => config('idtc.bridge.chain'),
                'usdt' => config('idtc.bridge.usdt'),
                'usdt_decimals' => (int) config('idtc.bridge.usdt_decimals'),
                'amount_decimals' => (int) config('idtc.bridge.amount_decimals'),
                'api' => url('/api/bridge'),
                'page' => config('idtc.bridge.page'),
            ],
            // ชื่อก้อนล็อกตามภาษาของหน้า (Tokenomics) — ตัวเลขชุดเดียวกับ config/idtc.php
            'vesting' => ['period_seconds' => (int) config('idtc.vesting.period_seconds'), 'tranches' => Tokenomics::tranches()],
            'site' => url('/'),
            // ภาษาของหน้า: ข้อความทุกตัวที่ JS แสดงมาจาก lang/{th,en}/js.php (resources/js/i18n.js)
            'locale' => SiteLocale::current(),
            'i18n' => __('js'),
            'urls' => [
                'home' => SiteLocale::route('home'),
                'addNetwork' => SiteLocale::route('add-network'),
                'presale' => SiteLocale::route('presale'),
                'trade' => SiteLocale::route('trade'),
                'bridge' => SiteLocale::route('bridge'),
            ],
        ];
    }
}
