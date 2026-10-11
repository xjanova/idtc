@php use App\Support\AdminFormat as F; @endphp
@if ($indexState?->last_error)
    <div class="flash warn" role="alert">ดึงยอดขายรอบล่าสุดไม่สำเร็จ ({{ F::dateTime($indexState->last_run_at) }}): {{ $indexState->last_error }} — ยอดที่ดึงได้แล้วยังอยู่ครบ ระบบจะลองใหม่เองทุกนาที</div>
@elseif ($indexState && $indexState->last_run_at && $indexState->last_run_at->lt(now()->subMinutes(15)))
    <div class="flash warn" role="note">ตัวดึงยอดขายไม่ได้ทำงานมาตั้งแต่ {{ F::dateTime($indexState->last_run_at) }} — ตรวจว่า cron ของ schedule:run ยังทำงาน (ดู README) หรือกด "ซิงก์ตอนนี้"</div>
@endif
