@include('filament-xlsx-import::styles')

@php
    $t = fn (string $key, array $r = []): string => __('filament-xlsx-import::filament-xlsx-import.'.$key, $r);
@endphp

@if (! is_array($preview))
    <p class="xi-note">{{ $t('preview_none') }}</p>
@else
    <div class="xi-stats">
        <div class="xi-stat xi-stat-created"><div class="xi-stat-n">{{ number_format($preview['created']) }}</div><div class="xi-stat-l">{{ $t('stat_created') }}</div></div>
        <div class="xi-stat xi-stat-updated"><div class="xi-stat-n">{{ number_format($preview['updated']) }}</div><div class="xi-stat-l">{{ $t('stat_updated') }}</div></div>
        <div class="xi-stat"><div class="xi-stat-n">{{ number_format($preview['skipped']) }}</div><div class="xi-stat-l">{{ $t('stat_skipped') }}</div></div>
        <div class="xi-stat xi-stat-failed"><div class="xi-stat-n">{{ number_format($preview['failed']) }}</div><div class="xi-stat-l">{{ $t('stat_failed') }}</div></div>
    </div>

    <p class="xi-note" style="margin-top: .75rem">
        {{ $t('preview_nothing_written') }}
        @if ($preview['truncated'])
            {{ $t('preview_truncated', ['checked' => number_format($preview['processed']), 'total' => number_format($rows)]) }}
        @endif
        @if (($preview['duplicates'] ?? 0) > 0)
            {{ $t('preview_duplicates', ['count' => number_format($preview['duplicates'])]) }}
        @endif
        @if ($queued)
            {{ $t('preview_queued') }}
        @endif
    </p>

    @if (! empty($preview['samples']['updated']))
        <div class="xi-h">{{ $t('preview_updates') }}</div>
        <table class="xi-table">
            <thead><tr><th>{{ $t('preview_row') }}</th><th>{{ $t('preview_record') }}</th><th>{{ $t('preview_changes') }}</th></tr></thead>
            <tbody>
                @foreach ($preview['samples']['updated'] as $sample)
                    <tr>
                        <td>{{ $sample['row'] }}</td>
                        <td>{{ $sample['label'] }}</td>
                        <td>
                            @foreach ($sample['changes'] as $field => $change)
                                <div><strong>{{ $field }}:</strong> <span class="xi-old">{{ $change[0] ?? '—' }}</span> → {{ $change[1] ?? '—' }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (! empty($preview['samples']['created']))
        <div class="xi-h">{{ $t('preview_creates') }}</div>
        <table class="xi-table">
            <thead><tr><th>{{ $t('preview_row') }}</th><th>{{ $t('preview_values') }}</th></tr></thead>
            <tbody>
                @foreach ($preview['samples']['created'] as $sample)
                    <tr>
                        <td>{{ $sample['row'] }}</td>
                        <td>
                            @foreach ($sample['changes'] as $field => $change)
                                <span class="xi-chip" style="display: inline-block">{{ $field }}: {{ $change[1] ?? '—' }}</span>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (! empty($preview['samples']['errors']))
        <div class="xi-h">{{ $t('preview_errors') }}</div>
        <table class="xi-table">
            <thead><tr><th>{{ $t('preview_row') }}</th><th>{{ $t('preview_reason') }}</th></tr></thead>
            <tbody>
                @foreach ($preview['samples']['errors'] as $sample)
                    <tr><td>{{ $sample['row'] }}</td><td>{{ $sample['message'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <p class="xi-note">{{ $t('preview_errors_note') }}</p>
    @endif

    @if (! empty($preview['samples']['skipped']))
        <div class="xi-h">{{ $t('preview_skipped') }}</div>
        <ul class="xi-note">
            @foreach ($preview['samples']['skipped'] as $reason => $count)
                <li>{{ number_format($count) }} × {{ $reason }}</li>
            @endforeach
        </ul>
    @endif
@endif
