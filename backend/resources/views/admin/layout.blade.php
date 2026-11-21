@php
    // Runtime branding: the customer's uploaded logo/app name also brand the admin panel.
    $adminBranding = app(\App\Services\Branding\BrandingService::class)->get();
    $adminAppName = $adminBranding['appName'] ?? 'Veltiq project';
    $adminLogo = $adminBranding['logo'] ?? '/veltiq-icon.svg';
    $adminFavicon = $adminBranding['favicon'] ?? '/favicon.ico';
@endphp
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ $adminFavicon }}">
    <title>@yield('title', 'Админ-панель') - {{ $adminAppName }}</title>
    <script>
        // Apply the saved (or system) theme before first paint to avoid a flash.
        (function () {
            var saved = localStorage.getItem('admin-theme');
            var theme = saved || ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7fa;
            --surface: #ffffff;
            --surface-2: #fafbfc;
            --text: #172B4D;
            --muted: #6B778C;
            --border: #dfe1e6;
            --primary: #0052CC;
            --primary-hover: #0065FF;
            --sidebar: #ffffff;
            --sidebar-active-bg: #DEEBFF;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --header-bg: linear-gradient(135deg, #0052CC 0%, #0747A6 100%);
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #0f1420;
            --surface: #161d2b;
            --surface-2: #1b2433;
            --text: #e6edf6;
            --muted: #9aa7b8;
            --border: #2a3446;
            --primary: #5aa2ff;
            --primary-hover: #7db6ff;
            --sidebar: #121a27;
            --sidebar-active-bg: #1d2b44;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
            --header-bg: linear-gradient(135deg, #0b2a55 0%, #071c3d 100%);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        a { color: var(--primary); }

        /* Shell: fixed sidebar + scrollable main */
        .admin-shell {
            display: grid;
            grid-template-columns: 252px minmax(0, 1fr);
            min-height: 100vh;
        }

        /* Sidebar */
        .admin-sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            background: var(--sidebar);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: var(--text);
        }
        .brand img { width: 30px; height: 30px; display: block; flex-shrink: 0; }
        .brand .brand-text { line-height: 1.2; }
        .brand .brand-title { font-weight: 700; font-size: 0.95rem; }
        .brand .brand-sub { font-size: 0.72rem; color: var(--muted); font-weight: 500; }

        .admin-nav { padding: 0.6rem 0.55rem 1rem; display: flex; flex-direction: column; gap: 0.15rem; }
        .nav-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            padding: 0.95rem 0.65rem 0.35rem;
            font-weight: 600;
        }
        .admin-nav a {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 0.7rem;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }
        .admin-nav a:hover { background: var(--surface-2); color: var(--primary); }
        .admin-nav a.active { background: var(--sidebar-active-bg); color: var(--primary); font-weight: 600; }

        .sidebar-footer {
            margin-top: auto;
            padding: 0.9rem 1.1rem;
            border-top: 1px solid var(--border);
            font-size: 0.85rem;
        }
        .sidebar-footer a { color: var(--muted); text-decoration: none; }
        .sidebar-footer a:hover { color: var(--primary); }

        /* Main column */
        .admin-main { display: flex; flex-direction: column; min-width: 0; min-height: 100vh; }
        .admin-topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.85rem 1.5rem;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .topbar-title { font-weight: 700; font-size: 1.05rem; }
        .topbar-actions { display: flex; align-items: center; gap: 0.75rem; }
        .user-name { color: var(--muted); font-size: 0.875rem; }
        .theme-toggle {
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text);
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }
        .theme-toggle:hover { border-color: var(--primary); }

        .admin-content { flex: 1; padding: 2rem; width: 100%; max-width: 1500px; margin: 0 auto; }

        .admin-footer {
            text-align: center;
            padding: 1.25rem;
            color: var(--muted);
            font-size: 0.8125rem;
            border-top: 1px solid var(--border);
            background: var(--surface);
        }
        .admin-footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .admin-footer a:hover { text-decoration: underline; }

        /* Alerts */
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        .alert-success { background: #e6f9e6; color: #2d7a2d; border: 1px solid #a3d9a3; }
        .alert-error { background: #ffe6e6; color: #c00; border: 1px solid #ffa3a3; }
        [data-theme="dark"] .alert-success { background: #12301c; color: #8fdc9f; border-color: #1f5233; }
        [data-theme="dark"] .alert-error { background: #3a1414; color: #ff9f9f; border-color: #5f1f1f; }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: #DE350B; color: white; }
        .btn-danger:hover { background: #FF5C34; }
        .btn-secondary { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--sidebar-active-bg); }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.8125rem; }

        /* Tables */
        .data-table {
            width: 100%;
            background: var(--surface);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .data-table table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: var(--surface-2);
            padding: 0.875rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--muted);
            border-bottom: 2px solid var(--border);
        }
        .data-table td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--border); }
        .data-table tr:hover { background: var(--surface-2); }

        /* Forms */
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--muted); }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background: var(--surface);
            color: var(--text);
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(0, 82, 204, 0.2);
        }
        .form-card {
            background: var(--surface);
            padding: 2rem;
            border-radius: 8px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            max-width: 600px;
        }
        .form-card h2 { margin-bottom: 1.5rem; color: var(--text); }

        /* Stats cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card {
            background: var(--surface);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .stat-card .stat-value { font-size: 2rem; font-weight: 700; color: var(--primary); }
        .stat-card .stat-label { font-size: 0.875rem; color: var(--muted); margin-top: 0.25rem; }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-success { background: #E3FCEF; color: #006644; }
        .badge-warning { background: #FFFBE5; color: #B78700; }
        .badge-danger { background: #FFEBE6; color: #BF2600; }
        .badge-info { background: #DEEBFF; color: #0052CC; }
        [data-theme="dark"] .badge-success { background: #12301c; color: #8fdc9f; }
        [data-theme="dark"] .badge-warning { background: #3a3212; color: #ffd76e; }
        [data-theme="dark"] .badge-danger { background: #3a1414; color: #ff9f9f; }
        [data-theme="dark"] .badge-info { background: #12294d; color: #8fbcff; }

        /* Filters */
        .filters {
            background: var(--surface);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .filters input,
        .filters select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background: var(--surface);
            color: var(--text);
        }

        /* Pagination */
        .pagination { margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem; }
        .pagination a {
            padding: 0.5rem 0.875rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary);
        }
        .pagination a:hover { background: var(--surface-2); }
        .pagination a.active { background: var(--primary); color: white; border-color: var(--primary); }

        /* Dark-mode fixes for colours hard-coded in page markup */
        [data-theme="dark"] [style*="#6B778C"],
        [data-theme="dark"] [style*="#42526E"] { color: var(--muted) !important; }
        [data-theme="dark"] [style*="#172B4D"] { color: var(--text) !important; }
        [data-theme="dark"] [style*="#FAFBFC"] { background: var(--surface-2) !important; }
        [data-theme="dark"] [style*="background: #fff"],
        [data-theme="dark"] [style*="background:#fff"],
        [data-theme="dark"] [style*="background: white"] { background: var(--surface) !important; }
        [data-theme="dark"] tr[style*="#FAFBFC"] { background: var(--surface-2) !important; }

        /* Responsive: sidebar becomes a scrollable top bar */
        @media (max-width: 900px) {
            .admin-shell { grid-template-columns: 1fr; }
            .admin-sidebar {
                position: static;
                height: auto;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            .brand { padding: 0.75rem 1rem; }
            .admin-nav {
                flex-direction: row;
                gap: 0.35rem;
                overflow-x: auto;
                padding: 0.5rem 0.75rem 0.75rem;
                -webkit-overflow-scrolling: touch;
            }
            .nav-group { display: contents; }
            .nav-label { display: none; }
            .admin-nav a { white-space: nowrap; }
            .sidebar-footer { display: none; }
            .admin-content { padding: 1.25rem; }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a href="{{ route('admin.dashboard') }}" class="brand">
            <img src="{{ $adminLogo }}" alt="{{ $adminAppName }}">
            <span class="brand-text">
                <span class="brand-title">{{ $adminAppName }}</span>
                <span class="brand-sub">Админ-панель</span>
            </span>
        </a>

        <nav class="admin-nav">
            <div class="nav-group">
                <div class="nav-label">Обзор</div>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">📊 Главная</a>
            </div>

            <div class="nav-group">
                <div class="nav-label">Каталоги</div>
                <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">👥 Пользователи</a>
                <a href="{{ route('admin.statuses.index') }}" class="{{ request()->routeIs('admin.statuses.*') ? 'active' : '' }}">📋 Статусы</a>
                <a href="{{ route('admin.priorities.index') }}" class="{{ request()->routeIs('admin.priorities.*') ? 'active' : '' }}">🎯 Приоритеты</a>
                <a href="{{ route('admin.roles.index') }}" class="{{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">🔐 Роли</a>
                <a href="{{ route('admin.access.projects') }}" class="{{ request()->routeIs('admin.access.*') ? 'active' : '' }}">🗝 Доступы</a>
            </div>

            <div class="nav-group">
                <div class="nav-label">Контент</div>
                <a href="{{ route('admin.spaces.index') }}" class="{{ request()->routeIs('admin.spaces.*') ? 'active' : '' }}">📚 Пространства</a>
                <a href="{{ route('admin.crm-stages.index') }}" class="{{ request()->routeIs('admin.crm-stages.*') ? 'active' : '' }}">📈 Этапы CRM</a>
                <a href="{{ route('admin.counterparties.index') }}" class="{{ request()->routeIs('admin.counterparties.*') ? 'active' : '' }}">🏢 Контрагенты</a>
                <a href="{{ route('admin.storage.index') }}" class="{{ request()->routeIs('admin.storage.*') ? 'active' : '' }}">🗂️ Хранилище</a>
                <a href="{{ route('admin.branding.index') }}" class="{{ request()->routeIs('admin.branding.*') ? 'active' : '' }}">🎨 Брендинг</a>
            </div>

            <div class="nav-group">
                <div class="nav-label">Коммуникации</div>
                <a href="{{ route('admin.notifications.index') }}" class="{{ request()->routeIs('admin.notifications.index') ? 'active' : '' }}">🔔 Уведомления</a>
                <a href="{{ route('admin.notifications.broadcast') }}" class="{{ request()->routeIs('admin.notifications.broadcast*') ? 'active' : '' }}">📨 Рассылка</a>
                <a href="{{ route('admin.webhooks.index') }}" class="{{ request()->routeIs('admin.webhooks.*') ? 'active' : '' }}">🔌 Вебхуки</a>
                <a href="{{ route('admin.widgets.index') }}" class="{{ request()->routeIs('admin.widgets.*') ? 'active' : '' }}">🧩 Виджеты</a>
                <a href="{{ route('admin.custom-fields.index') }}" class="{{ request()->routeIs('admin.custom-fields.*') ? 'active' : '' }}">➕ Доп. поля</a>
            </div>

            <div class="nav-group">
                <div class="nav-label">Безопасность</div>
                <a href="{{ route('admin.audit.index') }}" class="{{ request()->routeIs('admin.audit.*') ? 'active' : '' }}">🧾 Аудит</a>
                <a href="{{ route('admin.login-logs.index') }}" class="{{ request()->routeIs('admin.login-logs.*') ? 'active' : '' }}">🔑 Журнал входов</a>
                <a href="{{ route('admin.storage-audit.index') }}" class="{{ request()->routeIs('admin.storage-audit.*') ? 'active' : '' }}">🗂️ Аудит хранилища</a>
            </div>

            <div class="nav-group">
                <div class="nav-label">Клиенты</div>
                <a href="{{ route('admin.releases.index') }}" class="{{ request()->routeIs('admin.releases.*') ? 'active' : '' }}">🚀 Релизы</a>
                <a href="{{ route('admin.client-builds.index') }}" class="{{ request()->routeIs('admin.client-builds.*') ? 'active' : '' }}">🏗️ Сборки</a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <a href="/" target="_blank" rel="noopener">🌐 Открыть сайт</a>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-title">@yield('title', 'Админ-панель')</div>
            <div class="topbar-actions">
                <span class="user-name">{{ auth()->user()->first_name }} {{ auth()->user()->last_name }}</span>
                <button type="button" id="theme-toggle" class="theme-toggle" title="Переключить тему" aria-label="Переключить тему">🌙</button>
                <form method="POST" action="/admin/logout" style="display: inline;">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-sm btn-secondary">Выйти</button>
                </form>
            </div>
        </header>

        <main class="admin-content">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>

        <footer class="admin-footer">
            Разработано <a href="https://veltiq.ru" target="_blank" rel="noopener">Veltiq · veltiq.ru</a> · {{ date('Y') }}
        </footer>
    </div>
</div>

<script>
    (function () {
        var button = document.getElementById('theme-toggle');
        var root = document.documentElement;

        function sync() {
            if (button) button.textContent = root.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
        }

        sync();

        if (button) {
            button.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                localStorage.setItem('admin-theme', next);
                sync();
            });
        }
    })();
</script>

@stack('scripts')
</body>
</html>
