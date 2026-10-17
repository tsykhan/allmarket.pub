# allmarket.biz Seller API — контракт для модуля PrestaShop

Цей документ описує, що має реалізувати портал allmarket.biz, щоб модуль `allmarketsync`
міг працювати в режимі **API**. У режимі **Фід** портал лише завантажує XML за посиланням
(див. розділ «XML-фід»), а замовлення може надсилати вебхуком.

Еталонна реалізація для тестів: `tools/mock_allmarket.php` (PHP, ~150 рядків).

## Загальне

- Базовий URL задається в налаштуваннях модуля, за замовчуванням `https://allmarket.biz/api/v1`.
- Формат: JSON, UTF-8. Запити з тілом мають `Content-Type: application/json; charset=utf-8`.
- Автентифікація — заголовки в кожному запиті:
  - `Authorization: Bearer <API-ключ продавця>`
  - `X-Allmarket-Shop: <ID продавця>`
- Успіх: HTTP 2xx і JSON-об'єкт. Поле `"ok": false` у відповіді 2xx модуль вважає помилкою.
- Помилка: HTTP 4xx/5xx, тіло `{"ok": false, "error": {"code": "...", "message": "..."}}`
  (або `"error": "текст"`).
- Повтори: модуль повторює запит до 3 разів при мережевій помилці, `429` і `5xx`
  (пауза 2 с, 4 с). `401/403` зупиняють задачу. Інші `4xx` для пакета — пакет пропускається,
  решта продовжується.
- Ідентифікатор позиції — `external_id`: `"<id_product>"` для простого товару,
  `"<id_product>-<id_product_attribute>"` для комбінації. Він стабільний і унікальний у межах продавця.

### Пакетні відповіді

Для `*/batch` і `/products/delete` портал повертає результат по кожній позиції:

```json
{
  "ok": true,
  "results": [
    {"external_id": "12", "status": "created"},
    {"external_id": "1-3", "status": "updated"},
    {"external_id": "7", "status": "error", "error": "category is required"}
  ]
}
```

`status`: `ok | created | updated | deleted | error`. Позиції, яких немає в `results`, модуль
вважає прийнятими. Позиції зі статусом `error` будуть надіслані знову під час наступної синхронізації.

---

## GET /ping

Перевірка ключа. Відповідь: `{"ok": true, "shop": {"id": "777", "name": "Назва продавця"}}`.

## POST /categories/batch

Дерево категорій магазину (надсилається, коли воно змінилось; до 500 у запиті).

```json
{
  "categories": [
    {"id": "3", "parent_id": null, "name": "Одяг", "url": "https://shop.ua/3-clothes", "allmarket_category_id": "101"},
    {"id": "4", "parent_id": "3", "name": "Чоловічий", "url": "https://shop.ua/4-men", "allmarket_category_id": "101"},
    {"id": "87", "parent_id": null, "name": "Смарт рішення", "url": "https://shop.ua/87-smart", "allmarket_category_id": null, "allmarket_parent_id": "14"}
  ]
}
```

`allmarket_category_id` — категорія порталу з панелі «Розміщення категорій на allmarket.biz»
(«Відповідає категорії порталу», правило `id=X`; успадковується підкатегоріями) або `null`.
`allmarket_parent_id` — правило «Створити всередині» (`id>X`) саме на цій категорії: портал створює її
підкатегорією `X` (не успадковується). Без правил категорія йде за батьківською, верхній рівень — у корінь порталу.
Відсутні на порталі категорії створюються; при зміні правил портал переносить створені ним раніше категорії
разом з товарами, а порожні — видаляє. У відповіді `results[].portal_category_id` — категорія порталу.

## GET /portal-categories

Дерево категорій порталу для вибору в модулі: `{"ok": true, "categories": [{"id": "8", "parent_id": "7",
"name": "Харчове обладнання", "path": "Обладнання > Промислове обладнання > Харчове обладнання", "depth": 2, "items": 358}]}`
(у порядку обходу дерева).

## POST /products/batch

Створення або повне оновлення позицій (upsert за `external_id`). Надсилаються нові позиції
та ті, в яких змінився опис/фото/характеристики тощо. Розмір пакета — з налаштувань (за замовчуванням 100).

