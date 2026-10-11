<?php

namespace App\Services\Chain;

use App\Models\IndexerState;
use App\Support\Idtc;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ดึง event Purchased ของสัญญา presale ลงตาราง purchases
 *
 * - เริ่มจากบล็อกถัดจากที่ดึงล่าสุด (ครั้งแรก: indexer.start_block ในค่าตั้ง ถ้าไม่ตั้ง = บล็อกปัจจุบัน)
 * - ทีละช่วง 5,000 บล็อก · ช่วงไหนโหนดบอกว่ากว้างไป จะแบ่งครึ่งเอง
 * - ซ้ำกี่ครั้งก็ได้ (tx_hash + log_index ไม่ซ้ำ) · RPC ล่ม = หยุด บันทึกข้อผิดพลาด แล้วรอบหน้าทำต่อจากเดิม
 * - เปลี่ยนที่อยู่สัญญา presale = เริ่มนับใหม่ของสัญญาใหม่ (ยอดของสัญญาเดิมยังอยู่ในตาราง แยกด้วย contract)
 */
class SalesIndexer
{
    public const CHUNK = 5000;

    public const MIN_CHUNK = 250;

    public const STATE_KEY = 'sales';

    public const EVENT = 'Purchased(address,uint256,uint256)';

    public function __construct(protected RpcClient $rpc, protected Settings $settings) {}

    public static function topic(): string
    {
        return Abi::topic(self::EVENT);
    }

    public function state(): ?IndexerState
    {
        return IndexerState::query()->find(self::STATE_KEY);
    }

    /**
     * @return array{ok: bool, status: string, message: string, inserted: int, from: int|null, to: int|null}
     */
    public function run(int $maxChunks = 40): array
    {
        $presale = Idtc::contract('presale');
        if ($presale === null) {
            return $this->result(true, 'idle', 'ยังไม่ได้ตั้งที่อยู่สัญญา presale — ยังไม่มียอดให้ดึง');
        }

        $lock = Cache::lock('idtc:index-sales', 300);
        if (! $lock->get()) {
            return $this->result(false, 'busy', 'กำลังดึงยอดอยู่แล้ว (จากรอบอัตโนมัติหรือแอดมินอีกคน) รอสักครู่แล้วดูผล');
        }

        $contract = strtolower($presale);
        $state = IndexerState::query()->firstOrNew(['key' => self::STATE_KEY]);
        if ($state->contract !== $contract) {
            $state->contract = $contract;
            $state->last_block = null;
        }
        $state->last_run_at = now();
        $inserted = 0;
        $firstFrom = null;

        try {
            $head = $this->rpc->blockNumber();
            $state->head_block = $head;
            $from = $state->last_block !== null ? $state->last_block + 1 : ($this->settings->indexerStartBlock() ?? $head);
            $firstFrom = $from;
            $size = self::CHUNK;
            $chunks = 0;

            while ($from <= $head && $chunks < $maxChunks) {
                $to = min($from + $size - 1, $head);
                try {
                    $logs = $this->rpc->getLogs([
                        'address' => $contract,
                        'topics' => [self::topic()],
                        'fromBlock' => '0x'.dechex($from),
                        'toBlock' => '0x'.dechex($to),
                    ]);
                } catch (RpcException $e) {
                    if ($e->isRangeError() && $size > self::MIN_CHUNK) {
                        $size = max(self::MIN_CHUNK, intdiv($size, 2));

                        continue;
                    }
                    throw $e;
                }

                $rows = $this->decode($logs, $contract);
                if ($rows !== []) {
                    $times = $this->rpc->blockTimestamps(array_values(array_unique(array_column($rows, 'block_number'))));
                    $now = now();
                    foreach ($rows as &$row) {
                        $ts = $times[$row['block_number']] ?? null;
                        $row['block_time'] = $ts !== null ? Carbon::createFromTimestampUTC($ts) : null;
                        $row['created_at'] = $now;
                        $row['updated_at'] = $now;
                    }
                    unset($row);
                    $inserted += DB::table('purchases')->insertOrIgnore($rows);
                }

                $state->last_block = $to;
                $state->last_error = null;
                $state->save();
                $from = $to + 1;
                $chunks++;
            }

            $state->last_success_at = now();
            $state->save();
            $behind = $head - (int) $state->last_block;

            return $this->result(true, $behind > 0 ? 'partial' : 'done', $behind > 0
                ? "ดึงยอดได้ถึงบล็อก {$state->last_block} (ยังเหลือ {$behind} บล็อก ระบบจะดึงต่อรอบหน้า)"
                : "ดึงยอดครบถึงบล็อก {$state->last_block} แล้ว", $inserted, $firstFrom, $state->last_block);
        } catch (RpcException $e) {
            $state->last_error = $e->getMessage();
            $state->save();

            return $this->result(false, 'error', $e->getMessage().' — ยอดที่ดึงได้แล้วยังอยู่ครบ รอบหน้าจะดึงต่อจากเดิม', $inserted, $firstFrom, $state->last_block);
        } finally {
            $lock->release();
        }
    }

    /**
     * แปลง log ดิบเป็นแถวของตาราง purchases (ข้าม log ที่ไม่ใช่ของสัญญานี้/ถูก reorg ทิ้ง/รูปแบบผิด)
     *
     * @param  list<array<string, mixed>>  $logs
     * @return list<array<string, mixed>>
     */
    public function decode(array $logs, string $contract): array
    {
        $topic = self::topic();
        $rows = [];
        foreach ($logs as $log) {
            if (! is_array($log) || ! empty($log['removed'])) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            if (strtolower((string) ($log['address'] ?? '')) !== $contract
                || strtolower((string) ($topics[0] ?? '')) !== $topic
                || ! isset($topics[1], $log['transactionHash'], $log['logIndex'], $log['blockNumber'])) {
                continue;
            }
            try {
                [$pay, $out] = Abi::decode(['uint256', 'uint256'], (string) ($log['data'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $hash = strtolower((string) $log['transactionHash']);
            if (! preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
                continue;
            }
            $rows[] = [
                'contract' => $contract,
                'tx_hash' => $hash,
                'log_index' => Units::hexToInt((string) $log['logIndex']),
                'buyer' => Abi::decodeAddress((string) $topics[1]),
                'pay_amount' => $pay,
                'tokens_out' => $out,
                'pay_usdt' => Units::toFloat($pay, (int) config('idtc.sale.pay_decimals', 6)),
                'tokens_idtc' => Units::toFloat($out, (int) config('idtc.token.decimals', 18)),
                'block_number' => Units::hexToInt((string) $log['blockNumber']),
            ];
        }

        return $rows;
    }

    /** แอดมินตั้งบล็อกเริ่มใหม่ → ดึงใหม่ตั้งแต่บล็อกนั้น (แถวที่มีแล้วไม่ซ้ำ) */
    public function restartFrom(?int $block): void
    {
        $state = $this->state();
        if ($state !== null) {
            $state->last_block = $block !== null && $block > 0 ? $block - 1 : null;
            $state->last_error = null;
            $state->save();
        }
    }

    /** @return array{ok: bool, status: string, message: string, inserted: int, from: int|null, to: int|null} */
    protected function result(bool $ok, string $status, string $message, int $inserted = 0, ?int $from = null, ?int $to = null): array
    {
        return compact('ok', 'status', 'message', 'inserted', 'from', 'to');
    }
}
