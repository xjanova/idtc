<?php

namespace Tests\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/**
 * ลายเซ็นจริงจาก ethers (tests/Fixtures/ethers-vectors.json — คีย์สุ่มทิ้ง) สำหรับเทสต์ขั้นเซ็นกระเป๋าของหลังบ้าน
 * เวกเตอร์เซ็นสำหรับโดเมน localhost เวลา 2026-10-11 05:00Z
 */
trait SignsAdminMessages
{
    /** @var array<string, mixed> */
    protected array $f;

    protected function setUpSignedVectors(): void
    {
        URL::forceRootUrl('http://localhost');
        $this->f = json_decode((string) file_get_contents(base_path('tests/Fixtures/ethers-vectors.json')), true);
        Carbon::setTestNow('2026-10-11 05:01:00');
    }

    protected function signer(): string
    {
        return strtolower($this->f['address']);
    }

    /** จำลองว่า nonce ของเคสนี้ถูกออกให้ผู้เซ็นแล้ว (เหมือนเรียก /admin/auth/nonce) @return array<string, string> */
    protected function prime(string $case = 'valid'): array
    {
        $c = $this->f['cases'][$case];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => $this->signer()], 300);

        return $c;
    }

    protected function verify(array $case): TestResponse
    {
        return $this->postJson('/admin/auth/verify', ['message' => $case['message'], 'signature' => $case['signature']]);
    }
}
