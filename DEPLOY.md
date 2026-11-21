# Релиз и деплой через Git — по порядку действий

> **Параметры деплоя**
> - Домен (целевой): **https://veltiq.ru**
> - Внешний IP (публичный)
> - Внутренний IP сервера (Ubuntu + Docker)
> - Репозиторий: `https://github.com/Sereban4o/Tasksbase-Knowledgebase`
> - Каталог проекта на сервере: `/opt/tasksbase`
> - Доступ: HTTP по IP, HTTPS пока нет. WebSocket идёт через nginx (`ws://`), при включении TLS станет `wss://`.
> - Push-уведомления: **ntfy** на порту **8081** (с авторизацией).

Стек: Laravel 13 (PHP 8.5) + React 19 + PostgreSQL 18 + Redis + Reverb + Meilisearch.
Публикуются **80** (nginx: SPA + API + WebSocket) и **8081** (ntfy push).
В дистрибутиве SPA уже собран (`frontend/dist`) и образ nginx только копирует ассеты;
при сборке из репозитория SPA собирается внутри `nginx`-образа.

---

## Шаг 0. Переезд с существующей установки (сборка из исходников → Docker)

Нужен, когда проект уже работает на другой машине «из исходников» (php-fpm + nginx + PostgreSQL,
возможно cron/supervisor) и его надо перевести на этот стек. Старую машину **не выключать**
до подтверждения работы новой.

### 0.1 Снять инвентарь на старой машине

```bash
cd <old_project_dir>
php artisan about
php artisan migrate:status | tail -20
php -v; php -m | sort; node -v
crontab -l
ls backend/storage/app        # вложения, files, storage-meta
ls updates 2>/dev/null
```

Из старого `backend/.env` выписать: `APP_KEY`, `APP_URL`, `DB_*`, `REVERB_APP_ID/KEY/SECRET`,
`MEILI_MASTER_KEY`, `NTFY_*`, `MAIL_*`, `ONE_C_*`, `ONLYOFFICE_*`, `FLARE_KEY`,
`HORIZON_PREFIX`, `SANCTUM_TOKEN_PREFIX`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`,
`STORAGE_BROWSER_*`, а также факт использования SMB/NFS-монтирований.

> `APP_KEY` переносится **без изменений**: им зашифрованы пароли хранилища, токены 1С и прочие
> секреты в БД. Новый ключ сделает их нечитаемыми.

### 0.2 Бэкап старой установки

```bash
php artisan down
pg_dump -Fc -U <DB_USER> -h <DB_HOST> <DB_DATABASE> > /tmp/db.dump   # каталог данных копировать нельзя
tar czf /tmp/storage.tgz -C backend/storage/app .
tar czf /tmp/updates.tgz -C . updates          # если каталог есть
tar czf /tmp/env.tgz -C backend .env           # передавать только по защищённому каналу
```

После дампа остановить старые воркеры/крон, чтобы в БД и `storage` ничего не писалось.

### 0.3 Подготовить новый сервер (Шаги 1–2), перенести архивы

```bash
mkdir -p /opt/tasksbase/restore
scp /tmp/{db.dump,storage.tgz,updates.tgz,env.tgz} <user>@<new_host>:/opt/tasksbase/restore/
```

В `/opt/tasksbase/.env` (рядом с compose) задать `COMPOSE_PROJECT_NAME=tasksbase`,
`REVERB_APP_KEY=<как в бэкенде>` и `APP_RELEASE=<тег>` — см. комментарии в `.env.example`.
`REVERB_APP_KEY` вшивается в SPA при сборке, поэтому должен совпадать с бэкендом.

### 0.4 Создать `backend/.env.production`

```bash
cp backend/.env.production.example backend/.env.production
```

Перенести значения из старого `.env`, заменив адреса на имена сервисов compose:
`DB_HOST=postgres`, `DB_PORT=5432`, `REDIS_HOST=redis`, `MEILISEARCH_HOST=http://meilisearch:7700`,
`REVERB_HOST=reverb`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_RELEASE=<тег>`.

### 0.5 Запуск и восстановление данных

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps          # дождаться healthy

docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_restore -U postgres -d <DB_DATABASE> --clean --if-exists --no-owner < restore/db.dump

docker run --rm -v tasksbase_storage_data:/data -v /opt/tasksbase/restore:/b alpine \
  sh -c 'tar xzf /b/storage.tgz -C /data && chown -R 33:33 /data'

tar xzf restore/updates.tgz -C /opt/tasksbase && sudo chown -R 33:33 /opt/tasksbase/updates

docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force   # без --seed
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize
docker compose -f docker-compose.prod.yml exec -T worker php artisan search:reindex --chunk=500
docker compose -f docker-compose.prod.yml exec -T worker php artisan horizon:terminate
```

Индекс Meilisearch **не переносится** (формат привязан к версии образа) — он пересобирается
командой `search:reindex`. Redis тоже не переносится (кэш, очереди, сессии).

### 0.6 Переключение

1. Пройти чек-лист Шага 7 на новой машине.
2. Переключить DNS / проброс портов на новый IP, старую машину перевести в `php artisan up` + read-only.
3. Клиенты хранят адрес сервера на устройстве: при смене IP его меняют в «Настройках сервера»
   приложения; ntfy — новый `IP:8081` и логин/пароль.
4. Бэкапы старой машины хранить не меньше двух недель, затем вывести её из эксплуатации.

