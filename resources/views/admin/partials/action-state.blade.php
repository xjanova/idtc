<div class="act-state" :class="state" x-show="msg" x-cloak role="status" aria-live="polite">
    <span x-show="working" class="spin" aria-hidden="true"></span><span x-text="msg"></span>
    <template x-if="hash"><span> · <a :href="explorer" target="_blank" rel="noopener">ดูธุรกรรม ↗</a></span></template>
</div>
