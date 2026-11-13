{{-- กล่อง QR ของลิงก์ชวน (Alpine.store('qr') ใน resources/js/qr.js) — ใส่ครั้งเดียวต่อ layout --}}
<div class="qr-modal" x-data x-show="$store.qr.open" x-cloak @keydown.escape.window="$store.qr.open && $store.qr.close()" role="dialog" aria-modal="true" aria-labelledby="qrTitle" data-testid="qr-modal">
    <div class="qr-card" @click.outside="$store.qr.close()">
        <h3 id="qrTitle">{{ __('site.qr.title') }}</h3>
        <p class="qr-label" x-text="$store.qr.label"></p>
        <div class="qr-img">
            <template x-if="$store.qr.src"><img :src="$store.qr.src" width="240" height="240" alt="{{ __('site.qr.alt') }}"></template>
            <p class="qr-failed" x-show="$store.qr.failed">{{ __('site.qr.failed') }}</p>
        </div>
        <input class="qr-text mono" type="text" readonly :value="$store.qr.text" @focus="$event.target.select()" aria-label="{{ __('site.qr.link') }}">
        <div class="qr-actions">
            <button class="btn btn-primary btn-sm" type="button" @click="$store.qr.download()" :disabled="!$store.qr.src">{{ __('site.qr.download') }}</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="$store.qr.copy()" x-text="$store.qr.copied ? @js(__('site.qr.copied')) : @js(__('site.qr.copy'))">{{ __('site.qr.copy') }}</button>
            <button class="btn btn-ghost btn-sm" type="button" data-qr-close @click="$store.qr.close()">{{ __('site.qr.close') }}</button>
        </div>
        <p class="qr-note">{{ __('site.qr.note') }}</p>
    </div>
</div>
