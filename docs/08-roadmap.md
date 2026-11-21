# Roadmap и план спринта

Сводный план: обязательное «закрытие» текущего релиза (hardening) + продуктовый бэклог.
Основан на фактическом состоянии кода (`tasksbase`, `knowledgebase`, `chat`, `crm`, `calendar`, `mail`, `storage`, `search`, `notifications`).

## Легенда

| Метка | Значение |
|---|---|
| 🔴 P0 | блокирует релиз / прод |
| 🟡 P1 | важно в ближайшие 1–2 спринта |
| 🟢 P2 | по мере возможностей |
| S / M / L | трудоёмкость: дни / 1–2 недели / спринт+ |

---

## Спринт 1. Hardening & Release Readiness

Цель: тег `v*` деплоится без ручных шагов, прод стабилен, клиенты собираются и обновляются.

### 1.1 Релиз и деплой 🔴 S
- [ ] Прогнать `deploy.yml` end-to-end на staging: `migrate --force` → `event:clear` + `optimize` → `horizon:terminate` → `search:reindex-if-stale` → `/up`.
- [ ] Прописать секреты `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_PORT`.
- [ ] Проверить прод-`docker-compose.prod.yml`: сервис `scheduler` поднимается, healthcheck зелёный, `APP_RELEASE` доходит в контейнеры.
- [ ] Выставить `FLARE_KEY` в `backend/.env.production`, убедиться, что тестовое исключение приходит.
- [ ] Пересобрать образы (`imagick`, `poppler-utils`, `ffmpeg`, `p7zip-full`) и проверить превью PDF/видео/архива.

**DoD:** деплой тега проходит автоматически; `/up` OK; превью PDF/видео работают; Flare получает ошибки.

### 1.2 Эксплуатация 🔴 S
- [ ] Прогнать `scripts/backup.sh`; проверить восстановление: `pg_restore` + Meili dump → `search:reindex`.
- [ ] Включить cron бэкапов и ротацию лога.
- [ ] Проверить long-wait алерт: искусственно задержать очередь, убедиться что `NotifyLongWait` уведомил админов.
- [ ] Добавить heartbeat планировщика: маркер в кэше + алерт, если `schedule:run` не идёт.

**DoD:** бэкап восстановлен; алерт по очереди и «залипшему» планировщику срабатывают.

### 1.3 Android 15 / targetSdk 35 🟡 M
- [x] Поднять `compileSdk`/`targetSdk` до 35, прогнать `assembleSelfupdateRelease` + unit + lint.
- [x] Edge-to-edge: `enableEdgeToEdge` в `MainActivity`, inset-aware экраны (`Scaffold`/`safeDrawingPadding`), удалён opt-out `values-v35/themes.xml`.
- [ ] Проверить FGS `remoteMessaging`, `BOOT_COMPLETED`, `POST_NOTIFICATIONS`, predictive back на устройстве/эмуляторе API 34/35.
- [ ] Решить архитектурно: push-first (ntfy) как основной канал, realtime — по требованию.

**DoD:** автозапуск и уведомления работают на API 34/35; APK собирается и устанавливается.

### 1.4 Тесты клиентов 🟡 M
- [ ] Android: JVM-тесты на outbox-репозиторий и realtime-обработку; Compose smoke.
- [ ] Desktop: тесты API-мапперов; DPAPI-тест в Windows-CI (уже есть базовый).
- [ ] Frontend: расширить Vitest/RTL на оставшиеся модули (mail/crm/storage/tasksbase); сейчас покрыты calendar, chat theme, layout/realtime, search, knowledgebase, notifications, mail-format, ProjectReportsPanel.

**DoD:** desktop/android/frontend тесты не «NO-SOURCE», критические пути покрыты.

### 1.5 Backend hardening 🟢 M
- [x] PHPStan baseline сокращён (348 → 288 записей) типизацией связей моделей; добавлен level-6 конфиг `phpstan-modules.neon` для Storage/Search/Chat + CI-job.
- [ ] Постепенный Larastan 5→6 по остальным модулям.
- [ ] Per-module coverage-пороги; поднимать глобальный `--min` (pcov добавлен в dev-образ — покрытие можно измерять локально).
- [x] `Dependabot/Renovate` для пиннутых образов + runbook апгрейда Meilisearch.