Откат: вернуть DNS/проброс на старую машину. Данные, записанные на новой за время проверки,
при откате теряются — поэтому переключение делают в окно обслуживания.

---

## Шаг 1. Открыть порты

### 1.1 Публичные (firewall + NAT роутера → Внутренний IP)

| Порт | Назначение | Обязательность |
|------|-----------|----------------|
| **22/tcp** | SSH | да (по возможности ограничить по IP) |
| **80/tcp** | Сайт + API + WebSocket (`ws://`) | да |
| **8081/tcp** | ntfy — push-уведомления | да |
| **443/tcp** | HTTPS/`wss` | опционально (позже) |

### 1.2 Внутренние — НЕ открывать в интернет

`5432` (PostgreSQL), `6379` (Redis), `7700` (Meilisearch), `8080` (Reverb), `9000` (PHP-FPM).
В прод-`docker-compose.prod.yml` они не публикуются — доступны только внутри Docker-сети.

### 1.3 UFW на сервере

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 8081/tcp
# sudo ufw allow 443/tcp   # когда включите HTTPS
sudo ufw enable
sudo ufw status
```

> Если сервер за NAT-роутером: пробросьте на `Внутренний IP` **80**, **8081** и **22** (SSH).

---

## Шаг 2. Подготовить сервер (один раз)

### 2.1 Проверить Docker

```bash
docker --version
docker compose version
git --version
```

### 2.2 Создать каталог проекта

```bash
sudo mkdir -p /opt/tasksbase
sudo chown $USER:$USER /opt/tasksbase
```

### 2.3 Настроить доступ к репозиторию (deploy key, read-only)

```bash
ssh-keygen -t ed25519 -C "deploy@tasksbase" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
# Добавить ключ в GitHub → Settings → Deploy keys (read-only)
```

---

## Шаг 3. Первый деплой

### 3.1 Клонировать репозиторий

```bash
git clone git@github.com:Sereban4o/Tasksbase-Knowledgebase.git /opt/tasksbase
cd /opt/tasksbase
git checkout master        # или нужный тег: git checkout v1.0.0
```

### 3.2 Создать прод-окружение

```bash
cp backend/.env.production.example backend/.env.production
```

Сгенерировать `APP_KEY`:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Отредактировать `backend/.env.production`:
- `APP_KEY=base64:...`
- заменить `CHANGE_ME_*`: `POSTGRES_PASSWORD`, `DB_PASSWORD` (должны совпадать), `REVERB_APP_SECRET`, `MEILI_MASTER_KEY`
- `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS` — уже указывают на `Внешний IP`
- `NTFY_USER` / `NTFY_PASSWORD` — логин/пароль публикации в ntfy (должны совпадать с `NTFY_PASSWORD` сервиса `ntfy` в compose)

> `backend/.env.production` в git **не хранится** (в `.gitignore`). Создаётся один раз; `git pull`/`git checkout` его не перезаписывают.

### 3.3 Собрать и запустить

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

### 3.4 Инициализировать БД

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force   # опционально
```

> `--seed` создаёт демо-данные (пользователи, доска). Демо-админ `admin@example.com` / `password` — **смените пароль**.

### 3.5 Проверить

```bash
curl -fsS http://localhost/up && echo OK
docker compose -f docker-compose.prod.yml ps
```

- Сайт: `http://Внешний IP`
- Админка: `http://Внешний IP/admin/login`

### 3.6 Push через ntfy (без Firebase)

Сервис `ntfy` в `docker-compose.prod.yml` слушает **8081** и работает с включённой авторизацией:
- `NTFY_AUTH_DEFAULT_ACCESS=deny-all` — без логина нельзя ни читать, ни публиковать;
- при старте создаётся пользователь `tasksbase` с паролем из `NTFY_PASSWORD` (роль admin);
- auth-база — SQLite в volume `ntfydata` (`/var/cache/ntfy/auth.db`);
- топик на пользователя: `tasksbase-user-{id}`.

Проверка:

```bash
# без авторизации — ожидается 403
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8081 \
  -H 'Content-Type: application/json' \
  -d '{"topic":"tasksbase-user-1","message":"test"}'

# с авторизацией — ожидается 200
curl -s -o /dev/null -w "%{http_code}\n" -u tasksbase:tasksbase -X POST http://localhost:8081 \
  -H 'Content-Type: application/json' \
  -d '{"topic":"tasksbase-user-1","message":"test"}'
```

Смена пароля ntfy:
1. задать новый `NTFY_PASSWORD` в `docker-compose.prod.yml` (сервис `ntfy`) **и** в `backend/.env.production`;
2. удалить auth-базу и пересоздать контейнер:

```bash
docker compose -f docker-compose.prod.yml exec ntfy rm -f /var/cache/ntfy/auth.db
docker compose -f docker-compose.prod.yml up -d --force-recreate ntfy app
```

3. обновить пароль ntfy в аккаунтах клиентов (Android/Windows).

> Полный сброс подписок/паролей: удалить volume `ntfydata`.

---

## Шаг 4. Разработка фичи (ветки)

### 4.1 Создать ветку от `master`

```bash
git checkout master
git pull --ff-only
git checkout -b feature/short-name
```

### 4.2 Работа и локальные проверки

```bash
(cd backend && php artisan test)
(cd frontend && npm run build)
```

### 4.3 Коммит и пуш ветки

```bash
git add -A
git commit -m "feat: describe change"
git push -u origin feature/short-name
```

### 4.4 Pull Request → merge в `master`

- Открыть PR в GitHub, дождаться ревью/CI, влить в `master`.

