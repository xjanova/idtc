<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## หลังบ้าน IDTC (ระบบควบคุมเหรียญ) — `/admin`

เข้าด้วยการเซ็นข้อความในกระเป๋า (EIP-4361) ไม่มีรหัสผ่าน และเซิร์ฟเวอร์ไม่ถือคีย์ใด ๆ — คำสั่งบนเชน (หยุดขาย ตั้งเวลา เปลี่ยนกระเป๋ารับเงิน ฯลฯ) เซ็นในกระเป๋าบนเบราว์เซอร์เท่านั้น

- **เจ้าของคนแรก:** ใส่ที่อยู่กระเป๋าใน `.env` → `IDTC_ADMIN_WALLETS=0xเจ้าของ,0xแอดมิน2` (ตัวแรก = owner, ที่เหลือ = admin) แล้วเข้า `/admin/login`
- **ผู้ดูแลเพิ่มเติม:** owner เพิ่มจากหน้า "ผู้ดูแล" (สิทธิ์ owner / admin / viewer) หรือทางสำรองบนเซิร์ฟเวอร์: `php artisan idtc:admin 0xที่อยู่ --role=admin`
- **ที่อยู่สัญญา:** ตั้งจากหน้า "ตั้งค่าเหรียญ" (ตรวจ `eth_getCode` ก่อนบันทึก) หรือใน `.env` (`IDTC_TOKEN_ADDRESS`, `IDTC_VESTING_ADDRESS`, `IDTC_PRESALE_ADDRESS`, `IDTC_PAIR_USDT`, `IDTC_PAIR_TPIX`) — ค่าจากหลังบ้านแก้ทับ `.env`

### งานตามเวลา (ดึงยอดขายจากเชน)

`php artisan idtc:index-sales` ดึง event `Purchased` ของสัญญา presale ลงฐานข้อมูล (ทีละ 5,000 บล็อก รันซ้ำได้ไม่เกิดรายการซ้ำ) และตามผลธุรกรรมของหลังบ้านที่ค้างอยู่ — ตั้งไว้ใน `routes/console.php` ให้รันทุกนาที

ใน DirectAdmin → Cron Jobs เพิ่มบรรทัดเดียว:

```
* * * * * cd /home/admin/domains/idtc.online && php artisan schedule:run >> /dev/null 2>&1
```

ปุ่ม "ซิงก์ตอนนี้" ในหลังบ้านรันคำสั่งเดียวกันทันที · ถ้าตั้งที่อยู่ presale หลังเปิดขายแล้ว ให้ใส่ "บล็อกเริ่ม" (บล็อกที่ deploy สัญญา) ในหน้าตั้งค่าเหรียญเพื่อดึงยอดย้อนหลัง