**DoD:** baseline уменьшается, пороги растут, PR на апгрейд образов по расписанию.

---

## Спринт 2. Продуктовые «быстрые победы»

### 2.1 Presence и read receipts в чате 🟡 S
- На основе `ChatUserTyping` + Reverb и `POST /chat/{id}/read`.
- Онлайн-точка в списке чатов/шапке; «N прочитали»; unread-divider и «перейти к непрочитанному».

### 2.2 Публичные страницы базы знаний 🟡 S
- Переиспользовать `StorageShare` (токен, пароль, срок жизни) + права `Page`.
- Read-only внешний портал, отзыв доступа, лог просмотров.

### 2.3 Почта: «позже» и «отменить отправку» 🟡 S
- Отложенная отправка через Horizon-задачу; undo-окно 5–30 сек.
- Шаблоны (`TemplateManagerDialog`) — как canned responses.

### 2.4 Календарь: поиск свободного слота 🟢 S
- На основе participants/events: free/busy и подбор окна для встречи.

### 2.5 KB: backlinks, TOC, «следить за страницей» 🟢 S
- Ссылки между страницами, оглавление, подписка на правки через `NotificationObserver`.

---

## Спринт 3. Средние фичи

### 3.1 Зависимости задач + Timeline/Roadmap 🟡 M
- `IssueLink`: типы `blocks/blocked by`, критический путь.
- Gantt/Timeline по спринтам/эпикам; вехи.

### 3.2 Автоматизации Tasksbase 🟡 M
- Перенести движок `CrmAutomationRule` на задачи: триггеры (смена статуса, дедлайн), действия (назначить, уведомить, SLA-эскалация).

### 3.3 Timesheet и согласование часов 🟢 M
- На основе `IssueWorklog` + timer: недельный отчёт, подтверждение, экспорт для биллинга.

### 3.4 Team inbox для почты 🟢 M
- Общий ящик, назначение на сотрудника, snooze, ответы от команды.

### 3.5 Безопасность аккаунта 🟡 M
- 2FA (TOTP), список активных сессий и отзыв, уведомление о новом входе (`LoginJournal`).

### 3.6 Админ-audit-центр 🟢 S/M
- Единый UI по `storage` audit + `login-logs`: фильтры, экспорт.

### 3.7 Android home-widget и quick reply 🟢 M
- Виджет непрочитанных, быстрый ответ из уведомления.

---

## Спринт 4+. Крупные ставки

### 4.1 AI-ассистент по базе и задачам ✅
- «Спроси базу», суммаризация страниц/тредов, черновики задач и писем.
- Локальная модель (Ollama) — данные не уходят наружу; Meili как retrieval.
- Реализовано: «Спроси базу», резюме страницы, черновики задач/писем (см. «Выполнено»).

### 4.2 Видеозвонки/скриншеринг в чате ✅
- WebRTC + Reverb для сигналинга; TURN/STUN; запись (опционально).
- Реализовано (1:1 аудио/видео; скриншеринг/запись — по спросу): см. «Выполнено».

### 4.3 PWA-офлайн для web ✅
- Service worker + кэш страниц/задач по образцу Android/Desktop outbox.
- Реализовано: см. «Выполнено» (PWA offline). Очередь offline-записей — по спросу.

### 4.4 Гибкие роли и права ✅
- Конструктор ролей вместо фиксированных admin/member; права на проект/пространство.
- Реализовано ранее (см. «Выполнено»): конструктор ролей, матрица прав, scope-оверрайды.
### 4.5 Авторизация в приложении ✅
- В приложении APK preset нужно добавить возможность авторизации через qr code. Пользователь на сайте открыввает код, его сканирует и заходит в приложене на мобильном телефоне
- Реализовано: см. «Выполнено» (QR-логин).
---
### 4.6 Сборка из админки ✅

- В админпанели реализовать сборку apk/msi preset с указанием параметров
- Реализовано: страница «Сборки клиентов» диспатчит GitHub Actions `build-clients.yml` (см. «Выполнено»).

