@php($build = \App\Support\BuildInfo::get())
<span style="display: flex; flex-direction: column; justify-content: center; height: 100%; line-height: 1.15">
    <span>{{ filament()->getBrandName() }}</span>
    @if ($build['version'] || $build['commit'])
        <span
            class="fi-build-info"
            style="font-size: 0.7rem; line-height: 1rem; font-weight: 400; letter-spacing: 0; color: var(--gray-500); white-space: nowrap"
            title="Release · Commit · Commit-Datum"
        >{{ collect([$build['version'], $build['commit'], $build['date']?->format('d.m.Y')])->filter()->implode(' · ') }}</span>
    @endif
</span>
