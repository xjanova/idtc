<?php

namespace App\Services\Chain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * JSON-RPC ของเชน TPIX ฝั่งเซิร์ฟเวอร์ (อ่านอย่างเดียว — เซิร์ฟเวอร์ไม่ส่งธุรกรรม ไม่ถือคีย์)
 *
 * ใช้ rpc1.tpix.online (config idtc.chain.rpc) — rpc.tpix.online ตอบ 403 ให้ client ที่ไม่ใช่เบราว์เซอร์
 * timeout 8 วินาที ลองซ้ำ 1 ครั้งเมื่อเชื่อมต่อไม่ได้หรือโหนดตอบ 5xx
 * ทุกข้อผิดพลาดกลายเป็น RpcException ที่มีข้อความไทยพร้อมแสดง
 */
class RpcClient
{
    public const TIMEOUT = 8;

    public const BATCH_SIZE = 20;

    public const DOWN_KEY = 'idtc:rpc:down';

    /** หลังเชื่อมต่อไม่ได้ พักการเรียก RPC กี่วินาที */
    public const DOWN_SECONDS = 20;

    public function __construct(protected ?string $url = null) {}

    public function url(): string
    {
        return $this->url ?? (string) config('idtc.chain.rpc');
    }

    /** @param list<mixed> $params */
    public function call(string $method, array $params = []): mixed
    {
        $json = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        if (! is_array($json) || array_is_list($json)) {
            throw new RpcException('โหนด RPC ตอบกลับในรูปแบบที่อ่านไม่ได้', 'decode');
        }
        if (isset($json['error'])) {
            throw $this->jsonRpcError($json['error']);
        }
        if (! array_key_exists('result', $json)) {
            throw new RpcException('โหนด RPC ตอบกลับในรูปแบบที่อ่านไม่ได้', 'decode');
        }

        return $json['result'];
    }

    /**
     * ส่งหลายคำขอในครั้งเดียว (JSON-RPC batch) — ข้อผิดพลาดรายข้อ (เช่น revert) ไม่ทำให้ข้ออื่นพัง
     * ถ้าโหนดไม่รองรับ batch จะถอยไปเรียกทีละข้อ · ปัญหาการเชื่อมต่อยังโยน RpcException ทั้งชุด
     *
     * @param  list<array{0: string, 1: list<mixed>}>  $calls
     * @return list<array{result?: mixed, error?: RpcException}>
     */
    public function batch(array $calls): array
    {
        $out = [];
        foreach (array_chunk(array_values($calls), self::BATCH_SIZE) as $chunk) {
            $payload = [];
            foreach ($chunk as $i => [$method, $params]) {
                $payload[] = ['jsonrpc' => '2.0', 'id' => $i + 1, 'method' => $method, 'params' => $params];
            }
            $json = $this->post($payload);

            if (! is_array($json) || ! array_is_list($json)) {
                foreach ($chunk as [$method, $params]) {
                    try {
                        $out[] = ['result' => $this->call($method, $params)];
                    } catch (RpcException $e) {
                        if ($e->kind !== 'jsonrpc') {
                            throw $e;
                        }
                        $out[] = ['error' => $e];
                    }
                }

                continue;
            }

            $byId = [];
            foreach ($json as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $byId[(int) $item['id']] = $item;
                }
            }
            foreach ($chunk as $i => $_) {
                $item = $byId[$i + 1] ?? null;
                $out[] = match (true) {
                    $item === null => ['error' => new RpcException('โหนด RPC ตอบไม่ครบทุกคำขอ', 'decode')],
                    isset($item['error']) => ['error' => $this->jsonRpcError($item['error'])],
                    default => ['result' => $item['result'] ?? null],
                };
            }
        }

