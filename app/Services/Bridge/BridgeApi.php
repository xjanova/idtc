<?php

namespace App\Services\Bridge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * อ่านสะพาน USDT ของ tpix.online (BSC ⇄ TPIX) ฝั่งเซิร์ฟเวอร์ แล้วส่งต่อให้หน้า /bridge
 *
 * - เบราว์เซอร์เรียก tpix.online ตรงไม่ได้ (CORS ไม่เปิดให้ idtc.online) จึงผ่านตัวนี้
 * - ตรวจทุกครั้งว่า vault / USDT ของ BSC / USDT_TPIX ตรงกับค่าที่ตรึงไว้ใน config/idtc.php
 *   ถ้าไม่ตรง = ปิดการเติมเงิน (กันกรณี API ถูกแก้ให้ชี้ที่อยู่อื่น แล้วผู้ใช้โอนเงินจริงไปผิดที่)
 * - ส่งต่อเฉพาะช่องที่หน้าเว็บใช้ ไม่ส่งต่อ JSON ดิบ
 */
class BridgeApi
{
    public const HASH = '/^0x[0-9a-fA-F]{64}$/';

    /** สถานะที่ API ของสะพานตอบได้ — ค่าอื่นถือเป็น unknown */
    private const DEPOSIT_STATES = [
        'pending', 'unknown', 'not_found', 'failed', 'not_a_deposit', 'confirming', 'queued', 'minting', 'minted',
        'review', 'rejected', 'ignored', 'chain_paused', 'chain_disabled', 'unsupported_chain',
    ];

    private const WITHDRAW_STATES = [
        'pending', 'unknown', 'not_found', 'failed', 'not_a_withdrawal', 'confirming', 'queued', 'sending', 'paid',
        'review', 'awaiting_liquidity', 'rejected', 'below_fee',
    ];

