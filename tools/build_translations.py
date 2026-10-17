#!/usr/bin/env python3
"""Builds allmarketsync/translations/uk.php (legacy PrestaShop module translations).

Usage: python3 tools/build_translations.py [module_dir]   (default: allmarketsync/, also: ps16/allmarketsync)
Fails if a string in the module has no Ukrainian translation below.
"""
import glob
import hashlib
import os
import re
import sys

ROOT = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'allmarketsync')

UK = {
    'allmarket.biz exchange': 'Обмін з allmarket.biz',
    'Uploads the catalog to allmarket.biz, syncs prices, stock and orders.': 'Вивантажує каталог на allmarket.biz, синхронізує ціни, наявність і замовлення.',
    'All sync data (links to allmarket.biz items and orders) will be removed. Continue?': 'Усі дані синхронізації (зв\'язки з товарами й замовленнями allmarket.biz) буде видалено. Продовжити?',
    'Settings saved.': 'Налаштування збережено.',
    'Connection to allmarket.biz is OK.': 'З\'єднання з allmarket.biz працює.',
    'Task "%s" finished: %s': 'Задачу «%s» виконано: %s',
    'Hashes reset: the next sync will resend the whole catalog.': 'Стан скинуто: наступна синхронізація повторно надішле весь каталог.',
    'Imported %d of %d failed orders. See the log for details.': 'Імпортовано %d з %d невдалих замовлень. Подробиці — в журналі.',
    'Log cleared.': 'Журнал очищено.',
    'Tokens regenerated. Update the URLs in cron and on allmarket.biz.': 'Токени оновлено. Змініть URL у cron і в кабінеті allmarket.biz.',
    'API URL is not a valid absolute URL.': 'URL API має бути повною адресою.',
    'Unknown exchange mode.': 'Невідомий режим обміну.',
    'Markup must be a number.': 'Націнка має бути числом.',
    'Batch size must be between 1 and 1000.': 'Розмір пакета — від 1 до 1000.',
    'API: the module pushes data to allmarket.biz': 'API: модуль сам надсилає дані на allmarket.biz',
    'Feed: allmarket.biz downloads the XML feed by URL': 'Фід: allmarket.biz завантажує XML-фід за посиланням',
    'API + feed': 'API + фід',
    'Original image': 'Оригінал зображення',
    '— not set —': '— не вибрано —',
    '— do not send —': '— не передавати —',
    'Yes': 'Так',
    'No': 'Ні',
    'Connection to allmarket.biz': 'Підключення до allmarket.biz',
    'Exchange mode': 'Режим обміну',
    'API URL': 'URL API',
    'Base URL of the allmarket.biz seller API, e.g. https://allmarket.biz/api/v1': 'Базова адреса API продавця allmarket.biz, напр. https://allmarket.biz/api/v1',
    'Seller ID': 'ID продавця',
    'Your company/shop ID in the allmarket.biz cabinet.': 'ID вашої компанії/магазину в кабінеті allmarket.biz.',
    'API key': 'API-ключ',
    'A key is saved. Leave empty to keep it.': 'Ключ збережено. Залиште поле порожнім, щоб не змінювати.',
    'Generate the key in the allmarket.biz seller cabinet.': 'Згенеруйте ключ у кабінеті продавця allmarket.biz.',
    'Save': 'Зберегти',
    'Catalog export': 'Вивантаження каталогу',
    'Language': 'Мова',
    'Currency': 'Валюта',
    'Prices are converted to this currency.': 'Ціни перераховуються в цю валюту.',
    'Categories to export': 'Категорії для вивантаження',
    'Nothing selected = whole catalog. A product is exported if it belongs to any selected category.': 'Нічого не вибрано — весь каталог. Товар вивантажується, якщо він є хоча б в одній вибраній категорії.',
    'Export combinations as separate offers': 'Комбінації як окремі пропозиції',
    'Each combination becomes an offer with its own price and stock, grouped by group_id.': 'Кожна комбінація — окрема пропозиція з власною ціною й залишком, об\'єднані через group_id.',
    'Only items available for order': 'Лише доступні до замовлення',
    'Unavailable items are removed from allmarket.biz instead of being shown as "out of stock".': 'Недоступні товари знімаються з allmarket.biz, а не показуються як «немає в наявності».',
    'Image size': 'Розмір зображень',
    'Price markup, %': 'Націнка, %',
    'Negative value = discount. 0 = shop prices.': 'Від\'ємне значення — знижка. 0 — ціни магазину.',
    'Batch size': 'Розмір пакета',
    'Items per API request.': 'Позицій в одному запиті до API.',
    'Category mapping': 'Відповідність категорій',
    'One rule per line: shop_category_id=allmarket_category_id. Subcategories inherit the parent rule.': 'Одне правило в рядку: id_категорії_магазину=id_категорії_allmarket. Підкатегорії успадковують правило батьківської.',
    'Orders from allmarket.biz': 'Замовлення з allmarket.biz',
    'Import orders': 'Імпортувати замовлення',
    'Status of a new order': 'Статус нового замовлення',
    'Status of a prepaid order': 'Статус оплаченого замовлення',
    'Carrier': 'Перевізник',
    'Assigned to imported orders. It must serve the delivery country; a free carrier is recommended.': 'Призначається імпортованим замовленням. Має обслуговувати країну доставки; радимо безкоштовного перевізника.',
    'Delivery country': 'Країна доставки',
    'Used when the order has no country code.': 'Використовується, якщо в замовленні немає коду країни.',
    'Use prices from the allmarket.biz order': 'Ціни із замовлення allmarket.biz',
    'Otherwise current shop prices are applied.': 'Інакше застосовуються поточні ціни магазину.',
    'Use shipping cost from the allmarket.biz order': 'Вартість доставки із замовлення allmarket.biz',
    'Order status mapping (shop → allmarket.biz)': 'Відповідність статусів (магазин → allmarket.biz)',
    'When an imported order gets one of these statuses, allmarket.biz is notified. Statuses coming from allmarket.biz are applied by the same table in reverse.': 'Коли імпортоване замовлення отримує один із цих статусів, allmarket.biz отримує сповіщення. Статуси з allmarket.biz застосовуються за цією ж таблицею у зворотному напрямку.',
    'Exchange': 'Обмін',
    'Status': 'Стан',
    'Items on allmarket.biz': 'Позицій на allmarket.biz',
    'removed:': 'знято:',
    'not imported:': 'не імпортовано:',
    'Last run': 'Останній запуск',
    'error': 'помилка',
    'Run now': 'Запустити зараз',
    'Test connection': 'Перевірити з\'єднання',
    'Sync catalog': 'Синхронізувати каталог',
    'Sync prices & stock': 'Ціни й наявність',
    'Rebuild XML feeds': 'Перебудувати XML-фіди',
    'Force: resend everything and skip the delete safety check': 'Примусово: надіслати все й пропустити захист від масового видалення',
    'Retry failed orders': 'Повторити невдалі замовлення',
    'The whole catalog will be resent on the next sync. Continue?': 'Під час наступної синхронізації буде повторно надіслано весь каталог. Продовжити?',
    'Reset sync state': 'Скинути стан синхронізації',
    'Old cron, feed and webhook URLs will stop working. Continue?': 'Старі URL для cron, фідів і вебхука перестануть працювати. Продовжити?',
    'Regenerate tokens': 'Оновити токени',
    'Large catalogs are better synced by cron or CLI: the browser request may time out.': 'Великі каталоги краще синхронізувати через cron або CLI: запит із браузера може перевищити час очікування.',
    'Schedule (cron)': 'Розклад (cron)',
    'Recommended: prices & stock every 15 minutes, orders and statuses every 5 minutes, full catalog nightly.': 'Рекомендовано: ціни й наявність — кожні 15 хв, замовлення й статуси — кожні 5 хв, повний каталог — щоночі.',
    'Or by URL (wget/curl):': 'Або за URL (wget/curl):',
    'For the allmarket.biz cabinet': 'Для кабінету allmarket.biz',
    'Full XML feed': 'Повний XML-фід',
    'feed mode is off': 'режим фіду вимкнено',
    'Prices & stock XML feed': 'XML-фід цін і наявності',
    'Webhook URL': 'URL вебхука',
    'Webhook secret': 'Секрет вебхука',
    'Log': 'Журнал',
    'Clear': 'Очистити',
    'Date': 'Дата',
    'Task': 'Задача',
    'Message': 'Повідомлення',
    'No entries yet.': 'Записів поки немає.',
    'Order on allmarket.biz:': 'Замовлення на allmarket.biz:',
    'Status on allmarket.biz:': 'Статус на allmarket.biz:',
    'Waiting to be sent:': 'Очікує надсилання:',
    'Loaded %d categories from allmarket.biz.': 'Завантажено %d категорій з allmarket.biz.',
    'Category placement saved.': 'Розміщення категорій збережено.',
    'The portal applies it with the next catalog sync.': 'Портал застосує його під час наступної синхронізації каталогу.',
    'portal root': 'корінь порталу',
    'Category placement on allmarket.biz': 'Розміщення категорій на allmarket.biz',
    'In feed mode the portal reads the rules from the feed (allmarketId / allmarketParentId attributes of categories).': 'У режимі «Фід» портал бере правила з фіду (атрибути категорій allmarketId / allmarketParentId).',
    'The category tree of allmarket.biz is not available, so the rules can only be edited as text.': 'Дерево категорій allmarket.biz недоступне, тому правила можна змінити лише текстом.',
    'One rule per line: shop_category_id=portal_category_id (the shop category is that portal category) or shop_category_id>portal_category_id (it is created inside that portal category). Subcategories follow their parent.': 'Одне правило на рядок: id_категорії_магазину=id_категорії_порталу (категорія магазину — це та категорія порталу) або id_категорії_магазину>id_категорії_порталу (створюється всередині неї). Підкатегорії йдуть за батьківською.',
    'Load categories from allmarket.biz': 'Завантажити категорії з allmarket.biz',
    'Choose where each branch of your catalog goes on allmarket.biz. "Is the portal category" — products and subcategories go into the chosen portal category. "Create inside" — the shop category is created as a subcategory of the chosen one. Rows without a choice follow their parent; top-level categories without a choice are created at the portal root. Subcategories missing on the portal are created automatically.': 'Оберіть, куди на allmarket.biz потрапить кожна гілка вашого каталогу. «Відповідає категорії порталу» — товари й підкатегорії йдуть в обрану категорію порталу. «Створити всередині» — категорія магазину створюється як підкатегорія обраної. Рядки без вибору йдуть за батьківською категорією; категорії верхнього рівня без вибору створюються в корені порталу. Підкатегорії, яких немає на порталі, створюються автоматично.',
    'Filter shop categories': 'Пошук категорії магазину',
    'Shop category': 'Категорія магазину',
    'allmarket.biz category': 'Категорія allmarket.biz',
    'How': 'Як',
    'Result on allmarket.biz': 'Результат на allmarket.biz',
    'Is the portal category': 'Відповідає категорії порталу',
    'Create inside': 'Створити всередині',
    'Send the catalog to allmarket.biz right after saving': 'Одразу після збереження надіслати каталог на allmarket.biz',
    'Reload categories from allmarket.biz': 'Оновити категорії з allmarket.biz',
    '— follows the parent —': '— як у батьківської —',
    'not found on allmarket.biz': 'немає на allmarket.biz',
}


