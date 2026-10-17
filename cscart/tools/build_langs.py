#!/usr/bin/env python3
"""Builds var/langs/<lang>/addons/allmarket_sync.po (uk, ru, en, pl) from one table.

Usage: python3 tools/build_langs.py   (run from the cscart/ directory or anywhere)
"""
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANGS = ['en', 'uk', 'ru', 'pl']
# PO header meta CS-Cart validates: Language-Team (pack name) and Language (lang_COUNTRY).
META = {'en': ('English', 'en_US'), 'uk': ('Ukrainian', 'uk_UA'), 'ru': ('Russian', 'ru_RU'), 'pl': ('Polish', 'pl_PL')}
ADDON = 'allmarket_sync'

# (msgctxt, en, uk, ru, pl)
ROWS = [
    ('Addons::name::allmarket_sync', 'allmarket.biz Sync', 'Обмін з allmarket.biz', 'Обмен с allmarket.biz', 'Synchronizacja z allmarket.biz'),
    ('Addons::description::allmarket_sync',
     'Exchange with the allmarket.biz marketplace: catalog with categories and variations, prices and stock, orders and statuses (Seller API or XML feed).',
     'Обмін з маркетплейсом allmarket.biz: каталог з категоріями та варіаціями, ціни й залишки, замовлення та статуси (Seller API або XML-фід).',
     'Обмен с маркетплейсом allmarket.biz: каталог с категориями и вариациями, цены и остатки, заказы и статусы (Seller API или XML-фид).',
     'Wymiana z marketplace allmarket.biz: katalog z kategoriami i wariantami, ceny i stany, zamówienia i statusy (Seller API lub feed XML).'),

    # --- menu / titles ---
    ('Languages::allmarket_sync', 'allmarket.biz', 'allmarket.biz', 'allmarket.biz', 'allmarket.biz'),
    ('Languages::allmarket_sync_menu_description', 'Exchange of the catalog and orders with the allmarket.biz marketplace',
     'Обмін каталогом і замовленнями з маркетплейсом allmarket.biz', 'Обмен каталогом и заказами с маркетплейсом allmarket.biz',
     'Wymiana katalogu i zamówień z marketplace allmarket.biz'),
    ('Languages::allmarket_sync_manage', 'Exchange', 'Обмін', 'Обмен', 'Wymiana'),
    ('Languages::allmarket_sync_manage_menu_description', 'Connection, tasks, orders and log', 'Підключення, завдання, замовлення та журнал',
     'Подключение, задачи, заказы и журнал', 'Połączenie, zadania, zamówienia i dziennik'),
    ('Languages::allmarket_sync_categories', 'Category placement', 'Розміщення категорій', 'Размещение категорий', 'Rozmieszczenie kategorii'),
    ('Languages::allmarket_sync_categories_menu_description', 'Where shop categories go on allmarket.biz',
     'Куди потрапляють категорії магазину на allmarket.biz', 'Куда попадают категории магазина на allmarket.biz',
     'Gdzie trafiają kategorie sklepu na allmarket.biz'),

    # --- notifications ---
    ('Languages::allmarket_sync.task_done', 'Task [task] finished: [stats]', 'Завдання [task] виконано: [stats]', 'Задача [task] выполнена: [stats]', 'Zadanie [task] zakończone: [stats]'),
    ('Languages::allmarket_sync.ping_ok', 'Connected: [name] (ID [id]), seller currency on the portal: [currency]',
     'З\'єднання встановлено: [name] (ID [id]), валюта продавця на порталі: [currency]',
     'Соединение установлено: [name] (ID [id]), валюта продавца на портале: [currency]',
     'Połączono: [name] (ID [id]), waluta sprzedawcy na portalu: [currency]'),
    ('Languages::allmarket_sync.currency_mismatch',
     'Your seller currency on allmarket.biz is [portal], while the add-on exports prices in [shop]. The portal converts them at its rate; set the same currency to avoid differences.',
     'Валюта продавця на allmarket.biz — [portal], а модуль вивантажує ціни в [shop]. Портал перерахує їх за своїм курсом; щоб уникнути розбіжностей, встановіть однакову валюту.',
     'Валюта продавца на allmarket.biz — [portal], а модуль выгружает цены в [shop]. Портал пересчитает их по своему курсу; чтобы избежать расхождений, установите одинаковую валюту.',
     'Waluta sprzedawcy na allmarket.biz to [portal], a moduł eksportuje ceny w [shop]. Portal przeliczy je po swoim kursie; ustaw tę samą walutę, aby uniknąć różnic.'),
    ('Languages::allmarket_sync.secret_reset', 'The key has been changed. Update it in the allmarket.biz cabinet and in your cron.',
     'Ключ змінено. Оновіть його в кабінеті allmarket.biz і в cron.', 'Ключ изменён. Обновите его в кабинете allmarket.biz и в cron.',
     'Klucz został zmieniony. Zaktualizuj go w panelu allmarket.biz i w cronie.'),
    ('Languages::allmarket_sync.placement_saved', 'Placement rules saved: [count]', 'Збережено правил розміщення: [count]', 'Сохранено правил размещения: [count]', 'Zapisano reguł rozmieszczenia: [count]'),
    ('Languages::allmarket_sync.portal_cats_loaded', 'allmarket.biz categories loaded: [count]', 'Завантажено категорій allmarket.biz: [count]', 'Загружено категорий allmarket.biz: [count]', 'Wczytano kategorii allmarket.biz: [count]'),

    # --- overview ---
    ('Languages::allmarket_sync.connection', 'Connection', 'Підключення', 'Подключение', 'Połączenie'),
    ('Languages::allmarket_sync.status', 'Status', 'Стан', 'Состояние', 'Stan'),
    ('Languages::allmarket_sync.configured', 'API key is set', 'API-ключ вказано', 'API-ключ указан', 'Klucz API ustawiony'),
    ('Languages::allmarket_sync.not_configured', 'API URL or key is not set', 'Не вказано адресу API або ключ', 'Не указан адрес API или ключ', 'Nie ustawiono adresu API lub klucza'),
    ('Languages::allmarket_sync.mode', 'Mode', 'Режим', 'Режим', 'Tryb'),
    ('Languages::allmarket_sync.mode_api', 'API', 'API', 'API', 'API'),
    ('Languages::allmarket_sync.mode_feed', 'XML feed', 'XML-фід', 'XML-фид', 'Feed XML'),
    ('Languages::allmarket_sync.mode_both', 'API + XML feed', 'API + XML-фід', 'API + XML-фид', 'API + feed XML'),
    ('Languages::allmarket_sync.check_connection', 'Check connection', 'Перевірити з\'єднання', 'Проверить соединение', 'Sprawdź połączenie'),
    ('Languages::allmarket_sync.tasks', 'Tasks', 'Завдання', 'Задачи', 'Zadania'),
    ('Languages::allmarket_sync.task', 'Task', 'Завдання', 'Задача', 'Zadanie'),
    ('Languages::allmarket_sync.last_run', 'Last run', 'Останній запуск', 'Последний запуск', 'Ostatnie uruchomienie'),
    ('Languages::allmarket_sync.result', 'Result', 'Результат', 'Результат', 'Wynik'),
    ('Languages::allmarket_sync.run', 'Run', 'Запустити', 'Запустить', 'Uruchom'),
    ('Languages::allmarket_sync.force', 'Force (resend everything, delete without the safety check)', 'Примусово (надіслати все, видаляти без перевірки)',
     'Принудительно (отправить всё, удалять без проверки)', 'Wymuś (wyślij wszystko, usuwaj bez kontroli)'),
    ('Languages::allmarket_sync.force_help',
     'Without it only changes are sent, and if more than half of the products disappear from the export at once they are not deleted on the portal.',
     'Без нього надсилаються лише зміни, а якщо з вивантаження зникає понад половину товарів — на порталі вони не видаляються.',
     'Без него отправляются только изменения, а если из выгрузки пропадает больше половины товаров — на портале они не удаляются.',
     'Bez tego wysyłane są tylko zmiany, a jeśli z eksportu zniknie ponad połowa produktów, nie są one usuwane z portalu.'),
    ('Languages::allmarket_sync.items_on_portal', 'Products known to the portal', 'Товари, відомі порталу', 'Товары, известные порталу', 'Produkty znane portalowi'),
    ('Languages::allmarket_sync.task_catalog', 'Catalog', 'Каталог', 'Каталог', 'Katalog'),
    ('Languages::allmarket_sync.task_offers', 'Prices and stock', 'Ціни та залишки', 'Цены и остатки', 'Ceny i stany'),
    ('Languages::allmarket_sync.task_orders', 'Orders', 'Замовлення', 'Заказы', 'Zamówienia'),
    ('Languages::allmarket_sync.task_statuses', 'Status queue', 'Черга статусів', 'Очередь статусов', 'Kolejka statusów'),
    ('Languages::allmarket_sync.task_feed', 'XML feed', 'XML-фід', 'XML-фид', 'Feed XML'),
    ('Languages::allmarket_sync.for_cabinet', 'For the allmarket.biz cabinet and cron', 'Для кабінету allmarket.biz і cron', 'Для кабинета allmarket.biz и cron', 'Dla panelu allmarket.biz i crona'),
    ('Languages::allmarket_sync.webhook_url', 'Webhook URL', 'URL вебхука', 'URL вебхука', 'URL webhooka'),
    ('Languages::allmarket_sync.webhook_secret', 'Webhook secret', 'Секрет вебхука', 'Секрет вебхука', 'Sekret webhooka'),
    ('Languages::allmarket_sync.reset', 'Change', 'Змінити', 'Изменить', 'Zmień'),
    ('Languages::allmarket_sync.feed_full', 'Full feed', 'Повний фід', 'Полный фид', 'Pełny feed'),
    ('Languages::allmarket_sync.feed_offers', 'Price & stock feed', 'Фід цін і залишків', 'Фид цен и остатков', 'Feed cen i stanów'),
    ('Languages::allmarket_sync.cron', 'Cron', 'Cron', 'Cron', 'Cron'),
    ('Languages::allmarket_sync.cron_help', 'Server cron (recommended): prices, stock, orders every 15 minutes, full catalog nightly.',
     'Cron на сервері (рекомендовано): ціни, залишки та замовлення кожні 15 хвилин, повний каталог щоночі.',
     'Cron на сервере (рекомендуется): цены, остатки и заказы каждые 15 минут, полный каталог еженощно.',
     'Cron na serwerze (zalecane): ceny, stany i zamówienia co 15 minut, pełny katalog co noc.'),
    ('Languages::allmarket_sync.cron_web_help', 'Or call these URLs from an external cron service:', 'Або викликайте ці адреси зовнішнім cron-сервісом:',
     'Или вызывайте эти адреса внешним cron-сервисом:', 'Lub wywołuj te adresy zewnętrznym serwisem cron:'),
    ('Languages::allmarket_sync.status_map', 'Order statuses', 'Статуси замовлень', 'Статусы заказов', 'Statusy zamówień'),
    ('Languages::allmarket_sync.status_map_help',
     'When an allmarket.biz order gets a status from this table, the add-on sends the matching portal status (and the tracking number of the latest shipment). Statuses received from the portal are applied in reverse (the first matching row).',
     'Коли замовлення з allmarket.biz отримує статус із цієї таблиці, модуль надсилає на портал відповідний статус (і номер ТТН з останнього відвантаження). Статуси з порталу застосовуються у зворотному напрямку (перший відповідний рядок).',
     'Когда заказ с allmarket.biz получает статус из этой таблицы, модуль отправляет на портал соответствующий статус (и номер ТТН из последней отгрузки). Статусы с портала применяются в обратном направлении (первая подходящая строка).',
     'Gdy zamówienie z allmarket.biz otrzyma status z tej tabeli, moduł wysyła na portal odpowiedni status (i numer przesyłki z ostatniej wysyłki). Statusy z portalu są stosowane odwrotnie (pierwszy pasujący wiersz).'),
    ('Languages::allmarket_sync.cscart_status', 'Store status', 'Статус магазину', 'Статус магазина', 'Status sklepu'),
    ('Languages::allmarket_sync.am_status', 'allmarket.biz status', 'Статус allmarket.biz', 'Статус allmarket.biz', 'Status allmarket.biz'),
    ('Languages::allmarket_sync.not_sent', 'not sent', 'не надсилати', 'не отправлять', 'nie wysyłaj'),
    ('Languages::allmarket_sync.am_status_new', 'New', 'Нове', 'Новый', 'Nowe'),
    ('Languages::allmarket_sync.am_status_accepted', 'Accepted', 'Прийняте', 'Принят', 'Przyjęte'),
    ('Languages::allmarket_sync.am_status_processing', 'Processing', 'Обробляється', 'В обработке', 'W realizacji'),
    ('Languages::allmarket_sync.am_status_shipped', 'Shipped', 'Відправлене', 'Отправлен', 'Wysłane'),
    ('Languages::allmarket_sync.am_status_delivered', 'Delivered', 'Доставлене', 'Доставлен', 'Dostarczone'),
    ('Languages::allmarket_sync.am_status_cancelled', 'Cancelled', 'Скасоване', 'Отменён', 'Anulowane'),
    ('Languages::allmarket_sync.am_status_returned', 'Returned', 'Повернення', 'Возврат', 'Zwrot'),
    ('Languages::allmarket_sync.orders', 'allmarket.biz orders', 'Замовлення allmarket.biz', 'Заказы allmarket.biz', 'Zamówienia allmarket.biz'),
    ('Languages::allmarket_sync.sent', 'Sent to the portal', 'Надіслано на портал', 'Отправлено на портал', 'Wysłano na portal'),
    ('Languages::allmarket_sync.queued', 'queued', 'у черзі', 'в очереди', 'w kolejce'),
    ('Languages::allmarket_sync.retry', 'Retry', 'Повторити', 'Повторить', 'Ponów'),
    ('Languages::allmarket_sync.no_ack', 'not confirmed to the portal', 'не підтверджено порталу', 'не подтверждено порталу', 'niepotwierdzone portalowi'),
    ('Languages::allmarket_sync.log', 'Exchange log', 'Журнал обміну', 'Журнал обмена', 'Dziennik wymiany'),
    ('Languages::allmarket_sync.clear_log', 'Clear log', 'Очистити журнал', 'Очистить журнал', 'Wyczyść dziennik'),

    # --- category placement ---
    ('Languages::allmarket_sync.placement_feed_mode', 'In feed mode the portal reads the rules from the feed (allmarketId / allmarketParentId attributes of categories).',
     'У режимі фіду портал читає правила з фіду (атрибути allmarketId / allmarketParentId категорій).',
     'В режиме фида портал читает правила из фида (атрибуты allmarketId / allmarketParentId категорий).',
     'W trybie feedu portal odczytuje reguły z feedu (atrybuty allmarketId / allmarketParentId kategorii).'),
    ('Languages::allmarket_sync.portal_tree_unavailable', 'The category tree of allmarket.biz is not available, so the rules can only be edited as text.',
     'Дерево категорій allmarket.biz недоступне, тому правила можна редагувати лише текстом.',
     'Дерево категорий allmarket.biz недоступно, поэтому правила можно редактировать только текстом.',
     'Drzewo kategorii allmarket.biz jest niedostępne, więc reguły można edytować tylko jako tekst.'),
    ('Languages::allmarket_sync.placement_rules_help',
     'One rule per line: shop_category_id=portal_category_id (the shop category is that portal category) or shop_category_id>portal_category_id (it is created inside that portal category). Subcategories follow their parent.',
     'Одне правило в рядку: id_категорії_магазину=id_категорії_порталу (категорія магазину і є цією категорією порталу) або id_категорії_магазину>id_категорії_порталу (створюється всередині цієї категорії порталу). Підкатегорії йдуть за батьківською.',
     'Одно правило в строке: id_категории_магазина=id_категории_портала (категория магазина и есть эта категория портала) или id_категории_магазина>id_категории_портала (создаётся внутри этой категории портала). Подкатегории следуют за родительской.',
     'Jedna reguła w wierszu: id_kategorii_sklepu=id_kategorii_portalu (kategoria sklepu to ta kategoria portalu) lub id_kategorii_sklepu>id_kategorii_portalu (tworzona wewnątrz tej kategorii portalu). Podkategorie podążają za nadrzędną.'),
    ('Languages::allmarket_sync.load_portal_cats', 'Load categories from allmarket.biz', 'Завантажити категорії з allmarket.biz', 'Загрузить категории с allmarket.biz', 'Wczytaj kategorie z allmarket.biz'),
    ('Languages::allmarket_sync.reload_portal_cats', 'Reload categories from allmarket.biz', 'Оновити категорії з allmarket.biz', 'Обновить категории с allmarket.biz', 'Odśwież kategorie z allmarket.biz'),
    ('Languages::allmarket_sync.placement_help',
     'Choose where each branch of your catalog goes on allmarket.biz. "Is the portal category" — products and subcategories go into the chosen portal category. "Create inside" — the shop category is created as a subcategory of the chosen one. Rows without a choice follow their parent; top-level categories without a choice are created at the portal root. Subcategories missing on the portal are created automatically.',
     'Оберіть, куди на allmarket.biz потрапляє кожна гілка каталогу. «Відповідає категорії порталу» — товари й підкатегорії йдуть у вибрану категорію порталу. «Створити всередині» — категорія магазину створюється підкатегорією вибраної. Рядки без вибору йдуть за батьківською; категорії верхнього рівня без вибору створюються в корені порталу. Відсутні на порталі підкатегорії створюються автоматично.',
     'Выберите, куда на allmarket.biz попадает каждая ветка каталога. «Соответствует категории портала» — товары и подкатегории идут в выбранную категорию портала. «Создать внутри» — категория магазина создаётся подкатегорией выбранной. Строки без выбора следуют за родительской; категории верхнего уровня без выбора создаются в корне портала. Отсутствующие на портале подкатегории создаются автоматически.',
     'Wybierz, gdzie na allmarket.biz trafia każda gałąź katalogu. „Odpowiada kategorii portalu” — produkty i podkategorie trafiają do wybranej kategorii portalu. „Utwórz wewnątrz” — kategoria sklepu jest tworzona jako podkategoria wybranej. Wiersze bez wyboru podążają za nadrzędną; kategorie najwyższego poziomu bez wyboru są tworzone w katalogu głównym portalu. Brakujące na portalu podkategorie są tworzone automatycznie.'),
    ('Languages::allmarket_sync.filter_categories', 'Filter shop categories', 'Фільтр категорій магазину', 'Фильтр категорий магазина', 'Filtruj kategorie sklepu'),
    ('Languages::allmarket_sync.shop_category', 'Shop category', 'Категорія магазину', 'Категория магазина', 'Kategoria sklepu'),
    ('Languages::allmarket_sync.portal_category', 'allmarket.biz category', 'Категорія allmarket.biz', 'Категория allmarket.biz', 'Kategoria allmarket.biz'),
    ('Languages::allmarket_sync.how', 'How', 'Як', 'Как', 'Jak'),
    ('Languages::allmarket_sync.result_on_portal', 'Result on allmarket.biz', 'Результат на allmarket.biz', 'Результат на allmarket.biz', 'Wynik na allmarket.biz'),
    ('Languages::allmarket_sync.mode_map', 'Is the portal category', 'Відповідає категорії порталу', 'Соответствует категории портала', 'Odpowiada kategorii portalu'),
    ('Languages::allmarket_sync.mode_inside', 'Create inside', 'Створити всередині', 'Создать внутри', 'Utwórz wewnątrz'),
    ('Languages::allmarket_sync.send_now', 'Send the catalog to allmarket.biz right after saving', 'Надіслати каталог на allmarket.biz одразу після збереження',
     'Отправить каталог на allmarket.biz сразу после сохранения', 'Wyślij katalog na allmarket.biz zaraz po zapisaniu'),
    ('Languages::allmarket_sync.follows_parent', '— follows the parent —', '— як у батьківської —', '— как у родительской —', '— jak nadrzędna —'),
    ('Languages::allmarket_sync.not_found_on_portal', 'not found on allmarket.biz', 'не знайдено на allmarket.biz', 'не найдено на allmarket.biz', 'nie znaleziono na allmarket.biz'),
    ('Languages::allmarket_sync.settings_info', 'Tasks, URLs for the allmarket.biz cabinet, status mapping, orders and the log are on the add-on page.',
     'Завдання, адреси для кабінету allmarket.biz, відповідність статусів, замовлення та журнал — на сторінці модуля.',
     'Задачи, адреса для кабинета allmarket.biz, соответствие статусов, заказы и журнал — на странице модуля.',
     'Zadania, adresy dla panelu allmarket.biz, mapowanie statusów, zamówienia i dziennik są na stronie modułu.'),

    # --- settings ---
    ('SettingsSections::allmarket_sync::connection', 'Connection', 'Підключення', 'Подключение', 'Połączenie'),
    ('SettingsSections::allmarket_sync::catalog', 'Catalog', 'Каталог', 'Каталог', 'Katalog'),
    ('SettingsSections::allmarket_sync::orders', 'Orders', 'Замовлення', 'Заказы', 'Zamówienia'),
    ('SettingsOptions::allmarket_sync::mode', 'Exchange mode', 'Режим обміну', 'Режим обмена', 'Tryb wymiany'),
    ('SettingsVariants::allmarket_sync::mode::api', 'API (recommended)', 'API (рекомендовано)', 'API (рекомендуется)', 'API (zalecane)'),
    ('SettingsVariants::allmarket_sync::mode::feed', 'XML feed only', 'Лише XML-фід', 'Только XML-фид', 'Tylko feed XML'),
    ('SettingsVariants::allmarket_sync::mode::both', 'API + XML feed', 'API + XML-фід', 'API + XML-фид', 'API + feed XML'),
    ('SettingsOptions::allmarket_sync::api_url', 'API URL', 'Адреса API', 'Адрес API', 'Adres API'),
    ('SettingsOptions::allmarket_sync::api_key', 'API key', 'API-ключ', 'API-ключ', 'Klucz API'),
    ('SettingsTooltips::allmarket_sync::api_key', 'allmarket.biz cabinet → Integrations → Online store → create a key.',
     'Кабінет allmarket.biz → Інтеграції → Інтернет-магазин → створити ключ.', 'Кабинет allmarket.biz → Интеграции → Интернет-магазин → создать ключ.',
     'Panel allmarket.biz → Integracje → Sklep internetowy → utwórz klucz.'),
    ('SettingsOptions::allmarket_sync::shop_id', 'Seller ID', 'ID продавця', 'ID продавца', 'ID sprzedawcy'),
    ('SettingsTooltips::allmarket_sync::shop_id', 'Shown next to the API key in the allmarket.biz cabinet.', 'Показано поруч з API-ключем у кабінеті allmarket.biz.',
     'Показан рядом с API-ключом в кабинете allmarket.biz.', 'Widoczny obok klucza API w panelu allmarket.biz.'),
    ('SettingsOptions::allmarket_sync::info', 'Exchange page', 'Сторінка обміну', 'Страница обмена', 'Strona wymiany'),
    ('SettingsOptions::allmarket_sync::lang_code', 'Language of names and descriptions', 'Мова назв і описів', 'Язык названий и описаний', 'Język nazw i opisów'),
    ('SettingsOptions::allmarket_sync::currency', 'Price currency', 'Валюта цін', 'Валюта цен', 'Waluta cen'),
    ('SettingsTooltips::allmarket_sync::currency', 'Prices are converted from the primary currency at the store rate. Use the same currency as in the allmarket.biz cabinet.',
     'Ціни перераховуються з основної валюти за курсом магазину. Використовуйте ту саму валюту, що й у кабінеті allmarket.biz.',
     'Цены пересчитываются из основной валюты по курсу магазина. Используйте ту же валюту, что и в кабинете allmarket.biz.',
     'Ceny są przeliczane z waluty głównej po kursie sklepu. Użyj tej samej waluty co w panelu allmarket.biz.'),
    ('SettingsOptions::allmarket_sync::categories', 'Export only categories (IDs)', 'Вивантажувати лише категорії (ID)', 'Выгружать только категории (ID)', 'Eksportuj tylko kategorie (ID)'),
    ('SettingsTooltips::allmarket_sync::categories', 'Comma-separated category IDs, subcategories included. Empty — the whole catalog.',
     'ID категорій через кому, разом з підкатегоріями. Порожньо — весь каталог.', 'ID категорий через запятую, вместе с подкатегориями. Пусто — весь каталог.',
     'ID kategorii po przecinku, łącznie z podkategoriami. Puste — cały katalog.'),
    ('SettingsOptions::allmarket_sync::variations', 'Export variations as separate offers', 'Вивантажувати варіації окремими пропозиціями',
     'Выгружать вариации отдельными предложениями', 'Eksportuj warianty jako osobne oferty'),
    ('SettingsTooltips::allmarket_sync::variations', 'Variations of one product share group_id and carry their variation features. Off — only parent products.',
     'Варіації одного товару мають спільний group_id і свої характеристики варіації. Вимкнено — лише батьківські товари.',
     'Вариации одного товара имеют общий group_id и свои характеристики вариации. Выключено — только родительские товары.',
     'Warianty jednego produktu mają wspólny group_id i własne cechy wariantu. Wyłączone — tylko produkty nadrzędne.'),
    ('SettingsOptions::allmarket_sync::only_in_stock', 'Only products available for order', 'Лише товари, доступні для замовлення',
     'Только товары, доступные для заказа', 'Tylko produkty dostępne do zamówienia'),
    ('SettingsOptions::allmarket_sync::price_markup', 'Price markup, %', 'Націнка на ціни, %', 'Наценка на цены, %', 'Narzut na ceny, %'),
    ('SettingsTooltips::allmarket_sync::price_markup', 'Applied to the price and the old price. Negative value — a discount.',
     'Застосовується до ціни й старої ціни. Від\'ємне значення — знижка.', 'Применяется к цене и старой цене. Отрицательное значение — скидка.',
     'Stosowany do ceny i starej ceny. Wartość ujemna — rabat.'),
    ('SettingsOptions::allmarket_sync::batch_size', 'Products per request', 'Товарів у запиті', 'Товаров в запросе', 'Produktów w żądaniu'),
    ('SettingsOptions::allmarket_sync::orders_enabled', 'Import orders', 'Імпортувати замовлення', 'Импортировать заказы', 'Importuj zamówienia'),
    ('SettingsOptions::allmarket_sync::status_new', 'Status of a new order', 'Статус нового замовлення', 'Статус нового заказа', 'Status nowego zamówienia'),
    ('SettingsOptions::allmarket_sync::status_paid', 'Status of a paid order', 'Статус оплаченого замовлення', 'Статус оплаченного заказа', 'Status opłaconego zamówienia'),
    ('SettingsOptions::allmarket_sync::shipping_id', 'Shipping method', 'Спосіб доставки', 'Способ доставки', 'Metoda dostawy'),
    ('SettingsTooltips::allmarket_sync::shipping_id', 'The delivery details from allmarket.biz (city, warehouse) go to the order address and notes.',
     'Дані доставки з allmarket.biz (місто, відділення) записуються в адресу та коментар замовлення.',
     'Данные доставки с allmarket.biz (город, отделение) записываются в адрес и комментарий заказа.',
     'Dane dostawy z allmarket.biz (miasto, oddział) trafiają do adresu i uwag zamówienia.'),
    ('SettingsOptions::allmarket_sync::payment_id', 'Payment method', 'Спосіб оплати', 'Способ оплаты', 'Metoda płatności'),
    ('SettingsOptions::allmarket_sync::default_country', 'Default country (code)', 'Країна за замовчуванням (код)', 'Страна по умолчанию (код)', 'Kraj domyślny (kod)'),
    ('SettingsOptions::allmarket_sync::use_am_prices', 'Prices from the allmarket.biz order', 'Ціни із замовлення allmarket.biz', 'Цены из заказа allmarket.biz', 'Ceny z zamówienia allmarket.biz'),
    ('SettingsTooltips::allmarket_sync::use_am_prices', 'Off — the order uses the current store prices.', 'Вимкнено — у замовленні поточні ціни магазину.',
     'Выключено — в заказе текущие цены магазина.', 'Wyłączone — zamówienie używa bieżących cen sklepu.'),
    ('SettingsOptions::allmarket_sync::use_am_shipping', 'Shipping cost from the allmarket.biz order', 'Вартість доставки із замовлення allmarket.biz',
     'Стоимость доставки из заказа allmarket.biz', 'Koszt dostawy z zamówienia allmarket.biz'),
    ('SettingsOptions::allmarket_sync::notify_customer', 'Notify the customer by e-mail', 'Сповіщати покупця e-mail', 'Уведомлять покупателя по e-mail', 'Powiadamiaj klienta e-mailem'),
    ('SettingsOptions::allmarket_sync::notify_admin', 'Notify the order department', 'Сповіщати відділ замовлень', 'Уведомлять отдел заказов', 'Powiadamiaj dział zamówień'),
]


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"')


def main():
    seen = set()
    for row in ROWS:
        assert len(row) == 5, row[0]
        assert row[0] not in seen, row[0]
        seen.add(row[0])
    for i, lang in enumerate(LANGS):
        lines = [
            'msgid ""',
            'msgstr ""',
            '"Project-Id-Version: tygh\\n"',
            '"Content-Type: text/plain; charset=UTF-8\\n"',
            '"Language-Team: ' + META[lang][0] + '\\n"',
            '"Language: ' + META[lang][1] + '\\n"',
            '',
        ]
        for ctx, *texts in ROWS:
            lines += ['msgctxt "%s"' % ctx, 'msgid "%s"' % esc(texts[0]), 'msgstr "%s"' % esc(texts[i]), '']
        path = os.path.join(ROOT, 'var', 'langs', lang, 'addons', ADDON + '.po')
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8') as f:
            f.write('\n'.join(lines))
        print(path, len(ROWS))


if __name__ == '__main__':
    main()
