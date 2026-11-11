#!/usr/bin/env python
"""
tts.py — บทบรรยายอังกฤษ → เสียงพากย์ + ตารางเวลาสำหรับซับไทย

รับไฟล์บทในรูปแบบ script/epNN.json:
{
  "id": "ep01",
  "voice": "en-US-AndrewMultilingualNeural",
  "rate": "-6%",
  "lines": [
     {"id":"a1", "en":"TPIX Chain is ...", "th":"TPIX Chain คือ ...", "gap":0.45}
  ]
}

ผลลัพธ์:
  tmp/epNN/voice.mp3     เสียงพากย์ต่อกันแล้ว
  tmp/epNN/timing.json   {duration, lines:[{id,t0,t1,en,th}]}

เรียก:  python build/tts.py script/ep01.json
"""
import asyncio
import json
import os
import subprocess
import sys
from pathlib import Path

import edge_tts

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

ROOT = Path(__file__).resolve().parent.parent
FFDIR = Path(os.environ.get("FFMPEG_DIR", r"C:\Users\xman\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.2-full_build\bin"))
FFMPEG = str(FFDIR / "ffmpeg.exe") if FFDIR.exists() else "ffmpeg"
FFPROBE = str(FFDIR / "ffprobe.exe") if FFDIR.exists() else "ffprobe"
LEAD_IN = 0.9      # เงียบนำหน้าคลิป ให้ภาพตั้งตัวก่อนเสียงมา
TAIL = 1.6         # เงียบท้ายคลิป ให้ภาพจบสวย ๆ
DEFAULT_GAP = 0.42  # ช่องว่างระหว่างประโยค


def probe_duration(path: Path) -> float:
    out = subprocess.run(
        [FFPROBE, "-v", "error", "-show_entries", "format=duration",
         "-of", "default=nw=1:nk=1", str(path)],
        capture_output=True, text=True, check=True,
    )
    return float(out.stdout.strip())


async def synth_line(text: str, voice: str, rate: str, pitch: str, dest: Path):
    comm = edge_tts.Communicate(text, voice, rate=rate, pitch=pitch)
    await comm.save(str(dest))