---

## Шаг 5. Подготовка релиза (локально/CI)

> `master` = прод. Тег = неизменяемый срез для деплоя.

### 5.1 Обновить `master`

```bash
git checkout master
git pull --ff-only
```

### 5.2 Прогнать тесты и сборку

```bash
(cd backend && php artisan test)
(cd frontend && npm run build)
```

### 5.3 Поставить тег версии (SemVer)

```bash
git tag -a v1.0.0 -m "Release v1.0.0"
```

Типы версий: `v1.0.1` — фиксы, `v1.1.0` — фичи (обратно совместимо), `v2.0.0` — breaking changes.

### 5.4 Отправить ветку и тег

```bash
git push origin master
git push origin v1.0.0        # или все теги: git push origin --tags
```

---

## Шаг 6. Деплой релиза на сервере (по порядку)

### 6.1 Перейти в каталог и подтянуть теги

```bash
cd /opt/tasksbase
git fetch --tags --prune
```

### 6.2 Переключиться на релизный тег

```bash
git checkout v1.0.0
git describe --tags --always      # проверить развёртываемую версию
```

> `git checkout <тег>` → detached HEAD. Это нормально для прода. Возврат на ветку: `git checkout master`.

### 6.3 Пересобрать образы

```bash
docker compose -f docker-compose.prod.yml build --pull
docker compose -f docker-compose.prod.yml up -d
```

> В дистрибутиве SPA уже собран — `nginx`-образ только копирует `frontend/dist`, пересборка
> фронтенда на сервере не выполняется. `REVERB_APP_KEY` вшивается в бандл при упаковке
> дистрибутива (`scripts/package_distribution.ps1 -ReverbKey <ключ>`). При сборке из
> репозитория SPA собирается внутри `nginx`-образа и подхватывает изменения только пересборкой.

### 6.4 Применить миграции

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### 6.5 Обновить кэш

```bash
docker compose -f docker-compose.prod.yml exec app php artisan optimize
```

`php artisan optimize` кэширует конфиг, роуты, события и представления (эквивалент `config:cache` + `route:cache` + `event:cache` + `view:cache`).

> Если меняли `backend/.env.production`: сначала `php artisan config:clear`, затем `config:cache` и `up -d --force-recreate app worker reverb`.

### 6.6 Проверить релиз (Шаг 7 ниже)

---

## Шаг 7. Проверка после деплоя

```bash
curl -fsS http://localhost/up && echo OK
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f --tail=100
```

Чек-лист:
- [ ] `http://Внешний IP` открывает SPA
- [ ] Логин работает (`/api/v1/auth/login`)
- [ ] WebSocket подключается (`ws://Внешний IP/app/...`)
- [ ] Загрузка файлов/вложений работает
- [ ] ntfy: без логина — 403, с логином — 200; push приходит в клиенты
- [ ] `docker compose ... ps` — все сервисы `Up`

---

## Шаг 8. Откат (rollback)

### 8.1 Переключиться на предыдущий стабильный тег

```bash
cd /opt/tasksbase
git fetch --tags
git checkout v0.9.0               # предыдущий рабочий тег
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan config:clear
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
```

### 8.2 Про миграции

Откат кода **не откатывает миграции**. Варианты:
- `php artisan migrate:rollback` — только если у миграций есть рабочий `down()`;
- восстановление БД из бэкапа (см. Шаг 11.3);
- предпочтительно — «forward-fix» новым тегом.

---

## Шаг 9. Hotfix

```bash
git checkout -b hotfix/critical-bug master
# ... правки + тесты ...
git commit -am "fix: critical bug"
git checkout master
git merge --no-ff hotfix/critical-bug
git tag -a v1.0.1 -m "Hotfix v1.0.1"
git push origin master v1.0.1
```

На сервере — повторить **Шаг 6** с тегом `v1.0.1`.

---

## Шаг 10. CI/CD (GitHub Actions)

На пуш тега `v*` запускается `.github/workflows/deploy.yml`: он по SSH заходит на сервер и
выполняет деплой по порядку — `git fetch/checkout <tag>` → `build --pull` → `up -d` →
`migrate --force` → `event:clear` + `optimize` → `horizon:terminate` → `search:reindex-if-stale`,
затем `curl /up`. `APP_RELEASE=<tag>` прокидывается в контейнеры через `environment`
compose, так что после деплоя Meili переиндексируется автоматически.

Секреты `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (и опционально `DEPLOY_PORT`) — в
Settings → Secrets and variables → Actions. Без них workflow не запустится.

---

## Шаг 11. Эксплуатация

### 11.1 Перезапуск сервисов

```bash
docker compose -f docker-compose.prod.yml restart app worker reverb nginx
```

### 11.2 Логи

```bash
docker compose -f docker-compose.prod.yml logs -f --tail=200 app
docker compose -f docker-compose.prod.yml logs -f --tail=200 nginx
```

### 11.3 Бэкап БД

```bash
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U postgres veltiq_project > backup_$(date +%F).sql
```

### 11.4 Бэкап файлов пользователей

Загрузки — named-volume `storage_data` (в контейнере `/var/www/html/storage`):

```bash
docker run --rm -v tasksbase-knowledgebase_storage_data:/data -v $PWD:/backup alpine \
  tar czf /backup/storage_$(date +%F).tgz -C /data .
