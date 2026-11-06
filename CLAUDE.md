# IDTC v2 — idtc.online

Laravel 13 + Blade + Tailwind 4 + Alpine + Vite (the xmanstudio stack). Data in SQLite (`database/database.sqlite`, outside the web root). The web root is `public_html/` (DirectAdmin), not `public/`.

## Talk to the owner in Thai
All replies and progress lines go to the owner in Thai. Code, commits and logs stay in English.

## Run locally
- PHP: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe`. Composer: `C:\laragon\bin\composer\composer.phar`.
- `php artisan serve --port=8090` (preview config: `.claude/launch.json`, name `idtc`), `npx vite build`, `php artisan test`.

## What this site is
- Public site for the IDTC token on **TPIX Chain** (chainId 4289, gas price 0, RPC `https://rpc1.tpix.online`).
  - That RPC allows CORS from idtc.online, so the browser reads chain data directly.
  - Do NOT use `rpc.tpix.online`: Cloudflare returns 403 to non-browser clients.
- Contracts (sources in the TPIX-Coin repo, `contracts/src/launch/`):
  - IDTC token: a FactoryERC20V2 made by the TPIX Token Factory. Standard, no mint/pause/blacklist.
  - `TrancheVesting`: the 70% lock, schedule A. Owner-less; anyone can call `release()`.
  - `FixedPriceSale`: the presale. Immutable rate 2e18/3 per micro-USDT, i.e. 1 USDT = 666,666.67 IDTC.
  - `PoolFeeder` (`contracts.feeder`): created with the sale by TPIX `LaunchpadFactory`. It is the sale's permanent owner and its `treasury`: sale USDT goes into the IDTC/USDT pool and the LP is locked until `launchpad.lp_unlock`. Owner-only sale calls (pause, window, limits, unsold) must go through the feeder (`pauseSale`, `setSaleWindow`, `setSaleLimits`, `withdrawUnsold`). Calling them on the sale directly reverts.
    - Admin: when the presale's on-chain owner is `contracts.feeder`, `/admin/presale` switches to the `feeder.*` actions (`App\Services\Admin\ChainActions` + `resources/js/admin-chain.js`, kept in sync by `FeederActionsTest`) and hides setTreasury / transferOwnership of the sale (the feeder has neither). Feeder state comes from `App\Services\Chain\FeederReader`. LP, reserve, leftover USDT and unsold tokens can only leave after `lpUnlockTime`.
  - Sale dates, pool share and LP unlock: `config/idtc.php` → `sale.start/end` and `launchpad.*`, formatted by `App\Support\Launch`. They must match TPIX-Coin `contracts/scripts/launch-plans/idtc.json`.
  - USDT_TPIX (6 decimals) and TPIX DEX (Uniswap V2 fork).
- All chain constants and plan numbers: `config/idtc.php`, read through `App\Support\Idtc` (Blade/PHP) and `window.IDTC` (JS, `resources/js/chain.js`).
  - Contract addresses may be empty (not deployed yet). Every page must show a clear "รอ deploy" state instead of breaking.
- Two languages on the public site (admin stays Thai): Thai at the current URLs, English under `/en` (route names `en.*`).
  - Every user-facing string lives in `lang/th/*.php` and `lang/en/*.php` (same keys, a test checks). Link with `lroute('presale')`, never `route()`.
  - JavaScript text comes from `lang/*/js.php` via `window.IDTC.i18n` (`resources/js/i18n.js`): no Thai literals in JS.
  - English pages must contain no Thai characters; `tests/Feature/LocalizationTest.php` scans them.
- Design: the owner-approved mockup is in `design/mockup/index.html`, and the styles are ported to `resources/css/app.css`.
  - Keep the night-energy look: tokens on `:root`, Anuphan display, Noto Sans Thai body, JetBrains Mono for numbers.
  - Illustrative AI images carry the label "ภาพประกอบ" ("Illustration" on English pages).

## Hard rules
- The server never holds a private key. Every on-chain write is signed in the user's or admin's own wallet in the browser (ethers v6 BrowserProvider).
- Never invent facts: partners, audits, team members, returns or real projects. Every number must trace back to `config/idtc.php`, a contract, or a cited source.
- No promises of profit or yield. Keep the risk notice. Thai SEC digital-asset rules apply.
- New images: generate them fresh with ChatGPT (owner rule). Never use stock placeholders.
