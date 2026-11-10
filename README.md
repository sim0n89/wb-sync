# wb-sync

Сервис синхронизации магазинов Wildberries: забирает FBS-заказы из Marketplace API и хранит каталог товаров,
склады и остатки для последующей выгрузки в WB.

Стек: PHP 8.4, Laravel 12, MySQL 8.4, Redis, Horizon, Pest.

## Возможности

- **Магазины** — несколько магазинов WB, у каждого свой API-токен (хранится в БД зашифрованным).
- **Заказы** — инкрементальная загрузка `GET /api/v3/orders` по курсору `next`: каждые 5 минут
  планировщик ставит в очередь задачу `SyncShopOrders` на каждый активный магазин. Заказы пишутся через
  upsert по `(shop_id, wb_order_id)`, курсор сохраняется после каждой страницы, поэтому прерванная
  синхронизация продолжается с места остановки.
- **Лимиты WB** — клиент сам ограничивает частоту запросов на токен (`WB_REQUESTS_PER_SECOND`), а при
  ответе 429 задача возвращается в очередь через `X-Ratelimit-Retry` секунд.
- **Защита от дублей** — задача уникальна на магазин и не выполняется параллельно (`ShouldBeUnique` +
  `WithoutOverlapping`), планировщик запускается `onOneServer`.
- **Каталог и остатки** — товары, размеры (`chrt_id`), баркоды, склады и остатки по складам. Остатки помнят
  последнее выгруженное в WB значение (`synced_amount`), клиент умеет отправлять их в
  `PUT /api/v3/stocks/{warehouseId}` пачками до 1000 позиций. Регулярная выгрузка остатков — в работе.
- **Эмулятор WB** — в окружениях `local` и `testing` доступен фейковый `GET /fake-wb/api/v3/orders` с
  генерируемыми заказами и опциональным rate limit, чтобы гонять синхронизацию без настоящего токена.

## Запуск в Docker

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Сервисы `compose.yaml`:

| Сервис      | Назначение                                     |
|-------------|------------------------------------------------|
| `app`       | `php artisan serve`, http://localhost:8030      |
| `horizon`   | обработчик очередей (Redis)                     |
| `scheduler` | `php artisan schedule:work`                     |
| `mysql`     | MySQL 8.4, с хоста — `127.0.0.1:3312`           |
| `redis`     | очереди, кеш, rate limiter                      |

Тестовая база `wb_sync_testing` создаётся при первом старте MySQL (`docker/mysql/init.sql`).
Локальные переопределения (порты, сеть) — в `compose.override.yaml`, он не коммитится.

## Настройки `.env`

| Переменная               | По умолчанию                              | Описание                                         |
|--------------------------|-------------------------------------------|--------------------------------------------------|
| `WB_MARKETPLACE_URL`     | `https://marketplace-api.wildberries.ru`  | базовый URL Marketplace API                      |
| `WB_REQUESTS_PER_SECOND` | `5`                                       | сколько запросов в секунду делать с одного токена |
| `FAKE_WB_RATE_LIMIT`     | `0`                                       | лимит запросов в секунду у эмулятора, `0` — без лимита |

## Команды

```bash
# добавить магазин (токен спрашивается скрыто)
php artisan app:create-shop "Мой магазин"

# поставить в очередь синхронизацию заказов всех активных магазинов (то же делает планировщик)
php artisan app:sync-orders

# эмулятор: добавить N сгенерированных заказов для токена
php artisan app:fake-wb-add-orders <token> 100
```

## Синхронизация с эмулятором

1. В `.env` указать `WB_MARKETPLACE_URL=http://app:8000/fake-wb` — по этому адресу контейнер `horizon`
   достучится до `app` (при `network_mode: host` — `http://127.0.0.1:8030/fake-wb`).
2. Создать магазин с любым токеном: `php artisan app:create-shop Test`.
3. Нагенерировать заказы под этот токен: `php artisan app:fake-wb-add-orders <token> 2500`.
4. Запустить `php artisan app:sync-orders` и смотреть задачи в Horizon: http://localhost:8030/horizon.

Чтобы проверить обработку 429, задать `FAKE_WB_RATE_LIMIT` меньше `WB_REQUESTS_PER_SECOND`.

## Тесты

```bash
docker compose exec app php artisan test
```

Тесты работают с базой `wb_sync_testing` (см. `phpunit.xml`), HTTP к WB подменяется `Http::fake()` или
эмулятором.

## Структура

```
app/Console/Commands/       artisan-команды
app/Jobs/SyncShopOrders     синхронизация заказов одного магазина
app/Services/Wildberries/   HTTP-клиент WB и DTO ответов
app/Services/FakeWildberries/, app/Http/Controllers/FakeWildberries/   эмулятор WB
routes/console.php          расписание
routes/fake-wb.php          маршруты эмулятора
```