```

### 11.5 Автоматизация бэкапов

Скрипт `scripts/backup.sh` делает три бэкапа за раз и чистит старое:
- `backups/db/db-<stamp>.dump` — PostgreSQL в custom-формате (`pg_restore`);
- `backups/storage/storage-<stamp>.tgz` — volume `storage_data`;
- `backups/meili/meili-<stamp>.dump` — дамп Meilisearch (совместим с политикой пина, Шаг 19.6).

Запуск ежед��евно через cron (каталог `backups/` в git не хранится):

```bash
crontab -e
```

```cron
15 3 * * * cd /opt/tasksbase && ./scripts/backup.sh >> /var/log/tasksbase-backup.log 2>&1
```

Параметры через env: `BACKUP_DIR` (по умолчанию `./backups`), `RETENTION_DAYS` (14).
Восстановление БД: `pg_restore -U postgres -d veltiq_project backups/db/db-<stamp>.dump`.

---

## Шаг 12. Добавить HTTPS позже

1. Получить домен, A-запись → `Внешний IP` (или сертификат на IP через acme/certbot).
2. Смонтировать сертификаты в nginx, добавить `listen 443 ssl;` + `ssl_certificate`.
3. В `backend/.env.production`: `APP_URL=https://домен`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=домен`, `CORS_ALLOWED_ORIGINS=https://домен`.
4. Открыть 443, пересобрать nginx (Шаг 6). WebSocket станет `wss` автоматически (клиент выбирает схему по `window.location.protocol`).

---

## Шаг 13. Безопасность — чек-лист

- [ ] Сменён пароль `admin@example.com`
- [ ] Заданы сильные `POSTGRES_PASSWORD`/`DB_PASSWORD`, `REVERB_APP_SECRET`, `MEILI_MASTER_KEY`
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `LOG_LEVEL=warning`
- [ ] Наружу открыты только 22/80/8081 (443 позже); БД, Redis, Meilisearch, Reverb **не** опубликованы
- [ ] `.env` / `.env.production` не в git (только `*.example`), доступ к репо — deploy key (read-only)
- [ ] SSH ограничен по IP (по возможности)
- [ ] Настроен регулярный бэкап БД и `storage_data`
- [ ] Сменён пароль ntfy (`NTFY_PASSWORD`) и он совпадает в compose, `backend/.env.production` и клиентах
- [ ] Порт 8081 открыт только для ntfy; auth включён (`deny-all`)
- [ ] 1С-интеграция: заданы `ONE_C_TOKEN`/`ONE_C_HMAC_SECRET`, при возможности `ONE_C_ALLOWED_IPS`
- [ ] ONLYOFFICE (если включён): `ONLYOFFICE_URL=/onlyoffice` (same-origin через nginx), `ONLYOFFICE_JWT_SECRET` совпадает с `JWT_SECRET` контейнера `onlyoffice`
- [ ] Релизы: при включённой проверке подписи настроены `UPDATES_VERIFY_*`, каталог `./updates` доступен на запись `app`

---

## Шаг 14. Клиенты: Android и Windows

Оба приложения поддерживают **несколько аккаунтов**, каждый — со своим сервером и своим ntfy (в обычной сборке). Есть также **preset (branded) сборки** с зашитым сервером — см. 14.5.

### 14.1 Android (APK)

Сборка через Android Studio или Gradle 8.7 + JDK 17 (в репо есть wrapper):

```bash
cd android
./gradlew :app:assembleSelfupdateStandardRelease   # selfupdate-вариант
./gradlew :app:assembleManualStandardRelease       # без права установки APK
```

APK: `android/app/build/outputs/apk/selfupdateStandard/release/app-selfupdate-standard-release.apk`.

### 14.2 Windows (exe + MSI)

```bash
cd android
./gradlew :desktop:packageMsi
```

- EXE: `android/desktop/build/compose/binaries/main/app/VeltiqChat/VeltiqChat.exe`
- MSI: `android/desktop/build/compose/binaries/main/msi/VeltiqChat-1.2.2.msi`

> MSI требует WiX — Gradle скачивает его автоматически.
>
> При установке MSI/EXE создаются ярлыки **на рабочем столе** и **в меню «Пуск»** (jpackage `--win-shortcut` / `--win-menu`, блок `windows { }` в `android/desktop/build.gradle.kts`). `upgradeUuid` фиксирован — не меняйте его, чтобы MSI-обновления ставились поверх, а не рядом.

### 14.3 Добавление аккаунта

При входе указываются:
- **Сервер**: `IP:80` (прод) или ваш адрес;
- **ntfy**: `IP:8081`, **пользователь** и **пароль** ntfy (по умолчанию `tasksbase` / `tasksbase`);
- email/пароль пользователя этого сервера.

Каждый аккаунт хранит свой сервер, WebSocket и ntfy — переключение в меню сверху (Android — тап по заголовку, Windows — меню аккаунта).

### 14.4 Windows: трей и автозапуск

Меню аккаунта → **Настройки…**:
- **Автозапуск при входе в Windows** — запись в `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` (без админа; доступно в установленной/упакованной версии);
- **Сворачивать в трей при запуске** — приложение стартует в трее;
- закрытие окна (X) сворачивает в трей; «Выход» в трее — полное завершение.

Уведомления приходят через WebSocket (Reverb) и ntfy, пока приложение запущено.

### 14.5 Preset (branded) сборки

Preset-сборка зашивает сервер и ntfy на этапе сборки: пользователь их **не видит** и не может изменить, добавить/переключить аккаунт нельзя (логин — только email/пароль). Источник параметров — JSON-бренд `android/branding/<name>.json` (+ оверрайды `-P`/`VELTIQ_*`). Подробно — `android/branding/README.md`.