def collect():
    found = []
    php = open(os.path.join(ROOT, 'allmarketsync.php'), encoding='utf-8').read()
    for m in re.finditer(r"\$this->l\('((?:[^'\\]|\\.)*)'\)", php):
        found.append(('allmarketsync', m.group(1)))
    for tpl in sorted(glob.glob(os.path.join(ROOT, 'views', 'templates', '**', '*.tpl'), recursive=True)):
        src = open(tpl, encoding='utf-8').read()
        for m in re.finditer(r"\{l s='((?:[^'\\]|\\.)*)' mod='allmarketsync'", src):
            found.append((os.path.basename(tpl)[:-4], m.group(1)))
    unique = []
    for item in found:
        if item not in unique:
            unique.append(item)
    return unique


def php_escape(s):
    return s.replace('\\', '\\\\').replace("'", "\\'")


def main():
    missing = sorted({s for _, s in collect() if s not in UK})
    if missing:
        print('Missing translations:\n  ' + '\n  '.join(missing))
        sys.exit(1)
    lines = ['<?php', '', 'global $_MODULE;', '$_MODULE = [];']
    for source, s in collect():
        key = '<{allmarketsync}prestashop>%s_%s' % (source, hashlib.md5(s.encode('utf-8')).hexdigest())
        lines.append("$_MODULE['%s'] = '%s';" % (key, php_escape(UK[s])))
    path = os.path.join(ROOT, 'translations', 'uk.php')
    open(path, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
    print('Wrote %d strings to %s' % (len(lines) - 4, os.path.relpath(path)))


if __name__ == '__main__':
    main()