## Рекомендуемая последовательность

1. **Спринт 1** — целиком (релиз, эксплуатация, Android 15, тесты, backend hardening).
2. **Спринт 2** — 2.1 + 2.2 + 2.3 (максимум видимой ценности при малой трудоёмкости).
3. **Спринт 3** — 3.1 + 3.2 + 3.5.
4. **Спринт 4+** — 4.1 как отдельный трек (пилот на одном пространстве), остальное по спросу.

---

## Definition of Done (общий)

- Тесты зелёные: backend (PHPUnit), frontend (Vitest/RTL), desktop (`:desktop:test`), android (unit + assemble).
- `pint --test` + `phpstan` без новых ошибок; `npm run lint`/`build` проходят.
- Миграции идемпотентны, откат задокументирован.
- Обновлён `DEPLOY.md`/`docs/*`, если менялись эксплуатация или API.
- Для клиентов: собранные `apk`/`msi`, версия поднята, manifest обновления проверен.

---

## Открытые вопросы

1. Приоритет: продуктовые фичи vs дальнейший hardening?
2. AI-ассистент: допустима ли локальная модель (Ollama) и какие данные можно отдавать?
3. Нужны ли внешние интеграции (webhooks, календарные booking-ссылки) в этом квартале?
4. Целевые платформы: остаётся ли web+Android+Windows или планируется iOS?

---

## Выполнено

