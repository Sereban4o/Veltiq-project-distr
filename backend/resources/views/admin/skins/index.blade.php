@extends('admin.layout')

@section('title', 'Скины')

@php
    $safeColor = function ($value, string $fallback): string {
        return is_string($value) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? $value
            : $fallback;
    };
    $safeUrl = function ($value): ?string {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (str_starts_with($value, '/') && ! str_starts_with($value, '//'))
            || preg_match('#^https?://[^\s<>"]+$#i', $value) === 1
                ? $value
                : null;
    };
    $hasActive = $skins->contains(fn ($skin) => $skin->is_active);
@endphp

@section('content')

@once
    @push('styles')
        <style>
            .skins-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
            .skins-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; }
            .skin-card {
                background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
                box-shadow: var(--shadow); overflow: hidden; display: flex; flex-direction: column;
            }
            .skin-card.is-active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(0, 82, 204, 0.18); }
            .skin-card-thumb {
                display: grid; grid-template-columns: 84px minmax(0, 1fr); height: 112px;
                background: var(--surface-2); border-bottom: 1px solid var(--border);
            }
            .skin-card-sidebar {
                display: flex; flex-direction: column; align-items: center; gap: 0.35rem; justify-content: center;
                padding: 0.5rem 0.35rem; color: #fff; font-size: 0.6rem; text-align: center;
                background: linear-gradient(160deg, var(--sc-primary) 0%, var(--sc-secondary) 140%);
            }
            .skin-card-sidebar img { width: 26px; height: 26px; object-fit: contain; }
            .skin-card-sidebar span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .skin-card-body { display: flex; flex-direction: column; gap: 0.4rem; padding: 0.75rem; }
            .skin-card-bar { height: 8px; border-radius: var(--sc-radius); background: var(--sc-primary); width: 46%; }
            .skin-card-line { height: 6px; border-radius: 4px; background: var(--border); }
            .skin-card-line.short { width: 62%; }
            .skin-card-chip { width: 38%; height: 14px; border-radius: 999px; background: var(--sc-secondary); opacity: 0.35; }
            .skin-card-info { padding: 0.9rem 1rem 0.5rem; display: flex; flex-direction: column; gap: 0.35rem; }
            .skin-card-title { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text); }
            .skin-card-meta { color: var(--muted); font-size: 0.78rem; }
            .skin-card-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; padding: 0.75rem 1rem 1rem; margin-top: auto; }
            .skin-card-actions form { margin: 0; }
            .skin-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.5rem; }
            .skin-actions form { margin: 0; }
        </style>
    @endpush
@endonce

<div class="skins-toolbar">
    <div>
        <h1 style="color: var(--text); margin: 0;">🎨 Скины</h1>
        <p style="color: var(--muted); font-size: 0.875rem; margin-top: 0.35rem;">
            Шаблоны оформления: палитра, шрифт, скругления, логотип и меню. Применяются к клиентам без пересборки.
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="{{ route('admin.branding.index') }}" class="btn btn-secondary">Расширенные настройки</a>
        <a href="{{ route('admin.skins.create') }}" class="btn btn-primary">➕ Новый скин</a>
    </div>
</div>

@if(! $hasActive)
    <div class="form-card" style="max-width: 100%; margin-bottom: 1.5rem;">
        <p style="margin: 0; color: var(--muted); font-size: 0.875rem;">
            Активный скин не выбран — клиенты используют конфигурацию из раздела
            <a href="{{ route('admin.branding.index') }}">«Брендинг»</a>
            (<strong>{{ $branding['appName'] ?? 'Veltiq project' }}</strong>).
        </p>
    </div>
@endif

@if($skins->isEmpty())
    <div class="form-card" style="max-width: 100%;">
        <p style="margin: 0; color: var(--muted);">
            Скинов пока нет. <a href="{{ route('admin.skins.create') }}">Создайте первый скин</a>, чтобы менять оформление без пересборки.
        </p>
    </div>
@else
    <div class="skins-grid">
        @foreach($skins as $skin)
            @php
                $config = is_array($skin->config) ? $skin->config : [];
                $theme = is_array($config['theme'] ?? null) ? $config['theme'] : [];
                $palette = is_array($theme['palette'] ?? null) ? $theme['palette'] : [];
                $shape = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
                $typography = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];

                $primary = $safeColor($palette['primary'] ?? null, '#0052CC');
                $secondary = $safeColor($palette['secondary'] ?? null, '#7C3AED');
                $radius = (int) ($shape['borderRadius'] ?? 12);
                $logo = $safeUrl($skin->logo_path ?: ($config['logo'] ?? null)) ?: '/veltiq-icon.svg';
                $appName = (string) ($config['appName'] ?? 'Veltiq project');
            @endphp

            <article class="skin-card {{ $skin->is_active ? 'is-active' : '' }}"
                     style="--sc-primary: {{ $primary }}; --sc-secondary: {{ $secondary }}; --sc-radius: {{ $radius }}px;">
                <div class="skin-card-thumb">
                    <div class="skin-card-sidebar">
                        <img src="{{ $logo }}" alt="">
                        <span>{{ $appName }}</span>
                    </div>
                    <div class="skin-card-body">
                        <span class="skin-card-bar"></span>
                        <span class="skin-card-line"></span>
                        <span class="skin-card-line short"></span>
                        <span class="skin-card-chip"></span>
                    </div>
                </div>

                <div class="skin-card-info">
                    <div class="skin-card-title">
                        {{ $skin->name }}
                        @if($skin->is_active)
                            <span class="badge badge-success">Активен</span>
                        @endif
                    </div>
                    <div class="skin-card-meta">
                        {{ ['system' => 'как в системе', 'light' => 'светлая', 'dark' => 'тёмная'][$palette['mode'] ?? 'system'] ?? 'как в системе' }}
                        · радиус {{ $radius }} px
                        · {{ $typography['fontFamily'] ?? 'Inter' }}
                    </div>
                    @if($skin->description)
                        <div class="skin-card-meta">{{ $skin->description }}</div>
                    @endif
                    <div class="skin-card-meta">
                        {{ $skin->updated_at?->format('d.m.Y H:i') }}
                        @if($skin->creator)
                            · {{ trim($skin->creator->first_name . ' ' . $skin->creator->last_name) }}
                        @endif
                    </div>
                </div>

                <div class="skin-card-actions">
                    @unless($skin->is_active)
                        <form method="POST" action="{{ route('admin.skins.apply', $skin) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Применить</button>
                        </form>
                    @endunless

                    <a href="{{ route('admin.skins.edit', $skin) }}" class="btn btn-sm btn-secondary">Изменить</a>

                    <form method="POST" action="{{ route('admin.skins.duplicate', $skin) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">Дублировать</button>
                    </form>

                    @unless($skin->is_active)
                        <form method="POST" action="{{ route('admin.skins.destroy', $skin) }}"
                              onsubmit="return confirm('Удалить скин?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                        </form>
                    @endunless
                </div>
            </article>
        @endforeach
    </div>
@endif
@endsection