```bash
cd android
# Android: обычная и preset-сборка
./gradlew :app:assembleSelfupdateStandardRelease
./gradlew -Pbrand=acme :app:assembleSelfupdatePresetRelease
# Windows: обычная и preset-сборка
./gradlew :desktop:packageMsi
./gradlew -Pbrand=acme :desktop:packageMsi
```

Артефакты preset: `app-selfupdate-preset-release.apk`, `AcmeChat-1.2.2.msi` (имя пакета из бренда).

Важно:
- канал обновлений **общий** (`/updates/android|windows`), поэтому одновременно публикуйте только одну сборку на платформу;
- не меняйте `applicationId`/`upgradeUuid` бренда после первого релиза;
- ntfy-креды зашиты в APK/MSI и извлекаемы — используйте отдельного ntfy-пользователя с ограниченными правами.

---

## Шаг 15. Сервер обновлений (самообновление приложений)

Обновления отдаёт nginx из каталога `./updates`. Каталог монтируется в `nginx` (только чтение) и в `app` (read-write, для загрузки через админку) как `/var/www/updates`. Публичный порт 80.

Структура:
```
updates/
  android/latest.json  +  <файл>.apk
  windows/latest.json  +  <файл>.msi
```

Манифест `latest.json`:
```json
{ "version": "1.0.1", "versionCode": 2, "path": "/updates/android/app-release.apk", "notes": "Что нового", "sha256": "..." }
```

Эндпоинты (через nginx):
- `GET /updates/android/latest.json`, `GET /updates/android/<file>.apk`
- `GET /updates/windows/latest.json`, `GET /updates/windows/<file>.msi`

### 15.1 Загрузка через веб-админку (рекомендуется)

В SPA-админке: **Панель администратора → Релизы приложений**. Форма позволяет выбрать платформу (Android/Windows), версию, `versionCode`, описание и файл. Сервер:
- проверяет расширение по whitelist (`apk/aab` для Android, `msi/exe/zip` для Windows);
- (опционально) проверяет подпись бинарника перед публикацией;
- сохраняет файл в `updates/<platform>/`, считает SHA-256 и атомарно перезаписывает `latest.json`.

API-слой для SPA не используется — раздел живёт в Laravel Blade-админке: `http://<server>/admin/releases` (вход администратора через сессию). Ссылка также есть в меню админки («🚀 Релизы»).

Проверка подписи (опционально, fail-closed):
```bash
# backend/.env.production
UPDATES_VERIFY_SIGNATURE=true
UPDATES_VERIFY_ANDROID="apksigner verify --print-certs %s"
UPDATES_VERIFY_WINDOWS="osslsigncode verify -in %s"
```
Инструменты должны быть установлены в контейнере `app`; `%s` заменяется на путь к файлу.

### 15.2 Публикация из CLI

```powershell
# Android
.\scripts\publish_update.ps1 -Platform android -File .\app-release.apk -Version 1.0.1 -VersionCode 2 -Notes "Fixes"

# Windows
.\scripts\publish_update.ps1 -Platform windows -File .\TasksbaseChat-1.0.1.msi -Version 1.0.1 -Notes "Fixes"
```

Скрипт копирует файл в `updates/<platform>/`, считает SHA-256 и обновляет `latest.json`. Пересборка nginx не нужна — файлы видны сразу (bind-mount).

### 15.3 Как работают приложения

- При запуске и по пункту меню **«Проверить обновления»** приложение опрашивает `/updates/<platform>/latest.json` на своём сервере.
- Если версия новее — предлагает обновление:
  - **Android**: скачивает APK и запускает системный установщик (нужно разрешение «Установка из неизвестных источников» для приложения).
  - **Windows**: скачивает MSI и запускает `msiexec /i` (приложение закрывается, затем установщик обновляет файлы).

> Где менять версию:
> - Android: `versionCode` / `versionName` в `android/app/build.gradle.kts`;
> - Windows: `APP_VERSION` в `Main.kt` и `packageVersion` в `android/desktop/build.gradle.kts`.
>
> URL обновлений берётся с того же сервера, что и активный аккаунт (`http://<server>/updates/...`), поэтому для разных серверов — свои обновления.

---

## Шаг 16. Интеграция с 1С (опционально)

Входящие запросы 1С идут на `/api/v1/integration/1c/*` (без пользовательской сессии) и защищены токеном и/или HMAC-подписью.

```bash
# backend/.env.production
ONE_C_ENABLED=true
ONE_C_BASE_URL=http://1c-host/base/hs/tasksbase   # для исходящих вызовов Laravel → 1С
ONE_C_TOKEN=<bearer-токен>
ONE_C_HMAC_SECRET=<shared-secret>
ONE_C_ALLOWED_IPS=10.0.0.5,10.0.0.6               # опционально, через запятую
ONE_C_TIMESTAMP_TOLERANCE=300
```

- Подпись 1С: `X-Signature = hex(hmac_sha256("{X-Timestamp}.{raw_body}", ONE_C_HMAC_SECRET))` + заголовок `X-Timestamp`.
- Если интеграция включена, но токен и секрет пусты — эндпоинты отвечают `503` (fail-closed).
- Rate limit: 120 запросов/мин; опциональный IP-allowlist.

