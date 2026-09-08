# Property Offers API

REST API on Laravel 12 that:

- imports accommodation offers from suppliers asynchronously (queue);
- returns the cheapest bookable offer per property;
- reserves an offer without overselling the last unit.

Stack: PHP 8.2+ / Laravel 12 / MySQL 8 / database queue / Sail (Docker).

**Repository (commit history):** https://github.com/MelnykSheepfish/laravel-property-api

**Postman collection:** [postman/Property-Offers-API.postman_collection.json](https://github.com/MelnykSheepfish/laravel-property-api/blob/feature/PA-0-implement-propery-api/postman/Property-Offers-API.postman_collection.json)

## Requirements

- Docker + Docker Compose (Laravel Sail)
- or local PHP 8.2+, Composer, MySQL 8

Config template: [`.env.example`](.env.example) (no secrets).

## Setup (Sail)

```bash
cp .env.example .env
composer install
vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail artisan db:seed
```

Seed creates suppliers `supplier-a` and `supplier-b`.

```bash
# queue worker (imports stay pending until this runs)
vendor/bin/sail artisan queue:work

# tests (MySQL `testing` database; required for window functions)
vendor/bin/sail artisan test

# reset DB
vendor/bin/sail artisan migrate:fresh --seed
```

Without Sail: point `DB_*` in `.env` at MySQL and use `php artisan` instead of `vendor/bin/sail artisan`.

## API

### `POST /api/imports` → `202`

Queues an import. Unknown supplier / invalid payload → `422`. The same `(supplier, external_import_id)` returns the original import and does not queue it again.

```bash
curl -X POST http://localhost/api/imports \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "offers": [{
      "external_id": "offer-a-10001",
      "property": {"code": "BCN-0001", "name": "Apartment near Sagrada Familia", "city": "Barcelona"},
      "check_in": "2026-10-10", "check_out": "2026-10-15",
      "max_guests": 4, "price": 72500, "currency": "EUR",
      "available_units": 2, "expires_at": "2026-12-31T23:59:59Z"
    }]
  }'
```

```json
{ "data": { "id": 1, "status": "pending" } }
```

### `GET /api/imports/{import}`

Status of the async import (`pending` / `processing` / `completed` / `failed`).

### `GET /api/properties`

Required: `check_in`, `check_out`, `guests`. Optional: `city`, `page`, `per_page`.

```bash
curl -H 'Accept: application/json' \
  'http://localhost/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2'
```

Pagination uses Laravel's standard resource shape: `links.next`, `links.prev`, `meta.per_page`.

### `POST /api/offers/{offer}/reservations` → `201`

```bash
curl -X POST http://localhost/api/offers/1/reservations \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{
    "client_reference": "web-order-9f782b1c",
    "customer_name": "John Smith",
    "customer_email": "john@example.com"
  }'
```

Same `client_reference` again → existing reservation with `200`. Sold out / expired / reference used on another offer → `409`.

## Import idempotency

Unique index on `imports (supplier_id, external_import_id)`. A repeat `POST /api/imports` with the same pair returns the existing import (`202`) and does **not** dispatch `ProcessImportJob` again. If two requests race, one insert wins; the other hits the unique constraint, loads the existing row, and still does not queue a second job.

Offers are unique on `(supplier_id, external_id)` and updated in place. An older `sent_at` does not overwrite newer data. The job runs in one DB transaction: on failure the import is marked `failed` and partial writes are rolled back. Re-import keeps `reserved_units`; if the new `available_units` is below current reservations, the import fails.

## Last-unit reservation

`ReservationService` opens a transaction and locks the offer with `SELECT … FOR UPDATE`. Stock and expiry are checked under that lock, then the reservation row is inserted and `reserved_units` is incremented. Two concurrent clients cannot both take the final unit: the second waits on the lock, then sees sold out and gets `409`.

`client_reference` is unique. A retry with the same reference returns the existing reservation (`200`), or `409` if that reference already belongs to another offer.

## Assumptions (confirmed)

1. Search dates match exactly (`check_in` / `check_out` equality). Nested ranges are not required.
2. `properties.code` is global (shared across suppliers). First create wins `name` / `city`.
3. `client_reference` is an idempotency key for reservations.
4. Fresher `sent_at` wins when imports for the same offer arrive out of order.
5. Reservations survive re-import; `available_units < reserved_units` fails the import.
6. Currency is treated as uniform; no FX conversion. `price` is for the whole stay (minor units).
7. One reservation books one unit.

---

## Пояснення 

### Ідемпотентність імпорту

Імпорт унікальний по парі постачальник + `external_import_id` (unique у БД). Повторний `POST /api/imports` з тією ж парою не створює новий запис і не ставить другу джобу в чергу: повертається вже існуючий імпорт. Якщо два запити прилітають одночасно, один insert проходить, другий ловить unique constraint і теж бере існуючий рядок без повторної обробки.

Офери всередині імпорту оновлюються на місці по `(supplier_id, external_id)`. Старіший `sent_at` не перетирає свіжіші дані. Обробка йде в одній транзакції: помилка → статус `failed`, часткові зміни відкочуються. Бронювання (`reserved_units`) при повторному імпорті не скидаються; якщо новий `available_units` менший за вже заброньоване, імпорт падає.

### Захист від двох одночасних бронювань останньої одиниці

Бронювання йде в транзакції з песимістичним локом на офер: `SELECT ... FOR UPDATE`. Поки перший клієнт тримає лок, другий чекає. Після коміту першого вільних місць уже немає: другий отримує `409`, а не другу бронь на той самий останній юніт.

`client_reference` унікальний: повтор того самого ключа повертає існуючу бронь (`200`), а не списує місце ще раз.