async def main(script_path: Path):
    spec = json.loads(script_path.read_text(encoding="utf-8"))
    ep = spec["id"]
    voice = spec.get("voice", "en-US-AndrewMultilingualNeural")
    rate = spec.get("rate", "-6%")
    pitch = spec.get("pitch", "+0Hz")

    work = ROOT / "tmp" / ep
    work.mkdir(parents=True, exist_ok=True)
    for stale in work.glob("line-*.mp3"):
        stale.unlink()

    lines = spec["lines"]
    print(f"{ep}: {len(lines)} lines · voice={voice} rate={rate}")

    # สังเคราะห์ทีละบรรทัด (ทำพร้อมกันทีละ 4 เพื่อไม่ให้โดนจำกัดอัตรา)
    sem = asyncio.Semaphore(4)
    paths = []

    async def one(i, ln):
        p = work / f"line-{i:03d}.mp3"
        async with sem:
            for attempt in range(4):
                try:
                    await synth_line(ln["en"], voice, rate, pitch, p)
                    if p.exists() and p.stat().st_size > 512:
                        return
                except Exception as exc:  # noqa: BLE001
                    if attempt == 3:
                        raise
                    await asyncio.sleep(1.5 * (attempt + 1))
            raise RuntimeError(f"empty audio for line {i}")

    await asyncio.gather(*(one(i, ln) for i, ln in enumerate(lines)))
    paths = [work / f"line-{i:03d}.mp3" for i in range(len(lines))]

    # ประกอบไทม์ไลน์
    # `th` เป็นสตริงเดียว = ซับหนึ่งใบตลอดประโยค
    # `th` เป็นลิสต์      = แบ่งซับหลายใบในประโยคเดียว ตามสัดส่วนความยาวตัวอักษร
    timing, cursor = [], LEAD_IN
    concat_parts = []
    for i, (ln, p) in enumerate(zip(lines, paths)):
        d = probe_duration(p)
        gap = float(ln.get("gap", DEFAULT_GAP))
        lid = ln.get("id", f"l{i}")
        parts = ln["th"] if isinstance(ln["th"], list) else [ln["th"]]
        weights = [max(len(x), 1) for x in parts]
        span, acc = d / sum(weights), 0.0
        for j, (txt, w) in enumerate(zip(parts, weights)):
            t0 = cursor + acc
            acc += span * w
            timing.append({
                "id": lid if len(parts) == 1 else f"{lid}.{j + 1}",
                "line": lid,
                "t0": round(t0, 3),
                "t1": round(cursor + acc, 3),
                "en": ln["en"] if j == 0 else "",
                "th": txt,
            })
        concat_parts.append((p, gap))
        cursor += d + gap

    total = round(cursor - concat_parts[-1][1] + TAIL, 3)

    # ต่อไฟล์เสียง: ใส่ความเงียบนำหน้า + คั่นแต่ละประโยค + ท้ายคลิป
    filt, inputs = [], []
    for i, (p, _) in enumerate(concat_parts):
        inputs += ["-i", str(p)]
    n = len(concat_parts)
    # สร้างความเงียบด้วย anullsrc แล้วต่อกัน
    chain = []
    silence_specs = [LEAD_IN] + [g for _, g in concat_parts[:-1]] + [TAIL]
    for i, s in enumerate(silence_specs):
        filt.append(
            f"anullsrc=channel_layout=mono:sample_rate=24000,atrim=0:{max(s, 0.001):.3f}[s{i}]"
        )
    for i in range(n):
        filt.append(f"[{i}:a]aformat=sample_fmts=fltp:sample_rates=24000:channel_layouts=mono[a{i}]")
    chain.append("[s0]")
    for i in range(n):
        chain.append(f"[a{i}]")
        chain.append(f"[s{i + 1}]")
    filt.append("".join(chain) + f"concat=n={len(chain)}:v=0:a=1,"
                "loudnorm=I=-17:TP=-1.5:LRA=9,aresample=48000[out]")

    voice_mp3 = work / "voice.mp3"
    cmd = [FFMPEG, "-y", "-hide_banner", "-loglevel", "error", *inputs,
           "-filter_complex", ";".join(filt), "-map", "[out]",
           "-c:a", "libmp3lame", "-q:a", "2", str(voice_mp3)]
    subprocess.run(cmd, check=True)

    real = probe_duration(voice_mp3)
    (work / "timing.json").write_text(
        json.dumps({"id": ep, "duration": round(real, 3), "voice": voice, "lines": timing},
                   ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    # ฉากอ่านเวลาจาก timing.js (โหลดด้วย <script> ได้แน่นอนบน file://)
    (work / "timing.js").write_text(
        "window.TIMING = " + json.dumps({"duration": round(real, 3), "lines": timing}, ensure_ascii=False) + ";\n",
        encoding="utf-8",
    )

    # ซับสำหรับหน้าเว็บ (<track>): ไทยตามซับในคลิป · อังกฤษตามบทพากย์ทั้งประโยค
    def ts(v):
        h, r = divmod(v, 3600)
        m, s2 = divmod(r, 60)
        return f"{int(h):02d}:{int(m):02d}:{s2:06.3f}"

    th_vtt = ["WEBVTT", ""]
    for i, c in enumerate(timing, 1):
        th_vtt += [str(i), f"{ts(c['t0'])} --> {ts(c['t1'])}", c["th"], ""]
    (work / "subs-th.vtt").write_text("\n".join(th_vtt), encoding="utf-8")
    en_vtt = ["WEBVTT", ""]
    for n, ln in enumerate(lines, 1):
        cues = [c for c in timing if c["line"] == ln.get("id", f"l{n - 1}")]
        en_vtt += [str(n), f"{ts(cues[0]['t0'])} --> {ts(cues[-1]['t1'])}", ln["en"], ""]
    (work / "subs-en.vtt").write_text("\n".join(en_vtt), encoding="utf-8")

    print(f"  → {voice_mp3.name}  {real:.2f}s  (planned {total:.2f}s)")
    print(f"  → timing.json  {len(timing)} cues")


if __name__ == "__main__":
    asyncio.run(main(Path(sys.argv[1])))
