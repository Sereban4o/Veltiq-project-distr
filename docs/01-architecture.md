# Архитектура проекта Veltiq (Tasksbase-Knowledgebase)

## 1. Общее описание

Корпоративная платформа: канбан/скрам, база знаний, CRM, почта, календарь, файлы, чат и виджеты.

**Стек:**
- **Backend:** Laravel 12 + PHP 8.3, PostgreSQL 16, Redis 7, Meilisearch, Horizon
- **Frontend:** React 19 + TypeScript + Vite 6, MUI 6, TanStack Query 5, Zustand 5
- **Realtime:** Laravel Reverb (Pusher protocol)
- **Protected core:** отдельный Go-сервис (`core/`), приватная сеть Docker, авторизация `X-Core-Token`
- **Клиенты:** React SPA, Android (Kotlin/Compose), Windows (Compose Desktop)
- **Push:** ntfy; **Документы:** OnlyOffice

## 2. Структура

```
├── backend/                 # Laravel API + Blade-админка
│   ├── app/
│   │   ├── Http/Controllers/Api/   # API (tasksbase, Knowledgebase, Crm, Mail, Chat, Calendar, Storage, Ai, Admin…)
│   │   ├── Http/Controllers/Admin/ # Blade-админка
│   │   ├── Http/Middleware/        # QueryTokenAuth, VerifyBundleIntegrity, VerifyOneCRequest…
│   │   ├── Models/ Services/ Jobs/ Events/ Observers/
│   │   └── Support/                # HtmlSanitizer, SvgSanitizer, AuditWriter…
│   ├── config/ database/ resources/ routes/ tests/ swagger/
├── core/                    # Go «защищённое ядро» (алгоритмы, без БД)
├── frontend/                # React SPA
│   └── src/modules/         # auth, tasksbase, knowledgebase, crm, mail, calendar,
│                            # storage, chat, notifications, search, ai, widgets, releases, shared
├── android/                 # Android + Compose Desktop
├── nginx/ scripts/ updates/ docs/
├── docker-compose.yml       # dev
└── docker-compose.prod.yml  # prod
```

## 3. Аутентификация

### 3.1 API (Sanctum, токены)

- Токен в `localStorage` (`auth_token`), заголовок `Authorization: Bearer …`.
- Маршруты защищены `auth:sanctum`; query-токен для файлов — мидлвар `query.token`.
- Эндпоинты: `POST /api/v1/auth/login|logout|register`, `GET /api/v1/auth/me`, 2FA-челлендж, сессии.
- Rate limit API: `300,1` (`bootstrap/app.php`).

### 3.2 Админка (сессии)

- Blade `/admin`, мидлвары `auth` + `EnsureUserIsAdmin` (`admin`), плюс `SanitizeAdminAuth` (`admin.auth`).

### 3.3 Broadcast-каналы

- Авторизация приватных каналов: `routes/channels.php`, middleware `auth:sanctum`.
- Детали каналов/событий — `docs/03-websocket-events.md`.

## 4. Protected core (Go)

`core/` — скомпилированный сервис для алгоритмически ценной логики, чтобы она не поставлялась исходниками.

- Запуск: `CORE_TOKEN` обязателен (иначе сервис не стартует); `CORE_ADDR` (по умолчанию `:8080`).
- Не публикуется на хост, доступен только из Docker-сети.
- Клиент: `backend/app/Services/Core/CoreClient.php` — fail-open, circuit breaker (`CORE_BREAKER_THRESHOLD`/`CORE_BREAKER_COOLDOWN`), счётчики fallback (`php artisan core:stats`).
- Эндпоинты: `/healthz`, `/v1/calendar/free-slots`, `/v1/calendar/expand-recurrence`, `/v1/tasksbase/automation/match`, `/v1/tasksbase/timesheet`, `/v1/tasksbase/sprint/burndown|velocity`, `/v1/crm/automation/match`, `/v1/crm/csv/mapping`, `/v1/permissions/resolve`, `/v1/knowledgebase/page-acl/resolve`, `/v1/mail/rules/resolve`, `/v1/mail/thread/resolve`, `/v1/search/snippets`.
- Паритет с PHP проверяется `@group parity` (`PARITY_CORE_URL`/`PARITY_CORE_TOKEN`).

## 5. API

Полное описание — `backend/swagger/openapi.yaml`; фактическая регистрация маршрутов — `backend/routes/api.php`.
Базовый префикс: `/api/v1`. Основные группы:

| Группа | Префикс |
|---|---|
| Аутентификация | `/auth` |
| Задачи/доски/спринты | `/tasksbase` |
| База знаний | `/knowledgebase` |
| CRM | `/crm` |
| Почта | `/mail` |
| Календарь | `/calendar` |
| Файлы | `/files` |
| Чат | `/chat` |
| Уведомления | `/notifications` |
| Поиск | `/search`, `/search/*` |
| AI | `/ai` |
| Виджеты/кастомные поля/вебхуки | `/widgets`, `/custom-fields`, `/webhooks` |
| Брендинг | `/branding` |

## 6. Кэш, очереди, планировщик

- `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, Horizon-воркер, scheduler-контейнер.
- Планировщик: `php artisan schedule:run` (каждую минуту).

## 7. Переменные окружения (фрагмент)

```bash
# Core
CORE_URL=http://core:8080
CORE_TOKEN=
CORE_TIMEOUT=3
CORE_BREAKER_THRESHOLD=5
CORE_BREAKER_COOLDOWN=60

# Reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=

# Релизы приложений
UPDATES_PATH=/var/www/updates
UPDATES_MAX_SIZE_KB=512000
UPDATES_VERIFY_SIGNATURE=false
```

> Интеграция с 1С (мидлвар `one.c`, `/integration/1c/*`) сохранена в коде как legacy, но выведена из дорожной карты.
