@if ($paginator->hasPages())
    @php
        $lengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    @endphp
    <nav class="admin-pagination" role="navigation" aria-label="Постраничная навигация">
        <div class="pages">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="page-btn is-disabled" aria-disabled="true">‹ Назад</span>
            @else
                <a class="page-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Назад">‹ Назад</a>
            @endif

            {{-- Page numbers --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page-btn is-ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page-btn is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="page-btn" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a class="page-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Вперёд">Вперёд ›</a>
            @else
                <span class="page-btn is-disabled" aria-disabled="true">Вперёд ›</span>
            @endif
        </div>

        @if ($lengthAware)
            <div class="pager-info">
                Показано {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} из {{ $paginator->total() }}
            </div>
        @endif
    </nav>
@endif
