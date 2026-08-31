{{--
    The statement as a document.

    Deliberately not the React page rendered to paper. mPDF supports a subset of CSS —
    no flexbox, no grid — so this is tables and inline rules, and it is written for a
    page rather than a screen: repeating headers, page numbers, and a footer that says
    which copy this is on every sheet.

    Every amount is printed from the string the ledger holds. The only thing done to it
    is grouping its digits for reading — string surgery on the integer part. Nothing
    here rounds, parses, or converts a number.
--}}
@php
    /** @var \App\Domain\Statement\CounterpartyStatement $statement */
    $showsProfit = $statement->mode->showsProfit();
    $columns = $showsProfit ? 6 : 5;
    $align = $rtl ? 'right' : 'left';
    $opposite = $rtl ? 'left' : 'right';
@endphp

<style>
    body { font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 2pt 0; }
    .muted { color: #666; }
    .small { font-size: 8pt; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #f1f1f1; border-bottom: 0.6pt solid #999; padding: 4pt; font-weight: bold; }
    table.grid td { border-bottom: 0.3pt solid #ddd; padding: 4pt; vertical-align: top; }
    table.grid tfoot td { border-top: 0.6pt solid #999; border-bottom: none; font-weight: bold; background: #fafafa; }
    .num { font-family: monospace; }
    .position { border: 0.5pt solid #999; padding: 6pt; }
    .warn { border: 0.5pt solid #b45309; background: #fdf6e7; padding: 6pt; margin-bottom: 8pt; }
    .stamp { border: 0.8pt solid #111; padding: 3pt 6pt; font-weight: bold; font-size: 8pt; }

    /*
       Layout tables. The widths have to live here rather than on the elements: mPDF
       ignores width attributes and inline widths on these and sizes each cell to its
       content, which pulls both ends of a header or footer into the middle of the
       page. Only visible in the right-to-left layout, where nothing is anchored left.
    */
    table.brand { width: 100%; margin-bottom: 5pt; }
    table.brand td.mark { width: 8%; }
    table.brand td.who { width: 92%; }
    /* Fixed sides, not direction-dependent ones: the mark and the wordmark are one
       lockup and have to stay against each other, so in Arabic the pair moves to the
       right edge together rather than each taking the far side of the page. */
    table.brand td.hug-left { text-align: left; }
    table.brand td.hug-right { text-align: right; }

    table.layout { width: 100%; }
    table.layout td.main { width: 70%; }
    table.layout td.side { width: 30%; }
    table.foot { width: 100%; }
    table.foot td { width: 50%; }

    /* Direction-dependent alignment, resolved once at render time. */
    .t-start { text-align: {{ $align }}; }
    .t-end { text-align: {{ $opposite }}; }
</style>

{{-- The copy's identity repeats on every page, so a sheet that gets separated from
     the rest still says whether it was meant to leave the building. --}}
<htmlpagefooter name="pageFooter">
    {{-- dir="ltr" on the table itself, with the cells placed by hand. mPDF lays a
         right-to-left table out shrink-to-fit and centres it, which pulls both ends
         into the middle of the page; forcing the table left-to-right and choosing the
         cell order gives the same result in both languages. --}}
    <table class="foot small muted" dir="ltr" style="border-top: 0.3pt solid #ccc; padding-top: 3pt;">
        <tr>
            @if ($rtl)
                <td class="t-end">{{ __('statements.page') }} {PAGENO} / {nbpg}</td>
                <td class="t-start">{{ $statement->mode->label() }}</td>
            @else
                <td class="t-start">{{ $statement->mode->label() }}</td>
                <td class="t-end">{{ __('statements.page') }} {PAGENO} / {nbpg}</td>
            @endif
        </tr>
    </table>
</htmlpagefooter>

<sethtmlpagefooter name="pageFooter" value="on" />

{{-- Whose document this is. A statement leaves the building, and without this it is
     a table of figures carrying the client's name and nothing saying who produced it.

     Same dir="ltr" trick as the footer: the table is laid out left-to-right and the
     cells are written in the order the language wants them. --}}
<table class="brand" dir="ltr">
    <tr>
        @if ($rtl)
            <td class="who hug-right"><img src="{{ $brandWordmark }}" style="width: 32mm;" /></td>
            <td class="mark hug-left"><img src="{{ $brandIcon }}" style="width: 11mm;" /></td>
        @else
            <td class="mark hug-left"><img src="{{ $brandIcon }}" style="width: 11mm;" /></td>
            <td class="who hug-left"><img src="{{ $brandWordmark }}" style="width: 32mm;" /></td>
        @endif
    </tr>
</table>

<table class="layout" dir="ltr">
    <tr>
        {{-- In Arabic the stamp belongs on the left, so it is written first. See the
             note on the footer table. --}}
        @if ($rtl)
            <td class="side t-end">
                <span class="stamp">{{ $statement->mode->label() }}</span>
            </td>
        @endif

        <td class="main t-start">
            <h1>{{ $statement->counterparty->name }}</h1>
            <div class="muted">
                {{ __('statements.title') }} · {{ $statement->currency->code }}
            </div>
            <div class="muted small">
                @if ($statement->from || $statement->to)
                    {{ __('statements.period', [
                        'from' => $statement->from?->toDateString() ?? '—',
                        'to' => $statement->to?->toDateString() ?? '—',
                    ]) }}
                @else
                    {{ __('statements.all_dates') }}
                @endif
                · {{ __('statements.generated_at', ['at' => now()->toDayDateTimeString()]) }}
            </div>
        </td>
        @unless ($rtl)
            <td class="side t-end">
                <span class="stamp">{{ $statement->mode->label() }}</span>
            </td>
        @endunless
    </tr>
</table>

<hr style="border: 0; border-top: 0.8pt solid #111; margin: 6pt 0 10pt 0;" />

@if ($statement->declaredOpening !== null)
    <div class="warn small">
        <strong>{{ __('statements.declared_opening') }}</strong><br />
        {{ __('statements.declared_opening_body') }}
        <br /><span dir="ltr" class="num">{{ $statement->declaredOpening->toGroupedString() }} {{ $statement->currency->code }}</span>
    </div>
@endif

{{-- The closing balance, stated in words as well as figures.

     One number now, where there were four. It is signed, and a minus sign on a page
     somebody is holding is the easiest thing in the world to misread — so the sentence
     beside it says which way it runs, and the figure is printed without its sign. --}}
@if ($statement->closing->isZero())
    <p class="position">{{ __('statements.settled') }}</p>
@else
    <p class="position">
        <span class="muted small">{{ __('statements.closing') }}</span><br />
        {{ $statement->theyOweUs()
            ? __('statements.they_owe_us', ['amount' => $statement->closing->toGroupedString().' '.$statement->currency->code])
            : __('statements.we_hold_theirs', ['amount' => $statement->closing->absolute()->toGroupedString().' '.$statement->currency->code]) }}
    </p>
@endif

<table class="grid">
    <thead>
        <tr>
            <th align="{{ $align }}" width="11%">{{ __('statements.columns.date') }}</th>
            <th align="{{ $align }}">{{ __('statements.columns.details') }}</th>
            <th align="{{ $opposite }}" width="15%">{{ __('statements.columns.in') }}</th>
            <th align="{{ $opposite }}" width="15%">{{ __('statements.columns.out') }}</th>
            <th align="{{ $opposite }}" width="20%">{{ __('statements.columns.position') }}</th>
            @if ($showsProfit)
                <th align="{{ $opposite }}" width="13%">{{ __('statements.columns.profit') }}</th>
            @endif
        </tr>
    </thead>

    <tbody>
        @forelse ($statement->rows as $row)
            <tr>
                <td align="{{ $align }}"><span dir="ltr">{{ $row->occurredAt->toDateString() }}</span></td>
                <td align="{{ $align }}">
                    {{ __('transactions.types.'.$row->type->value) }}
                    @if ($row->description || $row->reference)
                        <div class="muted small">
                            {{ collect([$row->description, $row->reference])->filter()->implode(' · ') }}
                        </div>
                    @endif
                </td>
                <td align="{{ $opposite }}" class="num">
                    @if ($row->in)<span dir="ltr">{{ $row->in->toGroupedString() }}</span>@endif
                </td>
                <td align="{{ $opposite }}" class="num">
                    @if ($row->out)<span dir="ltr">{{ $row->out->toGroupedString() }}</span>@endif
                </td>
                <td align="{{ $opposite }}">
                    <span dir="ltr" class="num">{{ $row->balanceAfter->toGroupedString() }}</span>
                    {{-- What actually changed hands, when it was not this statement's
                         currency: "10,000 USD at 50.85" beside a line booked in pounds. --}}
                    @if ($row->movedAmount)
                        <div class="muted small" dir="ltr">
                            {{ $row->movedAmount->toGroupedString() }} {{ $row->movedAmount->currency->code }}@if ($row->rate) @ {{ $row->rate }}@endif
                        </div>
                    @endif
                </td>
                @if ($showsProfit)
                    <td align="{{ $opposite }}" class="num">
                        @if ($row->profit)
                            <span dir="ltr">{{ $row->profit->toGroupedString() }} {{ $row->profit->currency->code }}</span>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $columns }}" align="center" class="muted" style="padding: 14pt;">
                    {{ __('statements.no_activity') }}
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($statement->rows !== [])
        <tfoot>
            <tr>
                <td colspan="2" align="{{ $align }}">{{ __('statements.totals') }}</td>
                <td align="{{ $opposite }}" class="num">
                    <span dir="ltr">{{ $statement->totalIn->toGroupedString() }}</span>
                </td>
                <td align="{{ $opposite }}" class="num">
                    <span dir="ltr">{{ $statement->totalOut->toGroupedString() }}</span>
                </td>
                <td align="{{ $opposite }}" class="num">
                    <span dir="ltr">{{ $statement->closing->toGroupedString() }}</span>
                </td>
                @if ($showsProfit)
                    <td></td>
                @endif
            </tr>
        </tfoot>
    @endif
</table>

@if ($showsProfit && $statement->profit !== [])
    <table width="100%" style="margin-top: 10pt;">
        <tr>
            <td class="position">
                <strong>{{ __('statements.profit_total') }}</strong>
                @foreach ($statement->profit as $code => $amount)
                    <span dir="ltr" class="num" style="padding-{{ $rtl ? 'right' : 'left' }}: 8pt;">
                        {{ $amount->toGroupedString() }} {{ $code }}
                    </span>
                @endforeach
            </td>
        </tr>
    </table>
@endif

<p class="muted small" style="margin-top: 10pt;">{{ __('statements.from_ledger') }}</p>
