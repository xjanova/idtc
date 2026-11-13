<?php

namespace App\Services\Binary;

use App\Models\BinaryDeposit;
use App\Models\IndexerState;
use App\Services\Chain\Abi;
use App\Services\Chain\RpcClient;
use App\Services\Chain\RpcException;
use App\Services\Chain\TransferIndexer;
use App\Services\Chain\Units;
use App\Services\Grid\GridPlan;
use App\Support\Idtc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * อ่านการโอน USDT (event Transfer ของสัญญา USDT บนเชน TPIX) เข้า "กระเป๋า Binary" ของโครงการ ลงตาราง binary_deposits
 *
 *  - เซิร์ฟเวอร์ไม่ถือคีย์และไม่แตะเงิน: กระเป๋า Binary เป็นกระเป๋าของโครงการเอง ระบบแค่อ่านเชนว่าใครโอนเข้ามาเท่าไร
 *  - ฝาก = ซื้อทันที: แต่ละรายการถูกตัดสินตอนอ่าน (ราคา/กองรางวัลของสัปดาห์ที่โอน) แล้วไม่เปลี่ยนอีก
 *      ok    นับเป็นยอดซื้อ IDTC ของผู้โอน
 *      off / ended / min / quota   ไม่นับ — ขึ้นรายการ "ต้องคืนเงิน" ในหลังบ้าน (ระบบคืนเองไม่ได้ ไม่มีคีย์)
 *  - เริ่มอ่านจากบล็อกตอนตั้งกระเป๋า (settings binary.start_block) · เปลี่ยนกระเป๋า = เริ่มนับใหม่จากบล็อกตอนเปลี่ยน
 *  - ทีละช่วง 1,000 บล็อก (โหนดเชน TPIX จำกัด eth_getLogs ไว้เท่านี้) แบ่งครึ่งเองถ้าโหนดบอกว่ากว้างไป · รันซ้ำได้
 *  - ลำดับตัดสินโควตา = ลำดับบนเชน (บล็อก, ลำดับ log) และทำใน transaction เดียวต่อช่วง
 */
class DepositIndexer
{
    public const CHUNK = 1000;

    public const MIN_CHUNK = 100;

    public const STATE_KEY = 'binary';

    public function __construct(protected RpcClient $rpc, protected BinaryPlan $plans) {}

    public function state(): ?IndexerState
    {
        return IndexerState::query()->find(self::STATE_KEY);
    }

