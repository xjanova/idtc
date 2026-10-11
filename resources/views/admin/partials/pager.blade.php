@if ($paginator->hasPages())
    <nav class="pager" aria-label="เปลี่ยนหน้า">
        <span>หน้า {{ number_format($paginator->currentPage()) }} จาก {{ number_format($paginator->lastPage()) }} · ทั้งหมด {{ number_format($paginator->total()) }} รายการ</span>
        <span class="btns">
            @if ($paginator->onFirstPage())
                <span class="btn btn-ghost btn-sm" aria-disabled="true">← ก่อนหน้า</span>
            @else
                <a class="btn btn-ghost btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">← ก่อนหน้า</a>
            @endif
            @if ($paginator->hasMorePages())
                <a class="btn btn-ghost btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">ถัดไป →</a>
            @else
                <span class="btn btn-ghost btn-sm" aria-disabled="true">ถัดไป →</span>
            @endif
        </span>
    </nav>
@endif
