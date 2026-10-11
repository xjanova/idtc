<?php

namespace App\Services\Chain;

use RuntimeException;

/**
 * ข้อผิดพลาดจากการคุยกับเชน — getMessage() เป็นภาษาไทยที่แสดงให้แอดมินเห็นได้เลย
 * (ไม่มีรายละเอียดภายในของเซิร์ฟเวอร์ปน)
 */
class RpcException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'rpc', public readonly ?string $rpcMessage = null)
    {
        parent::__construct($message);
    }

    /** ข้อผิดพลาดที่บอกว่าช่วงบล็อกกว้างไป/ผลลัพธ์เยอะไป (ใช้ลดขนาดช่วงของ eth_getLogs) */
    public function isRangeError(): bool
    {
        return $this->kind === 'jsonrpc'
            && (bool) preg_match('/range|too many|limit|exceed|more than|too large/i', (string) $this->rpcMessage);
    }
}
