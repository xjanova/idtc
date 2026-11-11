#!/usr/bin/env python
"""Voice loudness per video frame -> tmp/<id>/envelope.js (window.ENVELOPE = [0..1, ...] at 30 fps).

The narrator character uses it to bob and "talk" in step with the voice.
Call:  python build/envelope.py tmp/grid-th/voice.mp3
"""
import re
import subprocess
import sys
from pathlib import Path

FFMPEG = r"C:\Users\xman\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.2-full_build\bin\ffmpeg.exe"
FPS = 30
RATE = 48000

voice = Path(sys.argv[1])
meta = voice.with_name("envelope.txt")
subprocess.run([
    FFMPEG, "-v", "error", "-y", "-i", voice.name,
    "-af", f"aresample={RATE},pan=mono|c0=c0,asetnsamples={RATE // FPS},"
           f"astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file={meta.name}",
    "-f", "null", "-",
], check=True, cwd=voice.parent)

db = [float(v) if v not in ("-inf", "inf", "nan") else -120.0
      for v in re.findall(r"RMS_level=(\S+)", meta.read_text())]
meta.unlink()
# -50 dB (silence) .. -14 dB (loud speech) -> 0..1, then a light 3-frame smooth
lin = [min(1.0, max(0.0, (d + 50) / 36)) for d in db]
sm = [sum(lin[max(0, i - 1):i + 2]) / len(lin[max(0, i - 1):i + 2]) for i in range(len(lin))]
out = voice.with_name("envelope.js")
out.write_text("window.ENVELOPE=[" + ",".join(f"{v:.2f}" for v in sm) + "];\n", encoding="utf-8")
print(f"{out}  {len(sm)} frames ({len(sm) / FPS:.1f}s)")
