@php($build = \App\Support\BuildInfo::get())
@if ($build['version'] || $build['commit'])
    <span
        class="fi-build-info"
        style="margin-inline-start: 0.5rem; font-size: 0.75rem; line-height: 1rem; color: var(--gray-500); white-space: nowrap"
        title="Release · Commit · Commit-Datum"
    >
        {{ collect([
            $build['version'],
            $build['commit'],
            $build['date']?->format('d.m.Y'),
        ])->filter()->implode(' · ') }}
    </span>
@endif
