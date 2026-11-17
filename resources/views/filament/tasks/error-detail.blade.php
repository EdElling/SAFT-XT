{{-- Technische Details eines fehlgeschlagenen Laufs (TaskRunsRelationManager, Recht tasks.details). --}}
<div class="saft-error-detail" data-error-reference="{{ $detail['reference'] ?? '' }}">
    <p class="saft-error-detail-hint">{{ __('tasks.error_detail.hint', ['reference' => $detail['reference'] ?? '']) }}</p>
    <dl>
        <dt>{{ __('tasks.error_detail.error') }}</dt>
        <dd><code>{{ $detail['class'] ?? '' }}</code></dd>
        <dt>{{ __('tasks.error_detail.message') }}</dt>
        <dd><pre>{{ $detail['message'] ?? '' }}</pre></dd>
        <dt>{{ __('tasks.error_detail.location') }}</dt>
        <dd><code>{{ $detail['location'] ?? '' }}</code></dd>
        @if (! empty($detail['causes']))
            <dt>{{ __('tasks.error_detail.causes') }}</dt>
            <dd><pre>{{ implode("\n", $detail['causes']) }}</pre></dd>
        @endif
        @if (! empty($detail['trace']))
            <dt>{{ __('tasks.error_detail.trace') }}</dt>
            <dd><pre>{{ implode("\n", $detail['trace']) }}</pre></dd>
        @endif
    </dl>
</div>
