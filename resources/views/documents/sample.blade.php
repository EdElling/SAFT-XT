{{-- Probeseite für das Briefpapier (Einstellungen › Dokumente): Betreff, Positionen über zwei Seiten (wiederholter Tabellenkopf), Blindtext. Das Adressfeld setzt der PdfRenderer. --}}
<p class="rechts">{{ now()->translatedFormat('j. F Y') }}</p>
<h1>{{ __('documents.sample.title') }}</h1>
<p>{{ __('documents.sample.intro') }}</p>

<table class="positionen">
    <thead>
        <tr><th width="12%">{{ __('documents.sample.qty') }}</th><th width="63%">{{ __('documents.sample.text') }}</th><th class="rechts" width="25%">{{ __('documents.sample.amount') }}</th></tr>
    </thead>
    <tbody>
        @foreach (range(1, 30) as $i)
            <tr><td width="12%">{{ $i }}</td><td width="63%">{{ __('documents.sample.item', ['n' => $i]) }}</td><td class="rechts" width="25%">{{ number_format($i * 12.5, 2, '.', "'") }}</td></tr>
        @endforeach
        <tr class="summe"><td width="12%"></td><td width="63%">{{ __('documents.sample.total') }}</td><td class="rechts" width="25%">{{ number_format(array_sum(range(1, 30)) * 12.5, 2, '.', "'") }}</td></tr>
    </tbody>
</table>

@foreach (range(1, 4) as $i)
    <p>{{ __('documents.sample.lorem') }}</p>
@endforeach

<p class="sekundaer klein">
    {{ implode(' · ', array_filter([$letterhead->sender['firma'], $letterhead->sender['mwst'] ? 'MWST '.$letterhead->sender['mwst'] : null, $letterhead->sender['iban'] ? 'IBAN '.$letterhead->sender['iban'] : null])) }}
</p>
