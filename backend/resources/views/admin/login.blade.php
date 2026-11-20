<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в админ-панель - Veltiq project</title>
    <script>
        (function () {
            var saved = localStorage.getItem('admin-theme');
            var theme = saved || ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg-grad: linear-gradient(135deg, #0052CC 0%, #0747A6 100%);
            --surface: #ffffff;
            --text: #172B4D;
            --muted: #6B778C;
            --border: #DFE1E6;
            --primary: #0052CC;
            --primary-hover: #0065FF;
        }
        [data-theme="dark"] {
            color-scheme: dark;
            --bg-grad: linear-gradient(135deg, #0b2a55 0%, #071c3d 100%);
            --surface: #161d2b;
            --text: #e6edf6;
            --muted: #9aa7b8;
            --border: #2a3446;
            --primary: #5aa2ff;
            --primary-hover: #7db6ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-grad);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .theme-toggle {
            position: fixed;
            top: 1rem;
            right: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border-radius: 8px;
            padding: 0.4rem 0.65rem;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }
        .theme-toggle:hover { background: rgba(255, 255, 255, 0.28); }

        .login-container {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h1 {
            font-size: 1.5rem;
            color: var(--text);
            margin-bottom: 0.5rem;
        }
        .login-header p {
            color: var(--muted);
            font-size: 0.875rem;
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--muted);
            font-size: 0.875rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9375rem;
            background: var(--surface);
            color: var(--text);
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 82, 204, 0.1);
        }
        .alert-error {
            background: #FFEBE6;
            color: #BF2600;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            border: 1px solid #FFC1B5;
        }
        [data-theme="dark"] .alert-error {
            background: #3a1414;
            color: #ff9f9f;
            border-color: #5f1f1f;
        }
        .btn-login {
            width: 100%;
            padding: 0.875rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-login:hover { background: var(--primary-hover); }
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
        }
        .login-footer a:hover { text-decoration: underline; }
        .login-footer p { margin-top: 0.75rem; color: var(--muted); font-size: 0.75rem; }
    </style>
</head>
<body>
    <button type="button" id="theme-toggle" class="theme-toggle" title="Переключить тему" aria-label="Переключить тему">🌙</button>

    <div class="login-container">
        <div class="login-header">
            <img src="/veltiq-icon.svg" alt="Veltiq" style="width: 56px; height: 56px; margin-bottom: 0.75rem;">
            <h1>Veltiq project</h1>
            <p>Админ-панель</p>
        </div>

        @if(session('error'))
            <div class="alert-error">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="alert-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/admin/login">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="admin@example.com"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="btn-login">Войти</button>
        </form>

        <div class="login-footer">
            <a href="/">← Вернуться на главную</a>
            <p>
                Разработано <a href="https://veltiq.ru" target="_blank" rel="noopener">Veltiq · veltiq.ru</a> · {{ date('Y') }}
            </p>
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
</body>
</html>