    /**
     * ข้อมูลสะพานสำหรับหน้าเว็บ — ไม่ throw: อ่านไม่ได้ก็ตอบ open=false พร้อมเหตุผล
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $cfg = (array) config('idtc.bridge');

        return Cache::remember('bridge:info', (int) ($cfg['cache_seconds'] ?? 30), function () use ($cfg) {
            $raw = $this->get('/info');
            if ($raw === null) {
                return $this->closed('unreachable');
            }

            $chainId = (int) $cfg['chain']['id'];
            $chain = collect((array) ($raw['chains'] ?? []))->first(fn ($c) => (int) ($c['chain_id'] ?? 0) === $chainId);
            $limits = (array) ($raw['limits'] ?? []);
            $minter = (array) ($raw['minter'] ?? []);

            // ค่าที่ตรึงไว้ต้องตรงทุกตัว — ตัวไหนไม่ตรง ห้ามให้ผู้ใช้โอนเงิน
            $mismatch = match (true) {
                ! $this->same($raw['vault_address'] ?? null, $cfg['vault']) => 'vault_mismatch',
                ! is_array($chain) => 'chain_missing',
                ! $this->same($chain['usdt_address'] ?? null, $cfg['usdt']) => 'usdt_mismatch',
                (int) ($chain['usdt_decimals'] ?? 0) !== (int) $cfg['usdt_decimals'] => 'usdt_mismatch',
                ! $this->same($raw['tpix']['usdt_tpix'] ?? null, config('idtc.contracts.usdt')) => 'usdt_tpix_mismatch',
                default => null,
            };

            $reason = $mismatch ?? match (true) {
                ! ($raw['installed'] ?? false) || ! ($raw['enabled'] ?? false) => 'closed',
                ! ($chain['enabled'] ?? false) => 'chain_closed',
                ($minter['paused'] ?? false) === true => 'minter_paused',
                default => null,
            };

            return [
                'open' => $reason === null,
                'reason' => $reason ?? (($raw['paused_reason'] ?? null) ? 'maintenance' : null),
                // ส่งที่อยู่ให้หน้าเว็บเฉพาะเมื่อผ่านการตรวจแล้วเท่านั้น
                'vault' => $mismatch === null ? $cfg['vault'] : null,
                'usdt' => $cfg['usdt'],
                'usdt_decimals' => (int) $cfg['usdt_decimals'],
                'deposit' => [
                    'min' => $this->decimal($limits['min_deposit'] ?? null),
                    'max_per_tx' => $this->decimal($raw['deposit_max_per_tx'] ?? ($limits['max_deposit_per_tx'] ?? null)),
                    'max_per_address_daily' => $this->decimal($limits['max_deposit_per_address_daily'] ?? null),
                    'fee' => $this->decimal($limits['deposit_fee'] ?? null),
                    'remaining_today' => $this->decimal($minter['remaining_today'] ?? null),
                ],
                'withdraw' => [
                    'min' => $this->decimal($limits['min_withdraw'] ?? null),
                    'max_per_tx' => $this->decimal($limits['max_withdraw_per_tx'] ?? null),
                    'fee' => $this->decimal($chain['withdraw_fee'] ?? null),
                ],
                'eta_minutes' => max(1, (int) ($chain['eta_minutes'] ?? 10)),
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * สถานะรายการ — แคชฝั่งเรา เพราะ tpix.online จำกัด 30 คำขอ/นาทีต่อ IP และผู้ใช้ idtc.online ทุกคน
     * ออกไปจาก IP เซิร์ฟเวอร์เดียวกัน · สถานะจบแล้วแคชนาน สถานะที่ยังเดินแคชสั้น · อ่านไม่ได้ไม่แคช
     *
     * @return array<string, mixed>
     */
    private function cachedStatus(string $kind, string $txHash, callable $fetch): array
    {
        $key = "bridge:{$kind}:".strtolower($txHash);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $data = $fetch();
        if ($data['state'] !== 'unreachable') {
            $done = in_array($data['state'], ['minted', 'paid', 'rejected', 'failed', 'not_a_deposit', 'not_a_withdrawal', 'ignored', 'below_fee'], true);
            Cache::put($key, $data, $done ? 300 : 10);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function deposit(string $txHash): array
    {
        return $this->cachedStatus('deposit', $txHash, fn () => $this->fetchDeposit($txHash));
    }

    /** @return array<string, mixed> */
    public function withdrawal(string $txHash): array
    {
        return $this->cachedStatus('withdrawal', $txHash, fn () => $this->fetchWithdrawal($txHash));
    }

    /** @return array<string, mixed> */
    private function fetchDeposit(string $txHash): array
    {
        $chainId = (int) config('idtc.bridge.chain.id');
        $raw = $this->get("/status/{$chainId}/".strtolower($txHash));
        if ($raw === null) {
            return ['state' => 'unreachable'];
        }

        $row = (array) (($raw['deposits'] ?? [])[0] ?? []);

        return [
            'state' => $this->state($raw['state'] ?? null, self::DEPOSIT_STATES),
            'amount' => $this->decimal($row['amount'] ?? ($raw['amount'] ?? null)),
            'mint_amount' => $this->decimal($row['mint_amount'] ?? null),
            'confirmations' => isset($raw['confirmations']) ? (int) $raw['confirmations'] : null,
            'final' => (bool) ($raw['final'] ?? false),
            'mint_tx' => $this->hash($row['mint_tx_hash'] ?? null),
            'review_reason' => $this->code($row['review_reason'] ?? null),
            'eta_minutes' => isset($raw['eta_minutes']) ? (int) $raw['eta_minutes'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchWithdrawal(string $txHash): array
    {
        $raw = $this->get('/withdrawals/'.strtolower($txHash));
        if ($raw === null) {
            return ['state' => 'unreachable'];
        }

        $row = (array) (($raw['withdrawals'] ?? [])[0] ?? []);

        return [
            'state' => $this->state($raw['state'] ?? null, self::WITHDRAW_STATES),
            'amount' => $this->decimal($row['amount'] ?? null),
            'fee' => $this->decimal($row['fee'] ?? null),
            'payout_amount' => $this->decimal($row['payout_amount'] ?? null),
            'target' => is_string($row['target_address'] ?? null) && preg_match('/^0x[0-9a-fA-F]{40}$/', $row['target_address']) ? $row['target_address'] : null,
            'payout_tx' => $this->hash($row['payout_tx_hash'] ?? null),
            'review_reason' => $this->code($row['review_reason'] ?? null),
        ];
    }

    /** GET แล้วคืน data — null ถ้าเชื่อมไม่ได้ / ตอบผิดรูป */
    private function get(string $path): ?array
    {
        try {
            $res = Http::timeout((int) config('idtc.bridge.timeout_seconds', 8))
                ->acceptJson()
                ->withUserAgent('idtc.online bridge-proxy')
                ->get(rtrim((string) config('idtc.bridge.api'), '/').$path);
        } catch (Throwable $e) {
            Log::warning('IDTC bridge API unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful() || $res->json('success') !== true || ! is_array($res->json('data'))) {
            // 422 ของ /status (เช่นเชนไม่รองรับ) เป็นคำตอบปกติ ไม่ต้องเตือน
            if ($res->serverError() || $res->status() === 429) {
                Log::warning('IDTC bridge API error', ['path' => $path, 'status' => $res->status()]);
            }

            return null;
        }

        return $res->json('data');
    }

    private function closed(string $reason): array
    {
        return ['open' => false, 'reason' => $reason, 'vault' => null, 'usdt' => config('idtc.bridge.usdt'), 'usdt_decimals' => (int) config('idtc.bridge.usdt_decimals'), 'deposit' => [], 'withdraw' => [], 'eta_minutes' => null, 'checked_at' => now()->toIso8601String()];
    }

    private function same(mixed $a, mixed $b): bool
    {
        return is_string($a) && is_string($b) && $a !== '' && strtolower($a) === strtolower($b);
    }

    /** ตัวเลขทศนิยมเป็นข้อความ (ไม่ผ่าน float) — รูปแบบอื่นทิ้ง */
    private function decimal(mixed $v): ?string
    {
        $s = is_int($v) ? (string) $v : $v;

        return is_string($s) && preg_match('/^\d{1,18}(\.\d{1,18})?$/', $s) ? $s : null;
    }

    private function hash(mixed $v): ?string
    {
        return is_string($v) && preg_match(self::HASH, $v) ? strtolower($v) : null;
    }

    private function code(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[a-z0-9_]{1,40}$/', $v) ? $v : null;
    }

    private function state(mixed $v, array $allowed): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : 'unknown';
    }
}
