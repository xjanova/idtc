<?php

namespace Tests\Unit;

use App\Services\Chain\Abi;
use App\Services\Chain\Address;
use App\Services\Chain\Units;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class AbiTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function selectors(): array
    {
        // ค่ามาตรฐานที่รู้กันทั่วไป + ค่าที่คำนวณด้วย ethers (tests/Fixtures/ethers-vectors.json)
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/ethers-vectors.json'), true)['topics'];

        return [
            'transfer' => ['transfer(address,uint256)', '0xa9059cbb'],
            'balanceOf' => ['balanceOf(address)', '0x70a08231'],
            'decimals' => ['decimals()', '0x313ce567'],
            'totalSupply' => ['totalSupply()', '0x18160ddd'],
            'owner' => ['owner()', '0x8da5cb5b'],
            'pause' => ['pause()', '0x8456cb59'],
            'token0' => ['token0()', '0x0dfe1681'],
            'getReserves (ethers)' => ['getReserves()', $vectors['getReserves()']],
            'setLimits (ethers)' => ['setLimits(uint256,uint256)', $vectors['setLimits(uint256,uint256)']],
            'setWindow (ethers)' => ['setWindow(uint64,uint64)', $vectors['setWindow(uint64,uint64)']],
            'tranche (ethers)' => ['tranche(uint256)', $vectors['tranche(uint256)']],
            'nextUnlock (ethers)' => ['nextUnlock()', $vectors['nextUnlock()']],
        ];
    }

    #[DataProvider('selectors')]
    public function test_function_selectors_match_keccak(string $signature, string $expected): void
    {
        $this->assertSame($expected, Abi::selector($signature));
    }

    public function test_selector_ignores_whitespace(): void
    {
        $this->assertSame('0xa9059cbb', Abi::selector('transfer(address, uint256)'));
    }

    public function test_event_topic_matches_ethers(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/ethers-vectors.json'), true)['topics'];
        $this->assertSame($vectors['Purchased'], Abi::topic('Purchased(address,uint256,uint256)'));
        $this->assertSame('0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', Abi::topic('Transfer(address,address,uint256)'));
    }

    public function test_encode_call_with_address_and_uint(): void
    {
        $data = Abi::encodeCall('setLimits(uint256,uint256)', ['1000000', '2000000000000000000000000000']);
        $this->assertSame(
            Abi::selector('setLimits(uint256,uint256)')
            .str_pad('f4240', 64, '0', STR_PAD_LEFT)
            .str_pad('6765c793fa10079d0000000', 64, '0', STR_PAD_LEFT),
            $data,
        );

        $this->assertSame(
            '0x70a08231000000000000000000000000399679922b673fee28c507b07ff12e17abfd78a8',
            Abi::encodeCall('balanceOf(address)', ['0x399679922b673FEE28c507B07FF12e17aBFD78a8']),
        );
    }

    public function test_encode_uint_max_and_overflow(): void
    {
        $max = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
        $this->assertSame(str_repeat('f', 64), Abi::encodeUint($max));
        $this->assertSame(str_repeat('0', 64), Abi::encodeUint(0));

        $this->expectException(InvalidArgumentException::class);
        Abi::encodeUint('115792089237316195423570985008687907853269984665640564039457584007913129639936');
    }

    public function test_encode_rejects_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Abi::encodeCall('balanceOf(address)', ['0x123']);
    }

    public function test_encode_rejects_wrong_argument_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Abi::encodeCall('setLimits(uint256,uint256)', ['1']);
    }

    public function test_decode_static_tuple(): void
    {
        // tranche(uint256) → tuple(uint256 amount, uint32 cliffPeriods, uint32 releasePeriods)
        $amount = '220000000000000000000000000000'; // 220B × 1e18
        $data = '0x'.Abi::encodeUint($amount).Abi::encodeUint(12).Abi::encodeUint(12);
        $this->assertSame([$amount, '12', '12'], Abi::decode(['uint256', 'uint32', 'uint32'], $data));
    }

    public function test_decode_address_bool_and_large_uint(): void
    {
        $data = '0x'
            .str_pad('399679922b673fee28c507b07ff12e17abfd78a8', 64, '0', STR_PAD_LEFT)
            .Abi::encodeUint(1)
            .str_repeat('f', 64);
        [$addr, $flag, $big] = Abi::decode(['address', 'bool', 'uint256'], $data);
        $this->assertSame('0x399679922b673fee28c507b07ff12e17abfd78a8', $addr);
        $this->assertTrue($flag);
        $this->assertSame('115792089237316195423570985008687907853269984665640564039457584007913129639935', $big);
    }

    public function test_decode_string(): void
    {
        $text = 'IDTC — ล็อก 70%';
        $hex = bin2hex($text);
        $data = '0x'.Abi::encodeUint(32).Abi::encodeUint(strlen($text)).str_pad($hex, (int) ceil(strlen($hex) / 64) * 64, '0');
        $this->assertSame([$text], Abi::decode(['string'], $data));
    }

    public function test_decode_rejects_short_or_garbage_data(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Abi::decode(['uint256', 'uint256'], '0x'.Abi::encodeUint(1));
    }

    public function test_decode_rejects_non_abi_data(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Abi::words('0x1234');
    }

    public function test_address_checksum(): void
    {
        $this->assertSame('0x399679922b673FEE28c507B07FF12e17aBFD78a8', Address::checksum('0x399679922b673fee28c507b07ff12e17abfd78a8'));
        $this->assertTrue(Address::hasValidChecksum('0x399679922b673FEE28c507B07FF12e17aBFD78a8'));
        $this->assertTrue(Address::hasValidChecksum('0x399679922b673fee28c507b07ff12e17abfd78a8'));
        $this->assertFalse(Address::hasValidChecksum('0x399679922b673FEE28c507B07FF12e17aBFD78A8'));
        $this->assertFalse(Address::isValid('0x399679922b673fee28c507b07ff12e17abfd78a'));
    }

    public function test_units_round_trip(): void
    {
        $this->assertSame('1.5', Units::fromRaw('1500000', 6));
        $this->assertSame('0.000001', Units::fromRaw('1', 6));
        $this->assertSame('1500000', Units::toRaw('1.5', 6));
        $this->assertSame('0', Units::toRaw('0', 18));
        $this->assertNull(Units::toRaw('1.0000001', 6));
        $this->assertNull(Units::toRaw('-1', 6));
        $this->assertSame('1,234,567.89', Units::format('1234567891234', 6, 2));
        $this->assertSame('87.52B', Units::compact(87_520_000_000));
        $this->assertSame('1.1T', Units::compact(1_100_000_000_000));
        $this->assertSame('255', Units::hexToDec('0xff'));
    }
}