    /**
     * @return array{ok: bool, status: string, message: string, inserted: int}
     */
    public function run(int $maxChunks = 40): array
    {
        $wallet = $this->plans->wallet();
        $usdt = Idtc::contract('usdt');
        if ($wallet === null || $usdt === null) {
            return $this->result(true, 'idle', 'ยังไม่ได้ตั้งกระเป๋า Binary — ยังไม่อ่านยอดฝาก');
        }
        $lock = Cache::lock('idtc:index-binary', 300);
        if (! $lock->get()) {
            return $this->result(false, 'busy', 'กำลังอ่านยอดฝาก Binary อยู่แล้ว รอสักครู่');
        }

        $usdt = strtolower($usdt);
        $state = IndexerState::query()->firstOrNew(['key' => self::STATE_KEY]);
        if ($state->contract !== $wallet) {   // ช่อง contract ของงานนี้เก็บ "กระเป๋าที่กำลังอ่าน"
            $state->contract = $wallet;
            $state->last_block = null;
            $state->last_block_time = null;
        }
        $state->last_run_at = now();
        $inserted = 0;

        try {
            $head = $this->rpc->blockNumber();
            $state->head_block = $head;
            $from = $state->last_block !== null ? $state->last_block + 1 : ($this->plans->startBlock() ?? $head);
            $size = self::CHUNK;
            $chunks = 0;
            $to64 = '0x'.str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT);

            while ($from <= $head && $chunks < $maxChunks) {
                $to = min($from + $size - 1, $head);
                try {
                    $logs = $this->rpc->getLogs([
                        'address' => $usdt,
                        'topics' => [TransferIndexer::topic(), null, $to64],
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

                $rows = $this->decode($logs, $usdt, $wallet);
                $blocks = array_values(array_unique([...array_column($rows, 'block_number'), $to]));
                $times = $this->rpc->blockTimestamps($blocks);
                foreach ($rows as $row) {
                    if (($times[$row['block_number']] ?? null) === null) {
                        // ไม่รู้เวลาของบล็อก = ยังตัดสินสัปดาห์/ราคาไม่ได้ — หยุดรอบนี้ รอบหน้าอ่านช่วงนี้ใหม่
                        throw new RpcException('อ่านเวลาของบล็อก '.$row['block_number'].' ไม่ได้', 'decode');
                    }
                }
                $inserted += DB::transaction(fn () => $this->store($rows, $times));

                $state->last_block = $to;
                if (($times[$to] ?? null) !== null) {
                    $state->last_block_time = CarbonImmutable::createFromTimestampUTC($times[$to]);
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
                ? 'อ่านยอดฝาก Binary ถึงบล็อก '.number_format((int) $state->last_block).' (ยังเหลือ '.number_format($behind).' บล็อก)'
                : 'อ่านยอดฝาก Binary ครบถึงบล็อก '.number_format((int) $state->last_block).' แล้ว', $inserted);
        } catch (RpcException $e) {
            $state->last_error = $e->getMessage();
            $state->save();

            return $this->result(false, 'error', $e->getMessage().' — รายการที่อ่านได้แล้วยังอยู่ครบ รอบหน้าจะอ่านต่อจากเดิม', $inserted);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $logs
     * @return list<array{tx_hash: string, log_index: int, wallet: string, to: string, amount: string, usdt: float, block_number: int}>
     */
    public function decode(array $logs, string $usdt, string $wallet): array
    {
        $topic = TransferIndexer::topic();
        $decimals = (int) config('idtc.sale.pay_decimals', 6);
        $rows = [];
        foreach ($logs as $log) {
            if (! is_array($log) || ! empty($log['removed'])) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            if (strtolower((string) ($log['address'] ?? '')) !== $usdt
                || strtolower((string) ($topics[0] ?? '')) !== $topic
                || ! isset($topics[1], $topics[2], $log['transactionHash'], $log['logIndex'], $log['blockNumber'])) {
                continue;
            }
            try {
                [$value] = Abi::decode(['uint256'], (string) ($log['data'] ?? ''));
                $from = Abi::decodeAddress((string) $topics[1]);
                $to = Abi::decodeAddress((string) $topics[2]);
            } catch (Throwable) {
                continue;
            }
            $hash = strtolower((string) $log['transactionHash']);
            // โหนดกรอง "ถึงกระเป๋านี้" มาแล้ว — ตรวจซ้ำ ไม่เชื่อโหนดอย่างเดียว · โอนจากกระเป๋าตัวเอง/ยอด 0 ไม่นับ
            if (strtolower($to) !== $wallet || strtolower($from) === $wallet || ! preg_match('/^0x[0-9a-f]{64}$/', $hash) || Units::cmp($value, '0') <= 0) {
                continue;
            }
            $rows[] = [
                'tx_hash' => $hash,
                'log_index' => Units::hexToInt((string) $log['logIndex']),
                'wallet' => strtolower($from),
                'to' => $wallet,
                'amount' => $value,
                'usdt' => Units::toFloat($value, $decimals),
                'block_number' => Units::hexToInt((string) $log['blockNumber']),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['block_number'], $a['log_index']] <=> [$b['block_number'], $b['log_index']]);

        return $rows;
    }

    /**
     * ตัดสินและบันทึกทีละรายการตามลำดับบนเชน — คืนจำนวนรายการใหม่
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, int|null>  $times
     */
    protected function store(array $rows, array $times): int
    {
        if ($rows === []) {
            return 0;
        }
        $enabled = $this->plans->enabled();
        $quota = $this->plans->quota();
        $ends = $this->plans->endsAt()?->getTimestamp();
        $sold = (float) BinaryDeposit::query()->where('status', BinaryDeposit::OK)->sum('idtc');
        $new = 0;
        foreach ($rows as $row) {
            if (BinaryDeposit::query()->where('tx_hash', $row['tx_hash'])->where('log_index', $row['log_index'])->exists()) {
                continue;
            }
            $ts = (int) $times[$row['block_number']];
            $week = GridPlan::purchaseWeek($ts);
            $plan = $this->plans->at($week);
            $price = (float) $plan['price'];
            $idtc = $price > 0 ? $row['usdt'] / $price : 0.0;
            $status = match (true) {
                ! $enabled || $quota <= 0 || $price <= 0 => 'off',
                $ends !== null && $ts >= $ends => 'ended',
                $plan['min_usdt'] > $row['usdt'] + 1e-9 => 'min',
                $sold + $idtc > $quota + 1e-6 => 'quota',
                default => BinaryDeposit::OK,
            };
            $ok = $status === BinaryDeposit::OK;
            BinaryDeposit::query()->create($row + [
                'block_time' => CarbonImmutable::createFromTimestampUTC($ts),
                'week' => $week,
                'status' => $status,
                'price' => $plan['price'],
                'idtc' => $ok ? $idtc : 0.0,
                'idtc_micro' => $ok ? BinaryDeposit::microIdtc($row['amount'], (string) $plan['price']) : '0',
                'pool_usdt' => $ok ? $row['usdt'] * (float) $plan['pool'] : 0.0,
            ]);
            if ($ok) {
                $sold += $idtc;
            }
            $new++;
        }

        return $new;
    }

    /** @return array{ok: bool, status: string, message: string, inserted: int} */
    protected function result(bool $ok, string $status, string $message, int $inserted = 0): array
    {
        return compact('ok', 'status', 'message', 'inserted');
    }
}
