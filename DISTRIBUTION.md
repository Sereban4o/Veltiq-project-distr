# Veltiq project — дистрибутив для развёртывания

Самодостаточный набор для запуска продукта на сервере через Docker Compose.
Собирается из репозитория `Tasksbase-Knowledgebase`; исходники клиентов (Android/Windows)
и исходники SPA в дистрибутив не входят. Веб-фронтенд поставляется **предсобранным**
(`frontend/dist`), образ nginx только копирует готовые ассеты — Node/npm на сервере не нужны.

Собирается скриптом `scripts/package_distribution.ps1` (см. «Пересборка дистрибутива»).

## Состав

| Каталог/файл | Назначение |
|---|---|
| `backend/` | Laravel-приложение (без `tests/`, `vendor/`, runtime-`storage/`). Собирается образом из `backend/Dockerfile.prod` |
| `core/` | Компилируемый core-сервис (Go, только stdlib). Образ из `core/Dockerfile`, тесты гоняются на стадии сборки |
| `frontend/dist/` | Скомпилированный SPA-бандл (минифицирован, без исходников и `.map`). Собирается при упаковке дистрибутива |
| `nginx/` | Конфиги и Dockerfile образа nginx (только `COPY frontend/dist` + прокси на PHP-FPM/Reverb) |
| `scripts/` | `backup.sh`, `publish_update.ps1`, брендинг-branding скрипты |
| `SHA256SUMS.txt` | Контрольные суммы всех файлов дистрибутива |
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
- Node.js/npm на сервере **не нужны**: SPA поставляется уже собранным

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
```

> `VITE_REVERB_KEY` уже вшит в `frontend/dist` при упаковке и должен совпадать
> с `REVERB_APP_KEY` бэкенда. Если ключ меняется — дистрибутив пересобирается
> (`scripts/package_distribution.ps1 -ReverbKey <ключ>`), на сервере фронтенд не пересобирается.

```bash
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

## Пересборка дистрибутива (у вендора)

```powershell
.\scripts\package_distribution.ps1 -ReverbKey <REVERB_APP_KEY>
```

Скрипт собирает SPA в Docker (`--target build` из `nginx/Dockerfile`), кладёт в бандл только
`frontend/dist` (без `src/`, `public/`, `package.json`, `vite.config.ts`), пишет `nginx/Dockerfile`
из шаблона `nginx/Dockerfile.dist`, копирует backend без `storage/`/тестов/tooling, и
перегенерирует `SHA256SUMS.txt`. Флаг `-SkipSpaBuild` переиспользует уже собранный `frontend/dist`.

## Проверка дистрибутива (без развёртывания)

```bash
cp backend/.env.production.example backend/.env.production   # compose требует env_file уже при `config`
docker compose --env-file backend/.env.production.example -f docker-compose.prod.yml config -q   # валидация compose
docker build -f nginx/Dockerfile -t veltiq-nginx-check .    # образ nginx: только копирование dist, без Node
docker build -f core/Dockerfile -t veltiq-core-check core   # сборка core + go test
```

Утечка исходников: в бандле не должно быть `frontend/src`, `*.ts`/`*.tsx`/`*.map`:

```bash
docker run --rm --entrypoint sh veltiq-nginx-check -c \
  "find /usr/share/nginx/html -name '*.ts' -o -name '*.tsx' -o -name '*.map'"
```

## Обновление

Тег `v*` в репозитории запускает `deploy.yml` (миграции, `optimize`, `horizon:terminate`,
`search:reindex-if-stale`, проверка `/up`). Ручной вариант — повторить шаги 2–3.

## Что не входит

- Исходники мобильного/десктопного клиента (`android/`) — собираются отдельно, а отдаются как APK/MSI.
- Исходники SPA (`frontend/src`, `public`, `package.json`, `vite.config.ts`) — поставляется только `dist`.
- Рантайм-данные `backend/storage/` (загруженные файлы, сессии, кэш, скомпилированные вьюхи).
- Бинарники обновлений (`updates/`) — публикуются через админ-панель или `scripts/publish_update.ps1`.
- Тесты, статический анализ, OpenAPI-контракт (`backend/swagger/`), dev-конфиги, git-хуки.