Планировщик подтягивает изменения задач из 1С ежечасно (`routes/console.php`), либо вручную:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan one-c:pull-issues --since="2026-01-01T00:00:00Z"
```

Не забудьте запускать Laravel scheduler: `schedule:work` или cron с `schedule:run` (см. Шаг 19.1).

---

## Шаг 17. Обслуживание чатов

Команда для починки исторически «битых» личных чатов (dm не с ровно двумя участниками):

```bash
docker compose -f docker-compose.prod.yml exec app php artisan chat:repair-dm --strategy=group --dry-run
# затем без --dry-run для применения; --strategy=prune оставит по 2 участника
```

---

## Шаг 18. Файловое хранилище (сетевое, без монтирования)

Раздел «Файлы» подключается к сетевому хранилищу напрямую по адресу — **без монтирования**. Настройка выполняется в админке: **Файловое хранилище**.

Поддерживаемые адреса:
- `smb://server/share/path` — Windows/Samba;
- `sftp://server/path` — Linux по SSH;
- `ftp://server/path` и `ftps://server/path` (explicit TLS, порт 21);
- `https://server/dav/path` (или `webdav://…`) — WebDAV/Nextcloud.

Логин и пароль вводятся в отдельных полях и хранятся в БД **в зашифрованном виде** (никогда не возвращаются в API). Кнопка «Проверить подключение» проверяет доступ и список корня **без сохранения** настроек.

Требования к образам: в `app`/`worker` должны быть `smbclient`, расширение PHP `ftp` и расширение `imagick` (реальный JPEG/WebP-энкодер для превью; без него используется GD с PNG-фолбэком). Для PDF-превью установлен `poppler-utils` (`pdftoppm`), для видео — `ffmpeg`, для не-ZIP архивов — `p7zip-full` (`7z`). Всё уже добавлено в `backend/Dockerfile` и `Dockerfile.prod` — нужна пересборка образов.

Параметры можно задать и через переменные окружения `STORAGE_BROWSER_*` (см. `backend/.env.example`), но значения из админки имеют приоритет.

> Особенности: `ftps://` — только explicit TLS (AUTH TLS, порт 21); implicit FTPS (990) не поддерживается. Для self-signed TLS у WebDAV снимите галку «Проверять TLS-сертификат» (для FTPS нужен системный CA). Пароль в адресе указывать нельзя — только в отдельном поле.

### 18.1 Legacy: монтирование SMB/CIFS (устаревший способ)

Для существующих установок, где сетевые файлы уже смонтированы как локальный каталог, сохранён старый механизм: `docker-compose.storage-smb.yml` монтирует CIFS-ресурс, а точка монтирования указывается в легаси-настройке `storage_path`. Если адрес в админке пуст, а `storage_path` задан — используется локальный драйвер (см. `.env.example` в корне и заголовок `docker-compose.storage-smb.yml`).

### 18.2 Legacy: монтирование NFS

NFS **не поддерживается как адрес** в админке (`nfs://`): в приложении есть только адаптеры smb/sftp/ftp(s)/webdav, а NFS — это kernel-монтирование. Подключаем его так же, как SMB: монтируем через Docker и используем легаси-путь.

```bash
# .env (рядом с compose)
FILE_STORAGE_NFS_DEVICE=192.168.0.10:/export/storage
FILE_STORAGE_NFS_HOST=192.168.0.10
FILE_STORAGE_NFS_VERSION=4.1
FILE_STORAGE_MOUNT_PATH=/mnt/file-storage
```

```bash
docker compose -f docker-compose.prod.yml -f docker-compose.storage-nfs.yml up -d
```

Затем в админке → «Файловое хранилище»: **адрес пустой**, путь — `/mnt/file-storage` (либо `STORAGE_BROWSER_PATH=/mnt/file-storage`). Приложение будет использовать локальный драйвер поверх NFS.

Требования и нюансы:
- на хосте нужен NFS-клиент (Ubuntu — `nfs-common`, RHEL — `nfs-utils`); внутри контейнера ничего ставить не нужно;
- запись от uid/gid **33** (`www-data`): на NFS-сервере экспорт `all_squash,anonuid=33,anongid=33` либо права 33:33 на каталоге;
- всё держать на одном экспорте: версии/превью используют атомарный `rename` в пределах каталога;
- предпочтителен NFSv4 (`nfsvers=4.1/4.2`); для v3 нужны `rpcbind`/lockd;
- проверка монтирования: `docker compose -f docker-compose.prod.yml exec app ls -la /mnt/file-storage`.

---

### 18.3 ONLYOFFICE — редактор через тот же origin

Document Server отдаётся **браузеру** через основной nginx по пути `/onlyoffice/`, а не отдельным портом.

```dotenv
# backend/.env.production
ONLYOFFICE_ENABLED=true
ONLYOFFICE_URL=/onlyoffice          # НЕ localhost и НЕ фиксированный публичный IP
ONLYOFFICE_CALLBACK_BASE_URL=http://nginx
ONLYOFFICE_JWT_SECRET=<тот же, что JWT_SECRET сервиса onlyoffice>
```

Почему так: редактор грузит `api.js` в **браузере пользователя**. Если задать абсолютный адрес вроде
`http://<внешний IP>:8082`, он перестаёт открываться у тех, кто заходит из локальной сети — публичный IP
обычно недоступен изнутри (NAT hairpin). При `ONLYOFFICE_URL=/onlyoffice` адрес берётся из того хоста,
по которому открыто приложение, поэтому LAN-адрес, публичный IP и домен работают одинаково
(и нет проблем со mixed content при переходе на HTTPS).