```json
{
  "products": [
    {
      "external_id": "1-1",
      "group_id": "1",
      "product_id": 1,
      "combination_id": 1,
      "name": "Футболка з колібрі (S, Білий)",
      "group_name": "Футболка з колібрі",
      "sku": "demo_1",
      "ean": "",
      "mpn": "",
      "brand": "Studio Design",
      "category_id": "4",
      "category_path": "Одяг > Чоловічий",
      "allmarket_category_id": "101",
      "description": "<p>HTML-опис</p>",
      "short_description": "<p>Короткий опис</p>",
      "url": "https://shop.ua/men/1-1-hummingbird.html#/size-s/color-white",
      "images": ["https://shop.ua/2-large_default/hummingbird.jpg", "https://shop.ua/1-large_default/hummingbird.jpg"],
      "attributes": [
        {"name": "Розмір", "value": "S", "type": "variant"},
        {"name": "Колір", "value": "Білий", "type": "variant"},
        {"name": "Склад", "value": "Бавовна", "type": "feature"}
      ],
      "condition": "new",
      "weight": 0.3,
      "weight_unit": "kg",
      "dimensions": {"width": 0, "height": 0, "depth": 0, "unit": "cm"},
      "minimal_quantity": 1,
      "meta_title": "",
      "meta_description": "",
      "price": 19.12,
      "old_price": 23.9,
      "currency": "UAH",
      "quantity": 300,
      "available": true
    }
  ]
}
```

- `group_id` — є лише в комбінацій: позиції з однаковим `group_id` є варіантами одного товару.
  Для простого товару `null`.
- `price` — ціна продажу з ПДВ і з урахуванням знижок та націнки модуля; `old_price` — ціна до
  знижки або `null`.
- `available` — чи можна замовити (активний, «доступний для замовлення», є залишок або дозволено
  замовлення без залишку). `quantity` ≥ 0.
- Перше фото — головне (обкладинка або фото комбінації).

## POST /offers/batch

Лише ціна й наявність уже відомих порталу позицій (часта синхронізація, кожні 10–15 хв).

```json
{
  "offers": [
    {"external_id": "1-1", "price": 19.12, "old_price": 23.9, "currency": "UAH", "quantity": 298, "available": true},
    {"external_id": "6", "price": 11.9, "old_price": null, "currency": "UAH", "quantity": 0, "available": false}
  ]
}
```

Якщо `external_id` невідомий, поверніть для нього `status: "error"` — модуль автоматично
надішле повну картку через `/products/batch` під час наступного запуску.

## POST /products/delete

Позиції, що зникли з вивантаження (товар видалено, вимкнено, виключено фільтрами).

```json
{"external_ids": ["8", "16-29"]}
```

Захист: якщо за один запуск зникає понад 50% позицій (і більше 20), модуль не видаляє їх,
а пише попередження в журнал — доки не буде запущено синхронізацію з прапорцем «Примусово».

---

## GET /orders

Замовлення для імпорту в магазин.

Параметри: `updated_since` (ISO 8601, з попередньої відповіді `server_time`), `page` (з 1), `limit` (50).

Портал має повертати замовлення, які **ще не підтверджені** (`/ack`) **або** змінені після `updated_since`.

```json
{
  "ok": true,
  "server_time": "2026-10-16T08:00:00Z",
  "has_more": false,
  "orders": [
    {
      "id": "AM-100523",
      "number": "100523",
      "status": "new",
      "created_at": "2026-10-16T08:00:00+03:00",
      "currency": "UAH",
      "customer": {"first_name": "Іван", "last_name": "Петренко", "email": "ivan@example.com", "phone": "+380671234567"},
      "delivery": {
        "method": "nova_poshta",
        "method_name": "Нова Пошта",
        "country": "UA",
        "region": "Київська",
        "city": "Київ",
        "warehouse": "Відділення №5",
        "address": "",
        "postcode": "01001",
        "recipient_first_name": "Іван",
        "recipient_last_name": "Петренко",
        "cost": 70
      },
      "payment": {"method": "cod", "method_name": "Накладений платіж", "paid": false},
      "comment": "Подзвоніть перед відправкою",
      "items": [
        {"external_id": "1-1", "sku": "demo_1", "name": "Футболка (S, Білий)", "price": 15.0, "quantity": 2}
      ],
      "total": 100.0
    }
  ]
}
```

