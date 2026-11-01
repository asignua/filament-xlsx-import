@include('filament-xlsx-import::styles')

@php
    $t = fn (string $key): string => __('filament-xlsx-import::filament-xlsx-import.'.$key);
    $hasRule = is_array($rule) && filled($rule['target'] ?? null);
@endphp

<div class="xi-source">
    <div class="xi-header">{{ $header }}</div>
    @if ($samples !== [])
        <div class="xi-samples">
            @foreach ($samples as $sample)
                <span class="xi-chip" title="{{ $sample }}">{{ $sample }}</span>
            @endforeach
        </div>
    @endif

    @if ($hasRule && ! $modified)
        <span class="xi-badge xi-badge-rule">{{ $t('badge_rule') }}</span>
    @elseif ($hasRule && $modified)
        <span class="xi-badge xi-badge-changed">{{ $t('badge_changed') }}</span>
    @elseif ($origin === 'preset')
        <span class="xi-badge xi-badge-preset">{{ $t('badge_preset') }}</span>
    @elseif ($origin === 'auto')
        <span class="xi-badge xi-badge-auto">{{ $t('badge_auto') }}</span>
    @endif
</div>