Nginx проксирует `/onlyoffice/` на контейнер `onlyoffice` (`nginx/prod/default.conf`, локация
`location ^~ /onlyoffice/`). Upstream резолвится на каждый запрос, поэтому nginx стартует и без оверлея
OnlyOffice — просто отдаёт 502 на этот путь. Если меняли конфиг nginx, пересоберите его образ:

```bash
docker compose -f docker-compose.prod.yml build nginx
docker compose -f docker-compose.prod.yml -f docker-compose.onlyoffice.yml up -d
```

Проверка:

```bash
curl -fsS http://localhost/up && echo OK
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/onlyoffice/                                    # 302
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/onlyoffice/web-apps/apps/api/documents/api.js   # 200
```

Порт **8082** в `docker-compose.onlyoffice.yml` публикуется только для диагностики — приложению он
не нужен, и в firewall его открывать не обязательно.

---

## Шаг 19. Планировщик, поиск, очередь и мониторинг

### 19.1 Планировщик (`schedule:work` / cron)

Laravel-планировщик из `backend/routes/console.php` должен работать постоянно. В прод-
`docker-compose.prod.yml` для этого уже есть сервис **`scheduler`** (`command: php artisan schedule:work`,
`restart: unless-stopped`) — при штатном `docker compose up -d` он поднимается сам. Альтернатива —
внешний cron на хосте:

```cron
* * * * * cd /opt/tasksbase && docker compose -f docker-compose.prod.yml exec -T scheduler php artisan schedule:run >> /var/log/tasksbase-schedule.log 2>&1
```

Проверить, что задачи зарегистрированы:

```bash
docker compose -f docker-compose.prod.yml exec -T scheduler php artisan schedule:list
```

Здоровье планировщика: `routes/console.php` пишет heartbeat в кэш каждую минуту, а healthcheck сервиса `scheduler` выполняет `php artisan system:scheduler-health --max-age=300`. Если планировщик «залип», контейнер станет `unhealthy`. Для внешнего мониторинга можно опрашивать код возврата этой же команды:

```bash
docker compose -f docker-compose.prod.yml exec -T scheduler php artisan system:scheduler-health
echo $?   # 0 — активен, 1 — устарел/нет heartbeat
```

Расписание (UTC, часовой пояс приложения): ежечасно `one-c:pull-issues` и
`calendar:sync-feeds`, `sprints:snapshot` в 23:50, превью хранилища каждые 30 минут,
ночные prune-задачи (03:00–03:50), дайджесты уведомлений, чистка очередей,
`search:reindex` в 04:10, `search:reindex-if-stale` каждые 5 минут и
`horizon:snapshot` каждые 5 минут. Health-heartbeat: раз в минуту. У тяжёлых задач задан явный TTL блокировки
`withoutOverlapping(N)` (минуты), чтобы упавшая задача не блокировала следующие запуски сутки.
Без запущенного планировщика не работают: циклическая выгрузка из 1С, синк календарей,
дайджесты, очистки, ночная пересборка поиска и графики Horizon.

### 19.2 Поиск (`search:reindex`, `search:reindex-if-stale`)

Индексы Meilisearch и blind-индекс чата пересобираются **тремя** способами:

1. **Автоматически после деплоя.** Планировщик каждые 5 минут запускает
   `search:reindex-if-stale`: сравнивает отпечаток текущего релиза с последним
   переиндексированным и пересобирает индексы только при изменении кода. Отпечаток — это
   `APP_RELEASE` (если задан в `backend/.env.production`), иначе самый свежий mtime
   `app/` и `database/migrations/` (меняется на каждом `git checkout` + пересборке).
2. **Ночной страховочный прогон** — полный `search:reindex` в 04:10 (рассинхронизация
   индексов от событий моделей).
3. **Вручную** — после изменения состава/настроек индексов, массового импорта или
   подозрения на «пропавшие» результаты; либо чтобы форсировать пересборку в рамках деплоя:

```bash
docker compose -f docker-compose.prod.yml exec -T worker php artisan search:reindex --chunk=500
# или условный прогон (пересоберёт, только если релиз изменился):
docker compose -f docker-compose.prod.yml exec -T worker php artisan search:reindex-if-stale
```

Команды выполняют `scout:sync-index-settings`, импорт моделей `Issue`, `Page`, `File`,
`MailMessage` и `chat:reindex-search`. Во время пересборки часть результатов может
отсутствовать — запускайте полный прогон в ночное окно.

> Ключ для индексации берётся из `MEILI_MASTER_KEY`: в `config/scout.php` он
> используется как fallback для `MEILISEARCH_KEY` (оба контейнера получают один и
> тот же `env_file`). Если ключ пуст, app/worker обращаются к Meilisearch без
> заголовка `Authorization`, и задания `MakeSearchable` падают с
> `The Authorization header is missing. It must use the bearer authorization method.`
> После правки `backend/.env.production` пересоздайте `app worker scheduler`
> (`up -d --force-recreate`) и выполните `horizon:terminate`.

Чтобы деплой-переиндексация срабатывала явно, задайте в `backend/.env.production`:

```dotenv
APP_RELEASE=v1.2.3   # git describe --tags --always
```

### 19.3 Очереди, Horizon (`horizon:terminate`)

После **каждого** деплоя новый код должен подхватить воркер очередей. Horizon-мастер
запускается как `command: php artisan horizon`; корректный перезапуск:

```bash
docker compose -f docker-compose.prod.yml exec -T worker php artisan horizon:terminate
```

