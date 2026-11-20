{{-- Schnittstellen einer App (B-107, Etappe 5): was sie freigibt, wer es nutzt, was sie von anderen Apps nutzt. --}}
@php /** @var list<array{table: \App\Models\CustomTable, fields: list<string>, usages: list<string>}> $offered */ @endphp
<div class="space-y-4 text-sm" data-app-interfaces>
    <div>
        <p class="font-semibold">{{ __('apps.interfaces.offered') }} ({{ __('apps.interfaces.version', ['version' => $version]) }})</p>
        @forelse ($offered as $entry)
            <div class="mt-2" data-interface-table="{{ $entry['table']->name }}">
                <p>{{ $entry['table']->label }} <span class="text-gray-500">({{ $entry['table']->name }})</span></p>
                <p class="text-gray-500">{{ __('apps.interfaces.fields') }}: {{ implode(', ', ['id', ...$entry['fields']]) }}</p>
                <p>{{ $entry['usages'] === [] ? __('apps.interfaces.unused') : __('apps.interfaces.used_by', ['usages' => implode(', ', $entry['usages'])]) }}</p>
            </div>
        @empty
            <p class="text-gray-500">{{ __('apps.interfaces.none_offered') }}</p>
        @endforelse
    </div>
    <div>
        <p class="font-semibold">{{ __('apps.interfaces.uses') }}</p>
        @if ($uses === [])
            <p class="text-gray-500">{{ __('apps.interfaces.none_used') }}</p>
        @else
            <ul class="list-disc ps-5" data-interface-uses>
                @foreach ($uses as $use)
                    <li>{{ $use }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
