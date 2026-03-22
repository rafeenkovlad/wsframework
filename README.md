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

## Ограничения и замечания

- Текущий bootstrap поднимает только FFmpeg queue сервис.
- Базовые абстракции под `http`, `websocket` и `tcp` в проекте есть, но отдельные процессы под них сейчас не инициализируются.
- `RestTransportStrategy` присутствует в коде, но в текущем дереве нет action-классов с `#[RestRoute]`, поэтому активный сценарий — OpenRPC.
- Упоминания persistence-слоя намеренно сведены к минимуму: текущий проект сейчас не позиционируется как работающий через БД.

## Лицензия

Лицензия не указана в `composer.json` и отдельных license-файлах текущего репозитория.
