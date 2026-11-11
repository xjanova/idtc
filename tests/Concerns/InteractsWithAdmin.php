<?php

namespace Tests\Concerns;

use App\Models\Admin;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait InteractsWithAdmin
{
    /** เจ้าของจาก .env (config) ที่ใช้ในเทสต์ */
    protected string $ownerAddress = '0x1111111111111111111111111111111111111111';

    /** @var (callable(string, array<int, mixed>): mixed)|null */
    protected $rpcHandler = null;

    /** ตั้งค่าแล้ว = จำลองว่าเชื่อมต่อ RPC ไม่ได้ (ข้อความ error ของ cURL) */
    protected ?string $rpcConnectionError = null;

    protected bool $rpcFaked = false;

    /**
     * คำตอบของ explorer (GET /api/v2/...) ตามที่อยู่ — ไม่ตั้ง = 404
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $explorerAddresses = [];

    protected function setUpAdmins(): void
    {
        config(['idtc.admin_wallets' => [$this->ownerAddress]]);
    }

    /** รหัสผ่านที่ loginAs ตั้งให้ owner (owner ต้องมีรหัสผ่านเสมอ) */
    protected string $testPassword = 'correct-horse-battery';

    /**
     * จำลอง session ที่ผ่านทุกขั้นแล้ว (ลายเซ็น + รหัสผ่านถ้าต้องใช้)
     *
     * @param  array<string, string>|null  $permissions  สิทธิ์รายเมนู (null = ตามชุดของ role)
     */
    protected function loginAs(string $address, ?string $role = null, ?array $permissions = null): static
    {
        $address = strtolower($address);
        if ($role !== null) {
            Admin::query()->updateOrCreate(['address' => $address], ['role' => $role, 'permissions' => $permissions]);
        }

        $pwV = null;
        $actual = app(AdminRegistry::class)->roleFor($address);
        if ($actual !== null) {
            $passwords = app(AdminPasswords::class);
            if ($passwords->stepFor($address, $actual) === AdminPasswords::STEP_SET) {
                $passwords->set($address, $this->testPassword);
            }
            $pwV = $passwords->sessionVersion($address, $actual);
        }

        return $this->withSession(['admin' => ['address' => $address, 'login_at' => time(), 'pw_v' => $pwV]]);
    }

    protected function loginAsOwner(): static
    {
        return $this->loginAs($this->ownerAddress);
    }

    /**
     * จำลอง JSON-RPC ของเชน — รองรับทั้งคำขอเดี่ยวและ batch · เรียกซ้ำได้เพื่อเปลี่ยนพฤติกรรมกลางเทสต์
     * $handler(method, params) คืนค่า result · คืน ['__error' => 'msg'] = ข้อผิดพลาด JSON-RPC
     *
     * @param  callable(string, array<int, mixed>): mixed  $handler
     */
    protected function fakeRpc(callable $handler): void
    {
        $this->rpcHandler = $handler;
        $this->rpcConnectionError = null;
        if ($this->rpcFaked) {
            return;
        }
        $this->rpcFaked = true;

        Http::fake(['*' => function (Request $request) {
            if ($this->rpcConnectionError !== null) {
                return (Http::failedConnection($this->rpcConnectionError))($request);
            }
            // explorer (Blockscout REST) — ไม่ใช่ JSON-RPC
            if ($request->method() === 'GET') {
                $addr = strtolower((string) preg_replace('#^.*/api/v2/addresses/#', '', (string) parse_url($request->url(), PHP_URL_PATH)));

                return isset($this->explorerAddresses[$addr])
                    ? Http::response($this->explorerAddresses[$addr])
                    : Http::response(['message' => 'Not found'], 404);
            }
            $body = json_decode($request->body(), true);
            $one = function (array $call) {
                $result = ($this->rpcHandler)($call['method'], $call['params'] ?? []);
                if (is_array($result) && array_key_exists('__error', $result)) {
                    return ['jsonrpc' => '2.0', 'id' => $call['id'], 'error' => ['code' => -32000, 'message' => $result['__error']]];
                }

                return ['jsonrpc' => '2.0', 'id' => $call['id'], 'result' => $result];
            };

            return Http::response(array_is_list($body) ? array_map($one, $body) : $one($body));
        }]);
    }

    protected function failRpcConnection(string $message = 'cURL error 28: Operation timed out after 8000 milliseconds'): void
    {
        if (! $this->rpcFaked) {
            $this->fakeRpc(fn () => null);
        }
        $this->rpcConnectionError = $message;
    }
}