        return $out;
    }

    public function blockNumber(): int
    {
        return Units::hexToInt($this->string($this->call('eth_blockNumber')));
    }

    public function chainId(): int
    {
        return Units::hexToInt($this->string($this->call('eth_chainId')));
    }

    public function ethCall(string $to, string $data): string
    {
        return $this->string($this->call('eth_call', [['to' => $to, 'data' => $data], 'latest']));
    }

    public function getCode(string $address): string
    {
        return $this->string($this->call('eth_getCode', [$address, 'latest']));
    }

    /** มีโค้ดสัญญาที่ที่อยู่นี้ไหม (กระเป๋าธรรมดา/ที่อยู่ว่าง = ไม่มี) */
    public function hasCode(string $address): bool
    {
        $code = strtolower($this->getCode($address));

        return ! in_array($code, ['', '0x', '0x0', '0x00'], true);
    }

    /** ยอดเหรียญหลักของเชน (TPIX) หน่วย wei เป็นเลขฐานสิบ */
    public function getBalance(string $address): string
    {
        return Units::hexToDec($this->string($this->call('eth_getBalance', [$address, 'latest'])));
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>
     */
    public function getLogs(array $filter): array
    {
        $logs = $this->call('eth_getLogs', [$filter]);
        if (! is_array($logs)) {
            throw new RpcException('โหนด RPC ตอบรายการ event ในรูปแบบที่อ่านไม่ได้', 'decode');
        }

        return array_values($logs);
    }

    /** @return array<string, mixed>|null */
    public function getTransaction(string $hash): ?array
    {
        $tx = $this->call('eth_getTransactionByHash', [$hash]);

        return is_array($tx) ? $tx : null;
    }

    /** @return array<string, mixed>|null */
    public function getReceipt(string $hash): ?array
    {
        $receipt = $this->call('eth_getTransactionReceipt', [$hash]);

        return is_array($receipt) ? $receipt : null;
    }

    /**
     * เวลาของหลายบล็อก (unix) ในคำขอเดียว
     *
     * @param  list<int>  $blocks
     * @return array<int, int|null>
     */
    public function blockTimestamps(array $blocks): array
    {
        $blocks = array_values(array_unique($blocks));
        $results = $this->batch(array_map(fn (int $b) => ['eth_getBlockByNumber', ['0x'.dechex($b), false]], $blocks));
        $out = [];
        foreach ($blocks as $i => $b) {
            $block = $results[$i]['result'] ?? null;
            $out[$b] = is_array($block) && isset($block['timestamp']) ? Units::hexToInt((string) $block['timestamp']) : null;
        }

        return $out;
    }

    /** @param array<int|string, mixed>|list<array<string, mixed>> $payload */
    protected function post(array $payload): mixed
    {
        // เชื่อมต่อไม่ได้เมื่อครู่ = ตอบทันที ไม่รอหมดเวลาซ้ำทุกคำขอ (หน้าเว็บไม่ค้างนานตอน RPC ล่ม)
        $down = Cache::get(self::DOWN_KEY);
        if (is_string($down)) {
            throw new RpcException($down, 'down');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(5)
                ->retry(2, 300, function ($e) {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError());
                }, throw: false)
                ->post($this->url(), $payload);
        } catch (ConnectionException $e) {
            Log::warning('IDTC RPC connection failed', ['error' => Str::limit($e->getMessage(), 160)]);
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            $message = $timeout
                ? 'เชน TPIX ตอบไม่ทันเวลา (เกิน '.self::TIMEOUT.' วินาที) ลองใหม่อีกครั้ง'
                : 'เชื่อมต่อโหนด RPC ของเชน TPIX ไม่ได้ ลองใหม่อีกครั้ง';
            Cache::put(self::DOWN_KEY, $message, self::DOWN_SECONDS);
            throw new RpcException($message, $timeout ? 'timeout' : 'connection');
        }

        if ($response->status() === 429) {
            throw new RpcException('โหนด RPC จำกัดจำนวนคำขอ รอสักครู่แล้วลองใหม่', 'http');
        }
        if (! $response->successful()) {
            Log::warning('IDTC RPC HTTP error', ['status' => $response->status()]);
            throw new RpcException('โหนด RPC ตอบผิดปกติ (HTTP '.$response->status().') ลองใหม่อีกครั้ง', 'http');
        }

        $json = $response->json();
        if ($json === null) {
            throw new RpcException('โหนด RPC ตอบกลับในรูปแบบที่อ่านไม่ได้', 'decode');
        }

        return $json;
    }

    protected function jsonRpcError(mixed $error): RpcException
    {
        $msg = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
        if (stripos($msg, 'revert') !== false) {
            return new RpcException('สัญญาปฏิเสธคำสั่ง (revert) — ที่อยู่นี้อาจไม่ใช่สัญญาชนิดที่คาดไว้', 'jsonrpc', $msg);
        }

        return new RpcException('เชนตอบข้อผิดพลาด: '.Str::limit($msg !== '' ? $msg : 'ไม่ทราบสาเหตุ', 120), 'jsonrpc', $msg);
    }

    protected function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RpcException('โหนด RPC ตอบค่าในรูปแบบที่อ่านไม่ได้', 'decode');
        }

        return $value;
    }

    /**
     * อ่านหลายค่าจากสัญญาในคำขอเดียว
     *
     * @param  array<string, array{0: string, 1: string, 2?: list<mixed>, 3?: list<string>}>  $calls  key => [to, signature, args, returnTypes]
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function readMany(array $calls): array
    {
        $keys = array_keys($calls);
        $batch = [];
        foreach ($calls as $c) {
            $batch[] = ['eth_call', [['to' => $c[0], 'data' => Abi::encodeCall($c[1], $c[2] ?? [])], 'latest']];
        }
        $results = $this->batch($batch);

        $values = $errors = [];
        foreach ($keys as $i => $key) {
            $types = $calls[$key][3] ?? ['uint256'];
            $r = $results[$i] ?? [];
            if (isset($r['error'])) {
                $values[$key] = null;
                $errors[$key] = $r['error']->getMessage();

                continue;
            }
            try {
                $decoded = Abi::decode($types, (string) ($r['result'] ?? ''));
                $values[$key] = count($types) === 1 ? $decoded[0] : $decoded;
            } catch (UnexpectedValueException) {
                $values[$key] = null;
                $errors[$key] = 'สัญญาไม่มีฟังก์ชันนี้ หรือตอบค่าผิดรูปแบบ';
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
