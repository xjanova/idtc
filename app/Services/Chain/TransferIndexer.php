<?php

namespace App\Services\Chain;

use App\Models\IndexerState;
use App\Support\Idtc;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ดึง event Transfer ของสัญญาเหรียญ IDTC ลงตาราง token_transfers — ใช้ตรวจการถือครบระยะของ Grid
 *
 * - ครั้งแรกเริ่มจากบล็อกที่ deploy สัญญาเหรียญ (ถาม explorer แล้วยืนยันกับ RPC) เพื่อให้ยอดคงเหลือย้อนหลังครบ
 *   หาไม่ได้ (เช่น สร้างจาก Token Factory) = บล็อกเริ่มของยอดขาย presale → กระเป๋าที่มีเหรียญก่อนหน้านั้น
 *   ตัวตรวจการถือจะเห็นยอดติดลบ และไม่ตัดรางวัลของกระเป๋านั้น (HoldCheck: gap)
 * - ทีละช่วง 5,000 บล็อก แบ่งครึ่งเองเมื่อโหนดบอกว่ากว้างไป · ซ้ำได้ (tx_hash + log_index ไม่ซ้ำ)
 * - บันทึกเวลาของบล็อกสุดท้ายที่ดึงครบ (last_block_time) — การซื้อที่ครบระยะถือหลังเวลานี้ยังตัดสินไม่ได้
 */
class TransferIndexer
{
    public const CHUNK = 5000;

    public const MIN_CHUNK = 250;

    public const STATE_KEY = 'transfers';

    public const EVENT = 'Transfer(address,address,uint256)';

    public function __construct(protected RpcClient $rpc, protected Settings $settings, protected ContractOrigin $origin) {}

    public static function topic(): string
    {
        return Abi::topic(self::EVENT);
    }

    public function state(): ?IndexerState
    {
        return IndexerState::query()->find(self::STATE_KEY);
    }

    /**
     * ดึงครบถึงเวลาไหน (unix) — null = ยังไม่เคยดึง / ไม่ได้ตั้งสัญญาเหรียญ
     */
    public function coveredUntil(): ?int
    {
        $token = Idtc::contract('token');
        $state = $this->state();
        if ($token === null || $state === null || $state->contract !== strtolower($token)) {
            return null;
        }

        return $state->last_block_time?->getTimestamp();
    }

    /**
     * @return array{ok: bool, status: string, message: string, inserted: int, from: int|null, to: int|null}
     */
    public function run(int $maxChunks = 40): array
    {
        $token = Idtc::contract('token');
        if ($token === null) {
            return $this->result(true, 'idle', 'ยังไม่ได้ตั้งที่อยู่สัญญาเหรียญ — ยังตรวจการถือไม่ได้');
        }

        $lock = Cache::lock('idtc:index-transfers', 300);
        if (! $lock->get()) {
            return $this->result(false, 'busy', 'กำลังดึงการโอนเหรียญอยู่แล้ว รอสักครู่แล้วดูผล');
        }

        $contract = strtolower($token);
        $state = IndexerState::query()->firstOrNew(['key' => self::STATE_KEY]);
        if ($state->contract !== $contract) {
            $state->contract = $contract;
            $state->last_block = null;
            $state->last_block_time = null;
        }
        $state->last_run_at = now();
        $inserted = 0;
        $firstFrom = null;

        try {
            $head = $this->rpc->blockNumber();
            $state->head_block = $head;
            $from = $state->last_block !== null ? $state->last_block + 1 : $this->startBlock($contract, $head);
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
                $blocks = array_values(array_unique([...array_column($rows, 'block_number'), $to]));
                $times = $this->rpc->blockTimestamps($blocks);
                if ($rows !== []) {
                    $now = now();
                    foreach ($rows as &$row) {
                        $ts = $times[$row['block_number']] ?? null;
                        $row['block_time'] = $ts !== null ? Carbon::createFromTimestampUTC($ts) : null;
                        $row['created_at'] = $now;
                        $row['updated_at'] = $now;
                    }
                    unset($row);
                    foreach (array_chunk($rows, 500) as $part) {
                        $inserted += DB::table('token_transfers')->insertOrIgnore($part);
                    }
                }

                $state->last_block = $to;
                // ไม่รู้เวลาของบล็อกปลายช่วง = ไม่ขยับเวลาที่ดึงครบ (ปลอดภัยกว่าเดา)
                if (($times[$to] ?? null) !== null) {
                    $state->last_block_time = Carbon::createFromTimestampUTC($times[$to]);
                }
                $state->last_error = null;
                $state->save();
                $from = $to + 1;
                $chunks++;
            }

            $state->last_success_at = now();
            $state->save();
            $behind = $head - (int) $state->last_block;

            return $this->result(true, $behind > 0 ? 'partial' : 'done', $behind > 0
                ? 'ดึงการโอนเหรียญได้ถึงบล็อก '.number_format((int) $state->last_block).' (ยังเหลือ '.number_format($behind).' บล็อก)'
                : 'ดึงการโอนเหรียญครบถึงบล็อก '.number_format((int) $state->last_block).' แล้ว', $inserted, $firstFrom, $state->last_block);
        } catch (RpcException $e) {
            $state->last_error = $e->getMessage();
            $state->save();

            return $this->result(false, 'error', $e->getMessage().' — การโอนที่ดึงได้แล้วยังอยู่ครบ รอบหน้าจะดึงต่อจากเดิม', $inserted, $firstFrom, $state->last_block);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $logs
     * @return list<array<string, mixed>>
     */
    public function decode(array $logs, string $contract): array
    {
        $topic = self::topic();
        $decimals = (int) config('idtc.token.decimals', 18);
        $rows = [];
        foreach ($logs as $log) {
            if (! is_array($log) || ! empty($log['removed'])) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            if (strtolower((string) ($log['address'] ?? '')) !== $contract
                || strtolower((string) ($topics[0] ?? '')) !== $topic
                || ! isset($topics[1], $topics[2], $log['transactionHash'], $log['logIndex'], $log['blockNumber'])) {
                continue;
            }
            try {
                [$value] = Abi::decode(['uint256'], (string) ($log['data'] ?? ''));
                $from = Abi::decodeAddress((string) $topics[1]);
                $to = Abi::decodeAddress((string) $topics[2]);
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
                'from' => $from,
                'to' => $to,
                'value' => $value,
                'amount' => Units::toFloat($value, $decimals),
                'block_number' => Units::hexToInt((string) $log['blockNumber']),
            ];
        }

        return $rows;
    }

    protected function startBlock(string $token, int $head): int
    {
        $created = $this->origin->creationBlock($token);
        if ($created !== null) {
            return $created;
        }

        return $this->settings->indexerStartBlock() ?? $head;
    }

    /** @return array{ok: bool, status: string, message: string, inserted: int, from: int|null, to: int|null} */
    protected function result(bool $ok, string $status, string $message, int $inserted = 0, ?int $from = null, ?int $to = null): array
    {
        return compact('ok', 'status', 'message', 'inserted', 'from', 'to');
    }
}
