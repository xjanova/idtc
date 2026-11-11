#!/usr/bin/env python
"""Thai edition: scene render + real network-map clip + Digen lip-sync narrator -> final MP4.

  python build/compose-th.py out/idtc-grid-plan-th-scene.mp4 out/idtc-grid-plan-th-1080p.mp4

Inputs (all under tmp/, not committed):
  tmp/grid-th/timing.json   cue times (map window = l35 .. l14)
  tmp/grid-th/voice.mp3     master narration (บัว, ElevenLabs)
  tmp/grid-th/map.mp4       recorded admin map with sample data (tmp/map-capture.mjs)
  tmp/lip/keys.json         key-moment windows [{id,a,b}] the voice was cut at for Digen GEN-3
  tmp/lip/<id>.mp4          Digen lip-sync clip per window, on solid magenta (k0..k4)
"""
import json
import subprocess
import sys
from pathlib import Path

FF = r"C:\Users\xman\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.2-full_build\bin\ffmpeg.exe"
ROOT = Path(__file__).resolve().parent.parent
scene, out = sys.argv[1], sys.argv[2]

timing = json.loads((ROOT / "tmp/grid-th/timing.json").read_text(encoding="utf-8"))
first = {}
for c in timing["lines"]:
    first.setdefault(c["line"], c["t0"])
map_in = first["l35"] - 0.1          # #mapHole fades in at l35-0.3 (0.5 s)
map_out = first["l14"] - 0.6         # the act fades out at l14-0.6
keys = json.loads((ROOT / "tmp/lip/keys.json").read_text())
missing = [k["id"] for k in keys if not (ROOT / f"tmp/lip/{k['id']}.mp4").exists()]
if missing:  # preview build only: those windows show an empty corner
    print("! lip clips missing, preview only:", ", ".join(missing))
    keys = [k for k in keys if k["id"] not in missing]
dur = timing["duration"]

# real map panel: .frame left 132 + #mapHole (0, 56) inside .frame top 152 -> (132, 208), 1180×664
MX, MY, MW, MH = 132, 208, 1180, 664
# narrator: bottom-right, waist-up, cut by the frame edge
NW, NH = 440, 660
NX, NY = 1920 - NW - 20, 1080 - NH

args = [FF, "-y", "-hide_banner", "-loglevel", "error", "-i", scene, "-i", str(ROOT / "tmp/grid-th/voice.mp3"),
        "-i", str(ROOT / "tmp/grid-th/map.mp4")]
for k in keys:
    args += ["-i", str(ROOT / f"tmp/lip/{k['id']}.mp4")]

f = []
mlen = map_out + 0.5 - map_in
f.append(f"[2:v]trim=duration={mlen:.3f},setpts=PTS-STARTPTS,fps=30,scale={MW}:{MH}:flags=lanczos,format=yuva420p,"
         f"fade=in:st=0:d=0.45:alpha=1,fade=out:st={mlen - 0.5:.3f}:d=0.5:alpha=1,setpts=PTS+{map_in:.3f}/TB[map]")
for i, k in enumerate(keys):
    d = k["b"] - k["a"]
    # fade only where a window opens or closes; back-to-back clips (b == next a) cut straight
    fin = i == 0 or abs(keys[i - 1]["b"] - k["a"]) > 0.01
    fout = i == len(keys) - 1 or abs(keys[i + 1]["a"] - k["b"]) > 0.01
    fx = (",fade=in:st=0:d=0.35:alpha=1" if fin else "") + (f",fade=out:st={d - 0.35:.3f}:d=0.35:alpha=1" if fout else "")
    # each clip is padded/trimmed to exactly its window so the lips stay on the master voice
    f.append(f"[{3 + i}:v]fps=30,setpts=PTS-STARTPTS,tpad=stop_mode=clone:stop_duration=2,trim=duration={d:.3f},setpts=PTS-STARTPTS,"
             # key tight (0.21): her skin leans pink and goes see-through at 0.30; shrink the matte 1 px to drop the magenta fringe
             f"scale={NW}:{NH}:flags=lanczos,setsar=1,chromakey=0xFF00FF:0.21:0.05,format=yuva444p,split[c{i}][m{i}];"
             f"[m{i}]alphaextract,erosion,boxblur=1[a{i}];[c{i}][a{i}]alphamerge,format=yuva420p{fx},setpts=PTS+{k['a']:.3f}/TB[k{i}]")
prev = "v0"
f.append(f"[0:v][map]overlay={MX}:{MY}:eof_action=pass[v0]")
for i in range(len(keys)):
    f.append(f"[{prev}][k{i}]overlay={NX}:{NY}:eof_action=pass[v{i + 1}]")
    prev = f"v{i + 1}"
f.append(f"[{prev}]format=yuv420p[v]")

args += ["-filter_complex", ";".join(f), "-map", "[v]", "-map", "1:a",
         "-c:v", "libx264", "-preset", "slow", "-crf", "19", "-pix_fmt", "yuv420p", "-profile:v", "high", "-level", "4.2",
         "-r", "30", "-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-ac", "2", "-shortest", "-movflags", "+faststart", out]
subprocess.run(args, check=True)
print("ok", out, f"map {map_in:.2f}-{map_out:.2f}s")