- Товар шукається за `external_id`, якщо його немає — за `sku` (артикул комбінації, потім товару).
- `items[].price` — ціна за одиницю з ПДВ; застосовується, якщо ввімкнено «Ціни із замовлення allmarket.biz».
- `delivery.cost` — вартість доставки з ПДВ; застосовується, якщо ввімкнено відповідне налаштування.
- `payment.paid: true` → замовлення отримує статус «оплаченого» з налаштувань.
- `email` необов'язковий: без нього створюється гість `am-<id>@allmarket.invalid`.
- `status`: `new | accepted | processing | shipped | delivered | cancelled | returned`.
  Нові замовлення зі статусом `cancelled`/`returned` не імпортуються. Для вже імпортованих
  зміна статусу застосовується за таблицею відповідності (у зворотному напрямку).

## POST /orders/{id}/ack

Підтвердження, що замовлення створено в магазині. Після цього портал може не повертати
його в `GET /orders`, поки воно не зміниться.

```json
{"external_order_id": "123", "reference": "XKBKNABJK"}
```

## POST /orders/{id}/status

Зміна статусу в магазині (за таблицею відповідності) та номер ТТН.

```json
{
  "status": "shipped",
  "external_order_id": "123",
  "reference": "XKBKNABJK",
  "tracking_number": "20450012345678",
  "carrier": "Нова Пошта",
  "changed_at": "2026-10-16T10:15:00+03:00"
}
```

Якщо портал недоступний, статус стає в чергу і надсилається задачею `statuses`.

---

## Вебхук (портал → магазин)

Необов'язковий, але дає миттєвий імпорт замовлень замість опитування.

- URL і секрет показані в налаштуваннях модуля («Для кабінету allmarket.biz»).
- `POST`, JSON, заголовок `X-Allmarket-Signature: sha256=<hex HMAC-SHA256 сирого тіла з секретом>`.

PHP на боці порталу:

```php
$body = json_encode($event, JSON_UNESCAPED_UNICODE);
$sig = 'sha256=' . hash_hmac('sha256', $body, $webhookSecret);
// POST $body на webhook URL з заголовком X-Allmarket-Signature: $sig
```

Події:

| event | тіло | відповідь |
|---|---|---|
| `ping` | `{"event":"ping"}` | `200 {"ok":true,"module":"1.0.1"}` |
| `order.created`, `order.updated` | `{"event":"order.created","order":{…як у GET /orders…}}` | `200 {"ok":true,"result":"imported\|skipped\|updated","external_order_id":"123","reference":"…"}` або `422 {"ok":false,"error":"…"}` |
| `order.status` | `{"event":"order.status","order_id":"AM-100523","status":"cancelled"}` | `200 {"ok":true,"changed":true}` або `404` |

Відповідь 200 на `order.created` замінює `/ack`. Повторна доставка того самого замовлення
безпечна — повертається `result: "skipped"`. Невірний підпис — `401`.

---

## XML-фід (режим «Фід»)

Модуль формує YML-сумісний фід (як для Prom/Rozetka) — портал може імпортувати його за посиланням:

- повний: `…&controller=feed&token=…&type=full` — категорії та повні картки;
- цін і наявності: `…&type=offers` — лише `id`, `available`, `price`, `oldprice`, `currencyId`, `quantity_in_stock`.

```xml
<yml_catalog date="2026-10-16 08:00">
 <shop>
  <name>…</name><url>…</url><platform>PrestaShop</platform><seller_id>777</seller_id>
  <currencies><currency id="UAH" rate="1"/></currencies>
  <categories><category id="4" parentId="3" allmarketId="101">Чоловічий</category></categories>
  <offers>
   <offer id="1-1" group_id="1" available="true">
    <price>19.12</price><oldprice>23.9</oldprice><currencyId>UAH</currencyId>
    <quantity_in_stock>300</quantity_in_stock>
    <url>…</url><categoryId>4</categoryId><allmarketCategoryId>101</allmarketCategoryId>
    <picture>…</picture><name>…</name><vendor>…</vendor><vendorCode>demo_1</vendorCode>
    <barcode>…</barcode><description><![CDATA[…]]></description><weight>0.3</weight>
    <condition>new</condition><param name="Розмір">S</param>
   </offer>
  </offers>
 </shop>
</yml_catalog>
```

Фід віддається з `Last-Modified` і підтримує `If-Modified-Since` (304). Файл перебудовується
cron-задачами `catalog`/`offers`/`feed`, а якщо застарів (повний — 24 год, цін — 1 год) — під час запиту.
