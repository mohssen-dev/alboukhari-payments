@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <div class="pagination-info">
            {!! __('Showing :first to :last of :total', [
                'first' => $paginator->firstItem() ?? 0,
                'last' => $paginator->lastItem() ?? 0,
                'total' => $paginator->total(),
            ]) !!}
        </div>

        <div class="pagination-controls">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="page-btn disabled" aria-disabled="true">‹</span>
            @else
                <button
                    type="button"
                    class="page-btn"
                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    rel="prev"
                >‹</button>
            @endif

            {{-- Page links --}}
            @foreach ($elements as $element)
                {{-- "..." separator --}}
                @if (is_string($element))
                    <span class="page-btn disabled" aria-disabled="true">…</span>
                @endif

                {{-- Array of links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page-btn active" aria-current="page">{{ $page }}</span>
                        @else
                            <button
                                type="button"
                                class="page-btn"
                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                wire:loading.attr="disabled"
                            >{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <button
                    type="button"
                    class="page-btn"
                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    wire:loading.attr="disabled"
                    rel="next"
                >›</button>
            @else
                <span class="page-btn disabled" aria-disabled="true">›</span>
            @endif
        </div>
    </nav>
@endif
