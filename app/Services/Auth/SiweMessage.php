<?php

namespace App\Services\Auth;

/**
 * ข้อความเข้าสู่ระบบแบบ EIP-4361 (Sign-In with Ethereum) ที่หลังบ้าน IDTC ใช้
 *
 *   idtc.online wants you to sign in with your Ethereum account:
 *   0xAbC…
 *
 *   เข้าสู่หลังบ้าน IDTC
 *
 *   URI: https://idtc.online/admin
 *   Version: 1
 *   Chain ID: 4289
 *   Nonce: …
 *   Issued At: 2026-10-11T05:00:00Z
 *   Expiration Time: 2026-10-11T05:05:00Z
 */
final class SiweMessage
{
    public const STATEMENT = 'เข้าสู่หลังบ้าน IDTC';

    public function __construct(
        public readonly string $domain,
        public readonly string $address,
        public readonly string $statement,
        public readonly string $uri,
        public readonly string $version,
        public readonly int $chainId,
        public readonly string $nonce,
        public readonly string $issuedAt,
        public readonly ?string $expirationTime = null,
    ) {}

    public function toString(): string
    {
        $lines = [
            "{$this->domain} wants you to sign in with your Ethereum account:",
            $this->address,
            '',
            $this->statement,
            '',
            "URI: {$this->uri}",
            "Version: {$this->version}",
            "Chain ID: {$this->chainId}",
            "Nonce: {$this->nonce}",
            "Issued At: {$this->issuedAt}",
        ];
        if ($this->expirationTime !== null) {
            $lines[] = "Expiration Time: {$this->expirationTime}";
        }

        return implode("\n", $lines);
    }

    /** อ่านข้อความแบบเข้มงวด — ช่องอื่นนอกเหนือจากนี้ถือว่าไม่ใช่ข้อความของหลังบ้าน */
    public static function parse(string $text): ?self
    {
        $text = str_replace("\r\n", "\n", $text);
        $re = '/^(?<domain>[A-Za-z0-9.\-:\[\]]+) wants you to sign in with your Ethereum account:\n'
            .'(?<address>0x[0-9a-fA-F]{40})\n\n'
            .'(?<statement>[^\n]+)\n\n'
            .'URI: (?<uri>\S+)\n'
            .'Version: (?<version>\d+)\n'
            .'Chain ID: (?<chain>\d{1,12})\n'
            .'Nonce: (?<nonce>[A-Za-z0-9]{8,64})\n'
            .'Issued At: (?<issued>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2}))'
            .'(?:\nExpiration Time: (?<exp>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})))?$/u';

        if (! preg_match($re, $text, $m)) {
            return null;
        }

        return new self(
            domain: $m['domain'],
            address: $m['address'],
            statement: $m['statement'],
            uri: $m['uri'],
            version: $m['version'],
            chainId: (int) $m['chain'],
            nonce: $m['nonce'],
            issuedAt: $m['issued'],
            expirationTime: ($m['exp'] ?? '') !== '' ? $m['exp'] : null,
        );
    }
}