- **Preset (branded) сборки** для Android и Desktop: сервер/ntfy зашиваются на этапе сборки из `android/branding/<name>.json`, поля скрыты в UI, второй сервер добавить нельзя; `updates` — общий канал. См. `android/branding/README.md` и `DEPLOY.md` 14.5.
- **Планировщик:** heartbeat (`routes/console.php`) + `system:scheduler-health`, healthcheck сервиса `scheduler`, тесты `SchedulerHealthTest`. См. `DEPLOY.md` 19.1.
- **Android 15:** `compileSdk`/`targetSdk` 35 (`android.suppressUnsupportedCompileSdk=35`), FGS `remoteMessaging`; edge-to-edge пока отключён через `windowOptOutEdgeToEdgeEnforcement` (values-v35) — миграция на inset-aware UI остаётся задачей.
- **Зависимости:** `.github/dependabot.yml` (composer/npm/gradle/actions); пиннутые образы (postgres/redis/meilisearch) обновляются вручную по политике.
- **Тесты клиентов:** frontend Vitest для календарных утилит; desktop/android — `PresetConfigTest`, outbox/DPAPI.
- Продуктовые фичи (presence, публичные страницы, mail «позже»/undo, timeline) — в бэклоге ниже.
- **Mail: «Отменить отправку» и «Отправить позже»** — 5-сек. окно отмены в `ComposeDialog`; отложенная отправка хранится черновиком с `mail_messages.scheduled_at` и отправляется job'ом `SendScheduledMail` (отмена — `scheduled_at=null`). Тесты `MailScheduledSendTest`.
- **Календарь: поиск свободного времени** — `GET /api/v1/calendar/free-slots` (`FreeSlotFinder`, права через `visibleTo`, шаг 15 мин), диалог `FreeSlotPickerDialog` в `EventDialog` («Найти время» → подстановка начала/конца). Тесты `CalendarFreeSlotTest`.
- **Публичные страницы базы знаний** — `page_shares` (хэш токена, пароль, срок, отзыв), API `pages/{id}/shares` + анонимный `GET /api/v1/public/pages/{token}` (тело санитизируется), фронт: `PageShareDialog` («Поделиться» в просмотре страницы) и маршрут `/share/page/:token`. Тесты `PageShareTest`.
- **KB: backlinks, TOC и подписка на страницу** — `GET /api/v1/knowledgebase/pages/{id}/backlinks`; оглавление и якоря заголовков в просмотре; `page_followers` + `POST/DELETE /pages/{id}/follow`, уведомление подписчиков (`page_updated`) при правках. Тесты `PageBacklinkTest`, `PageFollowTest`.
- **Чат: read receipts (web)** — `chat_members.last_read_message_id`, `POST /chat/{id}/read` с `message_id` + broadcast `ChatRead`, `GET /chat/{id}/reads`; фронт `useChatReads` и «✓✓» с тултипом «Прочитали». Presence и паритет Android/Desktop — остаются. Тесты `ChatReadReceiptTest`.
- **Tasksbase: Timeline / Дорожная карта** — `GET /api/v1/tasksbase/projects/{key}/timeline` (задачи с датами, статус/исполнитель/эпик, `blocked_by_count`), страница `/timeline` (полосатый Gantt на чистом CSS) + пункт меню. Связи `blocks/is_blocked_by` уже были. Тесты `ProjectTimelineTest`.
- **Tasksbase: автоматизации** — `task_automation_rules` (global/проектные), триггеры `issue_created`/`status_changed`, действия `assign`/`set_priority`/`notify`; движок `TaskAutomationEngine` подключён к create/update/move; CRUD-API + страница `/automations`. Тесты `TaskAutomationTest`.
- **Безопасность: активные сессии** — `GET /auth/sessions`, `DELETE /auth/sessions/{id}`, `DELETE /auth/sessions` (все, кроме текущей); карточка «Активные сессии» в профиле. Тесты `AuthSessionsTest`.
- **Безопасность: 2FA (TOTP)** — свой `Totp` (SHA-1/6/30), шифрованные secret/recovery на `users`, `auth/2fa` (`status`/`setup`/`confirm`/`disable`) и `auth/2fa/challenge` (шифрованный challenge при логине); веб-логин и карточка в профиле. Паритет Android/Desktop: обработка `two_factor_required` и ввод кода. Тесты `TwoFactorTest`.
- **Чат: presence (web)** — канал `chat.{id}.presence` (участник чата), хук `useChatPresence`; в шапке — «N онлайн» / «онлайн» для DM. Паритет Android/Desktop реализован: presence-канал в `RealtimeClient` (`subscribePresence`/`onPresence`) и «N онлайн»/«онлайн» в шапке чата на обоих клиентах.
- **Tasksbase: табель** — `GET /api/v1/tasksbase/timesheet` (агрегация по дням/задачам, только свой или админ) и `GET /timesheet/export` (CSV); страница `/timesheet` с недельной навигацией и экспортом. Согласование часов — позже. Тесты `TimesheetTest`.
- **Админ-audit-центр** — журнал действий и входов уже были; добавлен `admin/storage-audit` (фильтры по пользователю/действию/статусу/датам/пути + CSV) и пункт меню. Тесты `StorageAuditPageTest`.
- **Android: home-widget непрочитанных** — `ChatWidgetProvider` + layout/info, сводка пишется из `ChatListScreen` в SharedPreferences, клик открывает приложение. **Quick reply из уведомления** — `NotificationReplyReceiver` + `RemoteInput` в действии «Ответить» (отправка через outbox).
- **Почта: триаж (основа team inbox)** — `assigned_to`, `snoozed_until`, `is_done`; действия «Назначить мне», «Готово», «Отложить на день» в просмотре письма. Тесты `MailTriageTest`.
- **Почта: общий ящик (team inbox)** — `mail_account_shares` (`can_send`), доступ к письмам владельца ИЛИ по шаре (`MailMessage::accessibleBy`), фильтр `account_id` в списке; API `GET /mail/accounts`, `GET|POST|DELETE /mail/settings/members`, отправка от имени ящика (`account_id`). Фронт: переключатель ящиков, выбор «От» в composer, секция «Общий ящик» в настройках. Тесты `MailSharedMailboxTest`.
- **ONLYOFFICE: настройка автосохранения** — админ-тумблер `office_autosave_enabled` (сервис `OfficeSettings`) в «Администрировании хранилища»; `customization.autosave` в storage/CRM-конфигах берётся из настройки, `forcesave` остаётся для ручного сохранения. Тесты `OfficeTest`, `StorageAdminQuotaTest`.
- **AI-ассистент (пилот 4.1)** — `GET /ai/status`, `POST /ai/ask`; retrieval через permission-aware поиск (Meili + DB-fallback), генерация локальной Ollama (`AiAssistant`, `config/ai.php`, env `AI_ENABLED`/`OLLAMA_URL`/`AI_MODEL`), страница `/assistant` и пункт меню. Опциональный `docker-compose.ollama.yml`. Тесты `AiAssistantTest`.
- **QR-логин (4.5)** — `POST /auth/qr/start` (auth) выдаёт одноразовый код (TTL 2 мин, хранится только SHA-256, `qr_login_sessions`), `POST /auth/qr/claim` (guest, throttle) обменивает код на Sanctum-токен. Веб: карточка «Вход в приложение по QR» в профиле (`qrcode`). Android: кнопка «Войти по QR-коду» — сканер (`zxing-android-embedded`), парсинг `veltiq://login?...` (хост/порт/схема + код, preset игнорирует хост), `AuthRepository.qrLogin`. Тесты `QrLoginTest`.
- **PWA offline (4.3)** — `public/sw.js` (navigation network-first → кэш SPA-шелла; GET `/api/*` network-first с runtime-кэшем, кроме `/auth` и `/storage/raw`; статика cache-first), `manifest.webmanifest` + иконки, регистрация SW только в prod (`registerServiceWorker`), баннер «нет сети» (`OfflineBanner`), очистка runtime-кэша при выходе (`clearOfflineCaches`). nginx: `/sw.js` и `/manifest.webmanifest` с корректным cache-control. Очередь offline-записей — позже.
- **Видеозвонки в чате (4.2)** — WebRTC 1:1 аудио/видео. Сигналинг (invite/offer/answer/ice/hangup) релеится через private-канал чата (`App\Events\CallSignal`, `POST /chat/{id}/call/signal`, `throttle:240,1`). Фронт: `useCall` (RTCPeerConnection, STUN/TURN из `VITE_STUN_URL`/`VITE_TURN_*`), `CallOverlay` (принять/отклонить, mute, камера, завершить), кнопки звонка в шапке DM-чата. Скриншеринг/запись — по спросу. Тесты `CallSignalTest`.
- **AI: резюме и черновики (4.1)** — `POST /ai/summarize` (резюме страницы KB, ACL через `Space::visibleTo` + `PageAcl`), `POST /ai/draft` (`kind: task|mail`). Фронт: кнопка «Резюме» в просмотре страницы, блок «Черновик» на `/assistant`. Тесты `AiAssistantTest`.
- **Гибкие роли и права (4.4)** — уже в коде: конструктор ролей (`admin/roles` CRUD + матрица `role_has_permissions`), `PermissionCatalogue` (global/project/space), `PermissionService` ( deny>allow, scope→global, baseline → role), admin-страницы `access/projects|spaces` + overrides (`ScopePermission`). Тесты `AdminRoleTest`, `AdminAccessOverrideTest`, `PermissionServiceTest`.
- **Сборка клиентов из админки (4.6)** — страница `admin/client-builds`: настройки GitHub (repo/workflow/ref, токен шифрованно), форма параметров (бренд, название, сервер, ntfy), запуск `workflow_dispatch` через `ClientBuildService` (GitHub API), список последних запусков. Workflow `.github/workflows/build-clients.yml` пишет `branding/<name>.json` и собирает preset APK (ubuntu) + MSI (windows), артефакты — в Actions. Env: `CLIENT_BUILD_REPO`/`_WORKFLOW`/`_REF`/`_TOKEN`. Тесты `AdminClientBuildTest`.
- **Hardening: baseline phpstan очищен** — типизированы связи моделей (`Page`/`Space`/`Project`/`Issue`/`IssueWorklog`/`MailMessage`/`PageShare` → `BelongsTo<T,$this>` / `HasMany<T,$this>` / `BelongsToMany<T,$this>`), убраны ложные nullsafe/сравнения (AuthController, FreeSlotFinder, PageShare), исправлены реальные баги `->with('user')` на `belongsToMany(User)` в members-эндпоинтах (`Space`/`Project`). Baseline перегенерирован (сокращён), `phpstan analyse` — **0 ошибок**; Pint ✓.

