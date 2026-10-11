<?php

namespace App\Services\Auth;

use App\Services\Chain\Address;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * ออก nonce และตรวจลายเซ็นเข้าหลังบ้าน
 *
 * nonce ใช้ได้ครั้งเดียว อายุ 5 นาที ผูกกับที่อยู่ที่ขอ · ถูกใช้ทันทีที่ส่งมาตรวจ แม้จะตรวจไม่ผ่าน
 * ตรวจ: รูปแบบข้อความ · nonce · โดเมน/URI ตรงกับเว็บนี้ · chain id 4289 · เวลา · ลายเซ็น
 * (สิทธิ์ผู้ดูแลตรวจต่อใน controller หลังรู้แน่ว่าใครเซ็น)
 */
class SiweAuth
{
    public const NONCE_TTL = 300;

    public const CLOCK_SKEW = 60;

    public function issue(Request $request, string $address): SiweMessage
    {
        $nonce = Str::random(24);
        $now = CarbonImmutable::now('UTC');
        Cache::put($this->nonceKey($nonce), ['address' => strtolower($address)], self::NONCE_TTL);

        return new SiweMessage(
            domain: $request->getHttpHost(),
            address: Address::checksum($address),
            statement: SiweMessage::STATEMENT,
            uri: $request->getSchemeAndHttpHost().'/admin',
            version: '1',
            chainId: (int) config('idtc.chain.id'),
            nonce: $nonce,
            issuedAt: $now->format('Y-m-d\TH:i:s\Z'),
            expirationTime: $now->addSeconds(self::NONCE_TTL)->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return string ที่อยู่ผู้เซ็น (ตัวพิมพ์เล็ก)
     *
     * @throws SiweException
     */
    public function verify(Request $request, string $text, string $signature): string
    {
        $msg = SiweMessage::parse($text);
        if ($msg === null) {
            throw new SiweException('ข้อความที่เซ็นไม่ใช่รูปแบบของหลังบ้าน IDTC กดเข้าสู่ระบบใหม่', 'format');
        }

        // ใช้ nonce ทิ้งก่อนตรวจอย่างอื่น — ส่งซ้ำ/เดาลายเซ็นกับ nonce เดิมไม่ได้
        $data = Cache::get($this->nonceKey($msg->nonce));
        if (! is_array($data) || ! Cache::add('idtc:siwe:used:'.$msg->nonce, 1, self::NONCE_TTL * 2)) {
            throw new SiweException('คำขอเข้าสู่ระบบนี้หมดอายุหรือถูกใช้ไปแล้ว กดเข้าสู่ระบบใหม่', 'nonce');
        }
        Cache::forget($this->nonceKey($msg->nonce));

        $host = $request->getHttpHost();
        $uriHost = (string) parse_url($msg->uri, PHP_URL_HOST);
        $uriPort = parse_url($msg->uri, PHP_URL_PORT);
        $uriAuthority = $uriHost.($uriPort ? ':'.$uriPort : '');
        if (strcasecmp($msg->domain, $host) !== 0 || strcasecmp($uriAuthority, $host) !== 0) {
            throw new SiweException('ข้อความนี้ไม่ได้ออกให้เว็บนี้ (โดเมนไม่ตรง) อย่าเซ็นข้อความเข้าระบบจากเว็บอื่น', 'domain');
        }
        if ($msg->chainId !== (int) config('idtc.chain.id')) {
            throw new SiweException('ข้อความระบุเชนไม่ถูกต้อง ต้องเป็น TPIX Chain (4289)', 'chain');
        }
        if ($msg->version !== '1' || $msg->statement !== SiweMessage::STATEMENT) {
            throw new SiweException('ข้อความที่เซ็นไม่ใช่รูปแบบของหลังบ้าน IDTC กดเข้าสู่ระบบใหม่', 'format');
        }
        if (strtolower($msg->address) !== ($data['address'] ?? null)) {
            throw new SiweException('กระเป๋าที่เซ็นไม่ตรงกับกระเป๋าที่ขอเข้าสู่ระบบ กดเข้าสู่ระบบใหม่', 'address');
        }

        try {
            $issued = CarbonImmutable::parse($msg->issuedAt);
            $expires = $msg->expirationTime ? CarbonImmutable::parse($msg->expirationTime) : null;
        } catch (Throwable) {
            throw new SiweException('เวลาในข้อความไม่ถูกต้อง กดเข้าสู่ระบบใหม่', 'format');
        }
        $now = CarbonImmutable::now();
        if ($issued->gt($now->addSeconds(self::CLOCK_SKEW))
            || $issued->lt($now->subSeconds(self::NONCE_TTL + self::CLOCK_SKEW))
            || ($expires !== null && $expires->lt($now))) {
            throw new SiweException('ข้อความเข้าสู่ระบบหมดอายุแล้ว กดเข้าสู่ระบบใหม่', 'expired');
        }

        $signer = EthSignature::recover($text, $signature);
        if ($signer === null || $signer !== strtolower($msg->address)) {
            throw new SiweException('ลายเซ็นไม่ถูกต้อง กดเข้าสู่ระบบใหม่', 'signature');
        }

        return $signer;
    }

    protected function nonceKey(string $nonce): string
    {
        return 'idtc:siwe:nonce:'.$nonce;
    }
}
