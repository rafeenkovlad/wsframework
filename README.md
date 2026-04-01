# WsFramework

`WsFramework` — инфраструктурный слой на PHP 8.2+ поверх [Workerman](https://github.com/walkor/workerman) для многопроцессных асинхронных сервисов.

Текущее состояние репозитория сосредоточено вокруг одного прикладного сценария: **очередь FFmpeg-задач**, принимающая запросы по HTTP в формате OpenRPC/JSON-RPC и исполняющая задачи через `proc_open`.

## Текущее состояние проекта

- Точка входа: `public/index.php`
- Активный процесс: `FfmpegQueueProcess`
- Активный транспорт: `OpenRpcTransportStrategy`
- IPC между процессами: `workerman/channel`
- Разделяемое состояние: `workerman/globaldata`
- Очередь FFmpeg находится в стадии доработки, без актуального описанного слоя хранения
- Автоперезагрузка при изменении PHP-файлов: `monitor/file-monitor.php` с `inotify`

Важно: в `.env` присутствуют переменные `MAIN_HTTP_*`, `MAIN_CHANNEL_*` и `GLOBAL_DATA_CHANNEL_*`, но текущий bootstrap в `public/index.php` поднимает только FFmpeg queue сервис.

## Стек

| Компонент | Версия | Назначение |
| --- | --- | --- |
| PHP | 8.2+ | Язык и рантайм |
| Workerman | 5.1.8 | Многопроцессный async-сервер |
| Workerman Channel | 1.2.2 | Межпроцессная коммуникация |
| Workerman GlobalData | 1.0 | Разделяемое состояние |
| Symfony DependencyInjection | 8.0 | DI-контейнер |
| Symfony Validator | 8.0 | Валидация DTO |
| Symfony Dotenv | 5.4 | Загрузка `.env` |
| Revolt Event Loop | 1.0 | Async event loop |
| PSX API | 7.5.0 | OpenRPC/OpenAPI-инструменты |

## Как это устроено

### Bootstrap

`public/index.php` делает следующее:

1. Подключает `vendor/autoload.php` и `monitor/file-monitor.php`.
2. Загружает окружение через `ENV::init()`.
3. Поднимает `FfmpegQueueChannel::main()` и `FfmpegQueueGlobalData::main()`.
4. Создаёт `FfmpegQueueProcess`, который собирает Symfony DI-контейнер и регистрирует воркер Workerman.
5. Передаёт управление в `Worker::runAll()`.

### Основные слои

- `src/Process/DefaultProcess/DefaultProcessAbstract.php` — базовый класс процесса: протокол, адрес, число воркеров, lifecycle hooks, event loop.
- `src/Process/DefaultProcess/FfmpegQueueProcess/FfmpegQueueProcess.php` — прикладной процесс FFmpeg queue.
- `src/Service/ServiceAbstract.php` — общий сервисный слой с transport strategy.
- `src/Service/FfmpegQueueService/FfmpegQueueService.php` — логика очереди, таймер обработки, запуск `ffmpeg`.
- `src/Action/Method/FfmpegQueue/*` — прикладные методы OpenRPC.
- `src/Channel/FfmpegQueueChannel.php` — IPC-канал для очереди.
- `src/GlobalData/FfmpegQueueGlobalData.php` — разделяемые данные очереди.

### Поток запроса

```text
HTTP POST / (JSON body)
    -> OpenRpcTransportStrategy
    -> MethodDTO
    -> MethodAbstract::publishChannel()
    -> Action/Method
    -> Channel / GlobalData / сервисный слой
    -> ResponseAbstract
```

## FFmpeg queue: что реализовано сейчас

### Доступные методы

| Метод | Класс | Назначение |
| --- | --- | --- |
| `Doc` | `Doc` | Получить OpenRPC-описание всех доступных методов |
| `ffmpegQueue.addJob` | `AddJob` | Добавить задачу в очередь |
| `ffmpegQueue.getJobStatus` | `GetJobStatus` | Получить статус задачи |
| `ffmpegQueue.cancelJob` | `CancelJob` | Отменить задачу в статусе `pending` |
| `ffmpegQueue.restartJob` | `RestartJob` | Перезапустить задачу в статусе `failed` |

### Что важно про реализацию сейчас

- В проекте уже есть каркас FFmpeg queue сервиса, OpenRPC-методы и таймер обработки.
- В `FfmpegQueueService` присутствует логика запуска `ffmpeg` через `proc_open`.
- README не описывает интеграцию с БД, потому что текущая версия проекта сейчас не работает через неё как через актуальное рабочее хранилище.
- Если слой хранения будет переработан, документацию по persistence лучше обновлять отдельно от общего обзора архитектуры.

### Пример OpenRPC-запроса

Сервис слушает `http://<FFMPEG_QUEUE_HOST>:<FFMPEG_QUEUE_PORT>/`.

Получить описание методов:

```bash
curl -X POST http://127.0.0.1:8091/ \
  -H 'Content-Type: application/json' \
  -d '{
    "id": 1,
    "method": "Doc",
    "params": {}
  }'
```

```bash
curl -X POST http://127.0.0.1:8091/ \
  -H 'Content-Type: application/json' \
  -d '{
    "id": 1,
    "method": "ffmpegQueue.addJob",
    "params": {
      "inputFile": "/tmp/input.mp4",
      "outputFile": "/tmp/output.webm",
      "options": {
        "-c:v": "libvpx-vp9",
        "-b:v": "1M"
      },
      "priority": 10
    }
  }'
```

## Структура проекта

```text
wsframework/
├── public/
│   └── index.php
├── src/
│   ├── Action/
│   ├── Attribute/
│   ├── Channel/
│   ├── Config/
│   ├── Dto/
│   ├── Enum/
│   ├── GlobalData/
│   ├── Middleware/
│   ├── Pool/
│   ├── Process/
│   ├── Service/
│   ├── Trait/
│   └── UseCase/
├── config/services/
├── container/php8.4/
├── environment/
├── monitor/
├── scripts/
└── docker-compose-wsframework.yml
```

## Конфигурация

### Как формируется `.env`

- `.env.setup` задаёт `APP_ENV`
- `environment/.env.local` и `environment/.env.server` содержат шаблоны окружения
- `scripts/start-build.sh` копирует `environment/.env.$APP_ENV` в корневой `.env`

`ENV::init()` читает именно `./.env`, поэтому для локального запуска файл должен существовать в корне проекта.

### Ключевые переменные окружения

| Переменная | Назначение |
| --- | --- |
| `FFMPEG_QUEUE_PROTOCOL` | Протокол воркера, сейчас используется `http` |
| `FFMPEG_QUEUE_HOST` | Host FFmpeg queue сервиса |
| `FFMPEG_QUEUE_PORT` | Порт FFmpeg queue сервиса |
| `FFMPEG_QUEUE_COUNT_PROCESS` | Число воркеров Workerman |
| `FFMPEG_QUEUE_CHANNEL_HOST` / `FFMPEG_QUEUE_CHANNEL_PORT` | IPC-канал |
| `FFMPEG_QUEUE_GLOBALDATA_HOST` / `FFMPEG_QUEUE_GLOBALDATA_PORT` | GlobalData-сервис |
| `FFMPEG_BINARY_PATH` | Путь до бинарника `ffmpeg` |
| `FFMPEG_MAX_CONCURRENT_JOBS` | Лимит одновременных задач |
| `FFMPEG_MAX_RETRIES` | Максимум retry для задачи |
| `PHP_INI` | Выбор ini-файла для Docker-образа |

## Запуск

### Через Docker

Требуется **Docker Compose v2** (Go-плагин). Проверка:

```bash
docker compose version
```

Если команда не найдена, установить (Ubuntu):

```bash
sudo apt-get update
sudo apt-get install ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install docker-compose-plugin
```

`docker-compose-wsframework.yml` ожидает внешнюю Docker-сеть `srv`.

Если её ещё нет:

```bash
docker network create srv
```

Далее:

```bash
./scripts/start-build.sh
```

Полезные команды:

```bash
./scripts/start.sh
./scripts/status.sh
./scripts/stop.sh
./scripts/down.sh
./scripts/restart.sh
./scripts/reload.sh
```

`reload.sh` в текущей реализации не делает hot reload без даунтайма: он останавливает стек, очищает `./tmp/*` и поднимает контейнер заново.

Для диагностики JetStream в compose добавлен `nats-box`:

```bash
docker exec -it container_natsbox sh
nats --server nats://nats:4222 stream ls
nats --server nats://nats:4222 stream view s3_pipeline_dlq
```

### Без Docker

Нужны:

- PHP 8.2+
- расширения `pcntl`, `sockets`, `posix`
- расширение `inotify` для file monitor
- Composer
- доступный бинарник `ffmpeg`

После подготовки окружения:

```bash
php public/index.php start
```

## Browserless Queue

### Обзор

Browserless Queue — пайплайн для снятия скриншотов и PDF со страниц через headless Chrome (Browserless).

Пайплайн: `ProcessBrowserlessCallback` → Browserless (Puppeteer) → S3 Upload → Cleanup.

Контейнер `browserless` содержит Chrome, Node.js (Browserless API) и PHP (worker).

### Fingerprint-профили

Каждый запрос использует fingerprint-профиль для anti-detection (User-Agent, WebGL, canvas, viewport и т.д.).

Доступные профили определены в `PoolBrowserFingerprint`:

| Профиль | Платформа |
| --- | --- |
| `chrome_win10` | Windows 10, Chrome 131, GTX 1660 SUPER |
| `chrome_win11` | Windows 11, Chrome 132, RTX 3060 |
| `chrome_macos` | macOS, Chrome 131, Apple M1 |
| `chrome_linux` | Linux, Chrome 131, Intel UHD 630 |
| `firefox_win10` | Windows 10, Firefox 133 |
| `safari_macos` | macOS, Safari 18.1, Apple M1 |
| `chrome_android` | Android 14, Pixel 8 Pro (mobile) |
| `safari_iphone` | iPhone, Safari 18.1 (mobile) |
| `edge_win11` | Windows 11, Edge 131, RX 580 |
| `yandex_win10` | Windows 10, Yandex Browser 24.12 |

Если `fingerprint` не указан в запросе — выбирается случайный профиль.

### Cookie Warmup

Cookies хранятся в Chrome-профиле (`storage/profiles/<fingerprint>/Default/Cookies`) в формате SQLite. PHP читает и расшифровывает их напрямую через `CookieStorageProvider` + `ChromeCookieDecryptor`.

#### Как прогреть cookies

1. Зайти в контейнер:

```bash
docker exec -it container_browserless_worker bash
```

2. Запустить warmup-сессию:

```bash
xvfb-run node /var/www/html/scripts/browserless-warmup.mjs --fingerprint chrome_win10
```

`xvfb-run` создаёт виртуальный дисплей для headless Chrome с GUI-рендерингом (нужен для корректной работы WebGL, canvas fingerprinting и прочих API, которые требуют реального рендер-контекста).

3. Подключиться к Chrome DevTools:

- Открыть `chrome://inspect` в локальном браузере
- Нажать **Configure...** → добавить `localhost:9222` → **Done**
- Найти Remote Target → нажать **inspect**

4. В открывшемся DevTools перейти на нужные сайты (google.com, youtube.com, avito.ru и т.д.) — Chrome автоматически накопит cookies в профиле.

5. Завершить сессию `Ctrl+C`. Cookies останутся в SQLite-файле профиля.

#### Проверка cookies

Проверить количество cookies в профиле:

```bash
docker exec container_browserless_worker php -r "
require '/var/www/html/vendor/autoload.php';
\$db = new SQLite3('/var/www/html/storage/profiles/chrome_win10/Default/Cookies', SQLITE3_OPEN_READONLY);
var_dump(\$db->querySingle('SELECT COUNT(*) FROM cookies'));
\$db->close();
"
```

Или через API:

```bash
curl -X POST http://localhost:8091/ \
  -H 'Content-Type: application/json' \
  -d '{
    "id": 1,
    "method": "BrowserlessQueue.ListFingerprintCookies",
    "params": {}
  }'
```

Получить расшифрованные cookies для конкретного профиля:

```bash
curl -X POST http://localhost:8091/ \
  -H 'Content-Type: application/json' \
  -d '{
    "id": 1,
    "method": "BrowserlessQueue.GetFingerprintCookies",
    "params": { "fingerprint": "chrome_win10" }
  }'
```

### Пример запроса Browserless

```bash
curl -X POST http://localhost:8091/ \
  -H 'Content-Type: application/json' \
  -d '{
    "id": 1,
    "method": "BrowserlessQueue.ProcessBrowserlessCallback",
    "params": {
      "url": "https://example.com/page",
      "outputS3Prefix": "output/screenshots/",
      "format": "png",
      "fingerprint": "chrome_win10"
    }
  }'
```

**Важно:** если нужно использовать прогретые cookies — обязательно указывайте `"fingerprint": "chrome_win10"` (или другой профиль, для которого делали warmup). Без этого параметра выбирается случайный профиль, у которого может не быть cookies.

### Cookie Management API

| Метод | Назначение |
| --- | --- |
| `BrowserlessQueue.ListFingerprintCookies` | Список профилей с количеством cookies |
| `BrowserlessQueue.GetFingerprintCookies` | Получить расшифрованные cookies профиля |
| `BrowserlessQueue.DeleteFingerprintCookies` | Удалить все cookies из профиля |

### Как работает шифрование cookies

Chrome на Linux (в контейнере без keyring) шифрует cookies в формате `v10`:

- Алгоритм: AES-128-CBC
- Ключ: `PBKDF2('peanuts', 'saltysalt', iterations=1, keylen=16)`
- IV: 16 байт `0x20` (пробел)
- Первые 3 байта `encrypted_value` — префикс `v10`, за ними AES-шифротекст

`ChromeCookieDecryptor` автоматически расшифровывает значения при чтении.

## Ограничения и замечания

- Текущий bootstrap поднимает только FFmpeg queue сервис.
- Базовые абстракции под `http`, `websocket` и `tcp` в проекте есть, но отдельные процессы под них сейчас не инициализируются.
- `RestTransportStrategy` присутствует в коде, но в текущем дереве нет action-классов с `#[RestRoute]`, поэтому активный сценарий — OpenRPC.
- Упоминания persistence-слоя намеренно сведены к минимуму: текущий проект сейчас не позиционируется как работающий через БД.

## Лицензия

Лицензия не указана в `composer.json` и отдельных license-файлах текущего репозитория.
