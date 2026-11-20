# Veltiq project — дистрибутив для развёртывания

Самодостаточный набор для запуска продукта на сервере через Docker Compose.
Собирается из репозитория `Tasksbase-Knowledgebase`; исходники клиентов (Android/Windows)
в дистрибутив не входят.

## Состав

| Каталог/файл | Назначение |
|---|---|
| `backend/` | Laravel-приложение (без `tests/`, `vendor/`, runtime-`storage/`). Собирается образом из `backend/Dockerfile.prod` |
| `core/` | Компилируемый core-сервис (Go, только stdlib). Образ из `core/Dockerfile`, тесты гоняются на стадии сборки |
| `frontend/` | Исходники SPA (без `node_modules/`, `dist/`). SPA собирается внутри образа nginx |
| `nginx/` | Конфиги и Dockerfile образа nginx (SPA + прокси на PHP-FPM/Reverb) |
| `scripts/` | `backup.sh`, `publish_update.ps1`, брендинг-branding скрипты |
| `docker-compose.prod.yml` | Основной стек: postgres, redis, meilisearch, reverb, app, worker, scheduler, nginx, ntfy, core |
| `docker-compose.onlyoffice.yml` | Опционально: ONLYOFFICE Document Server |
| `docker-compose.ollama.yml` | Опционально: локальная модель для AI-ассистента |
| `docker-compose.storage-*.yml` | Опционально: сетевое хранилище (SMB/NFS) вместо локального тома |
| `.env.example` | Переменные для docker compose (имена томов/сети, ключ Reverb) |
| `DEPLOY.md` | Пошаговое развёртывание, бэкапы, алерты, апгрейды |
| `docs/` | Требования к системе и обзор |

## Требования

- Linux-сервер, Docker Engine 24+ и Docker Compose v2
- 4 vCPU / 8 ГБ RAM / 40 ГБ диска (с OnlyOffice и Ollama — больше)
- Открытые порты 80 (и 443 при TLS), 8080 — Reverb только внутри, 8081 — ntfy (по желанию)
- Домен и TLS-сертификат (рекомендуется; `SESSION_SECURE_COOKIE=true` после включения HTTPS)

## Развёртывание

```bash
# 1. Конфигурация
cp .env.example .env
cp backend/.env.production.example backend/.env.production
# заполнить: APP_KEY, POSTGRES_PASSWORD/DB_PASSWORD, REVERB_APP_SECRET,
# MEILI_MASTER_KEY, SANCTUM_STATEFUL_DOMAINS, CORS_ALLOWED_ORIGINS,
# NTFY_*, ONLYOFFICE_*, AI_*, CORE_TOKEN (общий секрет для core-сервиса)

php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # APP_KEY

# 2. Сборка и запуск
docker compose --env-file backend/.env.production -f docker-compose.prod.yml build --pull
docker compose --env-file backend/.env.production -f docker-compose.prod.yml up -d

# 3. Инициализация
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force   # при первом запуске
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize
docker compose -f docker-compose.prod.yml exec -T worker php artisan search:reindex

# 4. Проверка
curl -fsS http://localhost/up
docker compose -f docker-compose.prod.yml exec -T app php artisan core:stats   # состояние core-сервиса
```

Опциональные оверлеи добавляются флагами `-f`:

```bash
docker compose --env-file backend/.env.production \
  -f docker-compose.prod.yml -f docker-compose.onlyoffice.yml up -d
```

## Проверка дистрибутива (без развёртывания)

```bash
docker compose --env-file backend/.env.production.example -f docker-compose.prod.yml config -q   # валидация compose
docker build -f core/Dockerfile -t veltiq-core-check core                                       # сборка core + go test
```

## Обновление

Тег `v*` в репозитории запускает `deploy.yml` (миграции, `optimize`, `horizon:terminate`,
`search:reindex-if-stale`, проверка `/up`). Ручной вариант — повторить шаги 2–3.

## Что не входит

- Исходники мобильного/десктопного клиента (`android/`) — собираются отдельно, а отдаются как APK/MSI.
- Бинарники обновлений (`updates/`) — публикуются через админ-панель или `scripts/publish_update.ps1`.
- Тесты, статический анализ, dev-конфиги.
