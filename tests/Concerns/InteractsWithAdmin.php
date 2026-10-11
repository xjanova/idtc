<?php

namespace Tests\Concerns;

use App\Models\Admin;
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

    protected function setUpAdmins(): void
    {
        config(['idtc.admin_wallets' => [$this->ownerAddress]]);
    }

    protected function loginAs(string $address, ?string $role = null): static
    {
        $address = strtolower($address);
        if ($role !== null) {
            Admin::query()->updateOrCreate(['address' => $address], ['role' => $role]);
        }

        return $this->withSession(['admin' => ['address' => $address, 'login_at' => time()]]);
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