Мастер завершается штатно (текущие задачи доигрываются), контейнер `worker`
(`restart: unless-stopped`) поднимается заново уже на новом коде. Статус:

```bash
docker compose -f docker-compose.prod.yml exec -T worker php artisan horizon:status
docker compose -f docker-compose.prod.yml logs -f --tail=100 worker
```

Дашборд — `/horizon` (доступен администраторам). Неудачные задачи чистятся автоматически
(`queue:prune-failed --hours=168`, еженедельно) и алертятся в `NotifyFailedJob`.

**Тюнинг (`backend/config/horizon.php`).** Две группы супервизоров:
- `supervisor-default` — очередь `default` (тяжёлые задачи: почта, хранилище, синк 1С,
  фан-аут), `timeout=120`, автоскейл по времени обработки, до 6 процессов;
- `supervisor-notifications` — очередь `notifications` (короткие ntfy-пушки), `timeout=30`,
  автоскейл по размеру очереди, до 4 процессов: занятая `default` не задерживает пуш.

Порог алертов длительного ожидания (`waits`): `redis:default = 60s`,
`redis:notifications = 30s` (уведомления должны быть near real-time). При превышении
Horizon шлёт событие `LongWaitDetected`, которое слушает `App\Listeners\NotifyLongWait` и
уведомляет активных администраторов (in-app + ntfy, дедуп 15 минут). Метрики графиков
собирает `horizon:snapshot` (каждые 5 минут, Шаг 19.1).

### 19.4 Миграции (`migrate --force`)

Схема БД обновляется на `app` один раз за деплой, **до** перезапуска воркеров:

```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
```

`--force` обязателен: в `APP_ENV=production` Laravel иначе спрашивает подтверждение.
`--seed` (демо-данные) — только при первом развёртывании. Команда идемпотентна: уже
применённые миграции пропускаются. Перед потенциально разрушительными миграциями
сделайте бэкап (Шаг 11.3).

### 19.5 Трекинг ошибок (`FLARE_KEY`)

В `backend/.env.production` задайте ключ проекта Flare:

```dotenv
FLARE_KEY=<ключ из https://flareapp.io>
FLARE_REPORT_QUERY_BINDINGS=false   # не отправлять bind-параметры (могут содержать ПДн)
FLARE_SEND_LOGS_AS_EVENTS=true
```

Пустой `FLARE_KEY` полностью отключает отправку. После изменения переменных:

```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan config:clear
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.prod.yml up -d --force-recreate app worker reverb
```

Flare получает исключения веб-запросов и фоновых задач (через `spatie/laravel-ignition`,
конфиг `backend/config/flare.php`); пароли/токены и заголовки авторизации вырезаются
middleware. Проверка — намеренно вызвать исключение на staging или посмотреть, что
тестовое событие появилось в проекте.

### 19.6 Политика пина Meilisearch

В `docker-compose.prod.yml` образ зафиксирован: **`getmeili/meilisearch:v1.56.1`**
(не `:latest`). Это осознанно:

- `meilisearchdata` хранит индексы в формате конкретной версии; другой минорный релиз
  может не прочитать существующие данные;
- Meilisearch не поддерживает «прыжки» через версии — апгрейд идёт последовательно,
  через `dump` → новая версия → импорт дампа;
- `scout:sync-index-settings` и `search:reindex` рассчитаны на текущую схему индексов.

**Не меняйте тег на `:latest` и не бампайте версию «на глаз».** Порядок обновления:

1. Сделать дамп: `curl -X POST -H "Authorization: Bearer $MEILI_MASTER_KEY" http://localhost:7700/dumps` (или из контейнера).
2. Сохранить бэкап volume `meilisearchdata`.
3. Поднять целевой тег, импортировать дамп, затем прогнать `search:reindex` (19.2).
4. Проверить глобальный поиск в SPA и только после этого удалять старый дамп.

Тот же принцип — для `postgres:18-alpine` и `redis:7-alpine`: образы пиннятся по major-версии
и обновляются так же планово, а не автоматически.

---

## Быстрая шпаргалка

**Релиз (локально):**```bash
git checkout master && git pull --ff-only
(cd backend && php artisan test) && (cd frontend && npm run build)
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin master vX.Y.Z
```

**Деплой (сервер):**
```bash
cd /opt/tasksbase && git fetch --tags --prune && git checkout vX.Y.Z
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.prod.yml exec -T worker php artisan horizon:terminate
curl -fsS http://localhost/up && echo OK
```

**Обслуживание (по необходимости):**
```bash
# пересобрать индексы поиска вручную
docker compose -f docker-compose.prod.yml exec -T worker php artisan search:reindex
# список задач планировщика
docker compose -f docker-compose.prod.yml exec -T worker php artisan schedule:list
```

### Проверка OPcache/preload (prod)

```bash
# Образ должен содержать OPcache с заданным preload-скриптом
cp backend/.env.production.example backend/.env.production
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm app php -i | grep -i opcache
# ожидаем: opcache.enable => On, opcache.jit => tracing,
#          opcache.preload => /var/www/html/docker/preload.php

# Статистика preload и кэшированных скриптов (на запущенном контейнере)
docker compose -f docker-compose.prod.yml exec -T app \
  php -r "\$s=opcache_get_status(false); echo 'cached=',\$s['opcache_statistics']['num_cached_scripts'] ?? -1,' preload=',json_encode(\$s['preload_statistics'] ?? null),PHP_EOL;"
```

`preload.php` держите минимальным: слишком широкий список классов может не дать php-fpm стартовать.
