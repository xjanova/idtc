/**
 * Text for the browser side of idtc.online, in the language of the current page.
 *
 * The server injects the dictionary as window.IDTC.i18n (lang/{th,en}/js.php, App\Support\Idtc::publicConfig),
 * so no user-facing copy lives in the JS bundle. Placeholders use Laravel's ":name" syntax.
 * Numbers always use en-US digits; dates follow the page language (th-TH = Buddhist year, en-GB = Gregorian).
 */
const CFG = (typeof window !== 'undefined' && window.IDTC) || {};
const DICT = CFG.i18n && typeof CFG.i18n === 'object' ? CFG.i18n : {};

export const LOCALE = CFG.locale || 'th';
export const DATE_LOCALE = DICT.date_locale || (LOCALE === 'th' ? 'th-TH' : 'en-GB');

/** t('presale.status.live') · t('lock.month', { n: 13 }) — unknown keys return the key itself (visible in QA, never blank) */
export function t(key, vars) {
    const hit = String(key).split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), DICT);
    let text = typeof hit === 'string' ? hit : String(key);
    if (vars) {
        // longest names first so ":total" is not eaten by ":t"
        for (const name of Object.keys(vars).sort((a, b) => b.length - a.length)) text = text.split(`:${name}`).join(String(vars[name]));
    }
    return text;
}

/** Large token amounts: Thai counts in ล้าน (10^6), English in billion (10^9) */
export const BIG_EXP = Number(DICT.big?.exp ?? 6);
export const BIG_UNIT = DICT.big?.unit ?? '';

/** Date (and time) in the page language */
export function localDate(ts, withTime = false) {
    if (!ts) return '—';
    const opt = withTime
        ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: 'numeric', month: 'long', year: 'numeric' };
    return new Date(Number(ts) * 1000).toLocaleString(DATE_LOCALE, opt);
}

/** Link to a public page in the current language (server-provided URLs) */
export const pageUrl = (name, fallback = '/') => CFG.urls?.[name] || fallback;

/* ───────────── language choice (cookie read by the server, never encrypted) ───────────── */
const COOKIE = 'idtc_locale';

export function chosenLocale() {
    const m = document.cookie.match(/(?:^|;\s*)idtc_locale=([a-z]{2})(?:;|$)/);
    return m ? m[1] : null;
}

export function rememberLocale(code) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${code}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
}
