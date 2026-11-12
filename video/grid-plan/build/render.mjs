/* ===========================================================
   render.mjs — ฉาก HTML → เฟรม → MP4
   เดินเวลาเองทีละเฟรมผ่าน window.TPIX.seek(t) จึงได้ผลตรงเป๊ะ
   ทุกครั้ง ไม่ขึ้นกับความเร็วเครื่อง

   ใช้:
     node build/render.mjs --scene scenes/ep01.html --out out/ep01.mp4 \
                           --audio tmp/ep01.mp3
     node build/render.mjs --scene scenes/ep01.html --still 6.5 \
                           --out tmp/preview.png
   =========================================================== */

import puppeteer from 'puppeteer-core';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const FFMPEG = existsSync(process.env.FFMPEG || '') ? process.env.FFMPEG
    : 'C:\\Users\\xman\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0.2-full_build\\bin\\ffmpeg.exe';

// ---------- args ----------
const args = {};
for (let i = 2; i < process.argv.length; i++) {
    const a = process.argv[i];
    if (a.startsWith('--')) args[a.slice(2)] = process.argv[i + 1]?.startsWith('--') ? true : process.argv[++i];
}
const SCENE = resolve(args.scene);
const OUT = args.out ? resolve(args.out) : null;
const FPS = parseInt(args.fps || 30, 10);
const STILL = args.still !== undefined ? parseFloat(args.still) : null;
const AUDIO = args.audio ? resolve(args.audio) : null;
const CRF = args.crf || '17';

if (OUT) mkdirSync(dirname(OUT), { recursive: true });

// ---------- browser ----------
const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: [
        '--hide-scrollbars',
        '--force-color-profile=srgb',
        '--font-render-hinting=none',
        '--disable-lcd-text',              // ไม่เอา subpixel — วิดีโอจะเห็นขอบสี
        '--disable-font-subpixel-positioning',
        '--allow-file-access-from-files',
        '--disable-web-security',
        '--enable-unsafe-swiftshader',
        '--disable-dev-shm-usage',
        '--window-size=1920,1080',
    ],
});

const page = await browser.newPage();
await page.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 1 });
page.on('console', (m) => { if (m.type() === 'error') console.error('  [page]', m.text()); });
page.on('pageerror', (e) => console.error('  [page:error]', e.message));

// --query "tail=k4": passed to the scene as location.search
await page.goto(pathToFileURL(SCENE).href + (args.query ? '?' + args.query : ''), { waitUntil: 'load' });
await page.waitForFunction(() => window.TPIX && window.TPIX.ready === true, { timeout: 20000 });
await page.evaluate(async () => {
    await document.fonts.ready;
    // ให้รูปทุกใบถอดรหัสเสร็จก่อนเฟรมแรก
    await Promise.all(
        Array.from(document.images).map((i) => (i.complete ? Promise.resolve() : i.decode().catch(() => {})))
    );
});

const duration = args.dur ? parseFloat(args.dur) : await page.evaluate(() => window.TPIX.duration);
if (!duration || !isFinite(duration)) { console.error('scene did not report a duration'); process.exit(1); }

// ---------- still preview ----------
if (STILL !== null) {
    await page.evaluate((t) => window.TPIX.seek(t), STILL);
    const buf = await page.screenshot({ type: 'png' });
    writeFileSync(OUT, buf);
    console.log(`still @ ${STILL}s → ${OUT}`);
    await browser.close();
    process.exit(0);
}

// ---------- contact sheet: หลายเฟรมในรอบเดียว ----------
if (args.stills) {
    const times = String(args.stills).split(',').map(Number);
    const dir = resolve(args.outdir || 'tmp/stills');
    mkdirSync(dir, { recursive: true });
    for (const t of times) {
        await page.evaluate((tt) => window.TPIX.seek(tt), t);
        const buf = await page.screenshot({ type: 'png' });
        const f = `${dir}\\t${String(Math.round(t)).padStart(3, '0')}.png`;
        writeFileSync(f, buf);
        console.log(`  ${String(t).padStart(6)}s → ${f}`);
    }
    await browser.close();
    process.exit(0);
}

// ---------- ffmpeg ----------
const total = Math.round(duration * FPS);
// --from <s>: render only the tail from that time (to patch one section without a full re-render)
const F0 = args.from ? Math.max(0, Math.min(total - 1, Math.round(parseFloat(args.from) * FPS))) : 0;
const ff = [
    '-y', '-hide_banner', '-loglevel', 'error',
    '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'png', '-i', 'pipe:0',
];
if (AUDIO) ff.push('-i', AUDIO);
ff.push(
    '-c:v', 'libx264', '-preset', 'slow', '-crf', CRF,
    '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.2',
    '-x264-params', 'ref=4:bframes=3:aq-mode=3',
    '-r', String(FPS), '-movflags', '+faststart'
);
if (AUDIO) ff.push('-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2', '-shortest');
ff.push(OUT);

const proc = spawn(FFMPEG, ff, { stdio: ['pipe', 'inherit', 'inherit'] });

// ---------- frame loop ----------
const t0 = Date.now();
for (let f = F0; f < total; f++) {
    const t = f / FPS;
    await page.evaluate((tt) => window.TPIX.seek(tt), t);
    const buf = await page.screenshot({ type: 'png', optimizeForSpeed: true });
    if (!proc.stdin.write(buf)) await once(proc.stdin, 'drain');

    if (f % Math.max(1, Math.round(total / 20)) === 0 || f === total - 1) {
        const pct = ((f + 1) / total) * 100;
        const el = (Date.now() - t0) / 1000;
        const eta = el / (f + 1 - F0) * (total - f - 1);
        process.stdout.write(
            `\r  frame ${f + 1}/${total}  ${pct.toFixed(0).padStart(3)}%  ` +
            `${((f + 1 - F0) / el).toFixed(1)} fps  eta ${eta.toFixed(0)}s   `
        );
    }
}
process.stdout.write('\n');

proc.stdin.end();
await once(proc, 'close');
await browser.close();

console.log(`✓ ${OUT}  ·  ${duration.toFixed(1)}s @ ${FPS}fps  ·  ${((Date.now() - t0) / 1000).toFixed(0)}s render`);
