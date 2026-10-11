<?php

namespace Tests\Unit;

use App\Services\Auth\EthSignature;
use App\Services\Auth\SiweMessage;
use PHPUnit\Framework\TestCase;

/**
 * เวกเตอร์ทดสอบสร้างครั้งเดียวด้วย ethers v6 (Wallet.createRandom + signMessage)
 * คีย์เป็นคีย์สุ่มทิ้ง ไม่ได้เก็บไว้ที่ไหน — ใช้ทดสอบเท่านั้น (tests/Fixtures/ethers-vectors.json)
 */
class EthSignatureTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/ethers-vectors.json'), true);
    }

    public function test_recovers_signer_of_personal_sign_message(): void
    {
        $this->assertSame(strtolower($this->f['address']), EthSignature::recover($this->f['hello']['message'], $this->f['hello']['signature']));
    }

    public function test_recovers_signer_of_thai_siwe_message(): void
    {
        $case = $this->f['cases']['valid'];
        $this->assertSame(strtolower($this->f['address']), EthSignature::recover($case['message'], $case['signature']));
    }

    public function test_accepts_v_as_0_or_1(): void
    {
        $sig = $this->f['hello']['signature'];
        $v = hexdec(substr($sig, -2));
        $alt = substr($sig, 0, -2).str_pad(dechex($v - 27), 2, '0', STR_PAD_LEFT);
        $this->assertSame(strtolower($this->f['address']), EthSignature::recover($this->f['hello']['message'], $alt));
    }

    public function test_tampered_message_recovers_a_different_address(): void
    {
        $recovered = EthSignature::recover($this->f['hello']['message'].'!', $this->f['hello']['signature']);
        $this->assertNotSame(strtolower($this->f['address']), $recovered);
    }

    public function test_rejects_malformed_signatures(): void
    {
        $msg = $this->f['hello']['message'];
        $sig = $this->f['hello']['signature'];
        $this->assertNull(EthSignature::recover($msg, ''));
        $this->assertNull(EthSignature::recover($msg, substr($sig, 2)));            // ไม่มี 0x
        $this->assertNull(EthSignature::recover($msg, substr($sig, 0, -2)));        // สั้นไป
        $this->assertNull(EthSignature::recover($msg, substr($sig, 0, -2).'1d'));   // v = 29
        $this->assertNull(EthSignature::recover($msg, '0x'.str_repeat('0', 128).'1b')); // r = s = 0
    }

    public function test_siwe_message_round_trip(): void
    {
        $text = $this->f['cases']['valid']['message'];
        $msg = SiweMessage::parse($text);
        $this->assertNotNull($msg);
        $this->assertSame('localhost', $msg->domain);
        $this->assertSame(4289, $msg->chainId);
        $this->assertSame('เข้าสู่หลังบ้าน IDTC', $msg->statement);
        $this->assertSame($text, $msg->toString());
        $this->assertNull(SiweMessage::parse($text."\nResources:\n- https://evil.example"));
    }
}
