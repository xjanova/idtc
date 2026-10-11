<?php

namespace App\Services\Auth;

use RuntimeException;

/** เข้าสู่ระบบไม่ผ่าน — getMessage() เป็นภาษาไทยพร้อมแสดง · $reason ใช้แยกกรณี (และในเทสต์) */
class SiweException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
