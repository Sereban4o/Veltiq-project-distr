# WebSocket и Real-time события

## 1. Обзор

Real-time построен на Laravel Reverb (Pusher-протокол; на клиенте `laravel-echo` + `pusher-js`).
Сервер уже рассылает событие по каналам `project/board/issue/space/page/user/chat`; веб-клиент
подписывается на них через единый Echo-клиент и инвалидирует кэши React Query.

## 2. Каналы

Авторизация — `backend/routes/channels.php`.

| Канал | Доступ | Назначение |
|---|---|---|
| `project.{projectId}` | участники проекта или public | события задач/доски |
| `issue.{issueId}` | участники проекта задачи | события конкретной задачи |
| `board.{boardId}` | участники проекта доски | изменения доски/задач |
| `space.{spaceId}` | участники пространства или public | изменения страниц |
| `page.{pageId}` | участники пространства страницы | изменения/комментарии страницы |
| `page.{pageId}.presence` | любой авторизованный | совместное редактирование (presence) |
| `chat.{chatId}` (+`.presence`) | участники чата | чат |
| `user.{userId}` | сам пользователь | уведомления, чат-сводка, почта |

## 3. События (as-broadcast)

Имя на проводе задаётся `broadcastAs()`; в Echo его слушают с ведущей точкой (`listen('.issue.updated')`).

### 3.1 Задачи и доска

| Класс | `broadcastAs` | Каналы | Payload |
|---|---|---|---|
| `App\Events\IssueUpdated` | `issue.{action}` (`created`/`updated`/`deleted`) | `project.*`, `issue.*`, `board.*` | `{ id, key, summary, status_id, assigned_to, priority, action, timestamp }` |
| `App\Events\BoardUpdated` | `board.{action}` | `board.*`, `project.*` | `{ board_id, action, column_id, issue_id, order, timestamp }` |
| `App\Events\NewComment` | `new_comment` | `issue.*` или `page.*` | `{ id, body, user: {id,name,initials}, timestamp }` |

### 3.2 База знаний

| Класс | `broadcastAs` | Каналы | Payload |
|---|---|---|---|
| `App\Events\PageUpdated` | `page.{action}` (`created`/`updated`/`deleted`) | `space.*`, `page.*` | `{ id, title, status, version, action, timestamp }` |
| `App\Events\NewComment` | `new_comment` | `page.*` | как выше |

Presence: `page.{id}.presence` возвращает `{ id, name }` участника (`here`/`joining`/`leaving`).

### 3.3 Чат

| Класс | `broadcastAs` | Каналы |
|---|---|---|
| `MessageCreated` | `MessageCreated` | `chat.*`, `user.*` |
| `MessageUpdated` | `MessageUpdated` | `chat.*` |
| `MessageDeleted` | `MessageDeleted` | `chat.*` |
| `ChatDeleted` | `ChatDeleted` | `chat.*`, `user.*` |
| `ChatRead` | `ChatRead` | `chat.*` |
| `UserTyping` | `UserTyping` | `chat.*` |

### 3.4 Уведомления и почта

| Класс | `broadcastAs` | Канал | Payload |
|---|---|---|---|
| `App\Events\NotificationCreated` | `notification.created` | `user.*` | уведомление |
| `App\Events\MailMessageCreated` | `mail.created` | `user.*` | `{ account_id, message_id, folder, timestamp }` (без темы/тела) |

Почта: `MailSyncService::storeParsed()` рассылает `MailMessageCreated`, но **троттлится** —
не более одного события на пользователя за `config('mail.realtime_throttle_seconds', 5)` секунд,
чтобы пачка писем из sync давала одно обновление.

## 4. Клиент

Единый Echo-клиент: `frontend/src/modules/chat/realtime.ts` (`getEcho(token)`).

Потребители:
- чат — `frontend/src/modules/chat/realtime.ts` (`chat.*`, `user.*`);
- уведомления — `frontend/src/modules/notifications/realtime.ts` (`user.*` → `.notification.created`);
- **общий `user.*`** — `frontend/src/modules/shared/realtime/userChannel.ts` (reference-counting,
  чтобы чат/уведомления/почта не «отписывали» канал друг у друга);
- задачи/доска — `frontend/src/modules/tasksbase/realtime.ts` (`board.*`/`project.*`/`issue.*`);
- база знаний + presence — `frontend/src/modules/knowledgebase/realtime.ts` (`space.*`/`page.*`, `page.*.presence`);
- почта — `frontend/src/modules/mail/realtime.ts` (`user.*` → `.mail.created`).

Обработка: события инвалидируют связанные ключи React Query (с дебаунсом ~300 мс), кроме
присутствия/счётчиков. `useTasksbaseRealtime` принимает `boardId`/`projectId`/`issueId`;
`useKnowledgebaseRealtime` — `spaceId`/`pageId`.

### 4.1 Статус соединения и догон

`frontend/src/modules/shared/realtime/realtimeStatus.ts` слушает `state_change` Pusher-соединения
(`connected/connecting/unavailable/failed/disconnected`). Индикатор — в `AppLayout`
(компонент региона `realtime-status`). `useRealtimeConnection()` при возврате в `connected`
делает один разовый `queryClient.invalidateQueries()` — догоняет пропущенное за время обрыва.

## 5. Fallback

Если Reverb/сеть недоступны, интерфейс продолжает работать: кэши React Query и ручное
обновление (кнопки sync/refresh) остаются основным путём; индикатор показывает `Оффлайн`,
после переподключения данные догоняются автоматически.

## 6. Настройка

### Backend
```bash
# .env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
```
```bash
php artisan reverb:start
```

### Frontend
```bash
# .env
VITE_REVERB_HOST=...
VITE_REVERB_PORT=...
VITE_REVERB_SCHEME=https
VITE_REVERB_KEY=...
```
Клиент также понимает устаревшие `VITE_PUSHER_*` переменные.
