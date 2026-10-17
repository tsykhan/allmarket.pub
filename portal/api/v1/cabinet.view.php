<?php
/**
 * Body of the "API для інтернет-магазину" page; expects $AM from amapi_cabinet().
 * Markup follows the cabinet's «Інтеграції» pages (int-card / int-sec / section, template b2b-b2c).
 */
if (!isset($AM) || !is_array($AM)) {
    return;
}
$s = $AM['seller'];
$codes = array(200 => 'ok', 401 => 'bad', 403 => 'bad', 404 => 'warn', 413 => 'warn', 500 => 'bad');
?>
<style>
.amapi .amapi-key{font-family:Menlo,Consolas,monospace;font-size:15px;word-break:break-all;padding:10px 12px;margin:8px 0;border:1px dashed var(--c-accent,#2ea043);border-radius:10px;background:var(--c-soft,rgba(46,160,67,.08))}
.amapi .amapi-kv{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:8px 16px;align-items:center;margin:0}
.amapi .amapi-kv dt{font-weight:500;color:var(--c-text-2,inherit)}
.amapi .amapi-kv dd{margin:0;word-break:break-all}
.amapi code{font-size:13px}
.amapi .amapi-tbl{width:100%;border-collapse:collapse;font-size:13px}
.amapi .amapi-tbl th,.amapi .amapi-tbl td{padding:6px 8px;border-bottom:1px solid var(--c-line,rgba(128,128,128,.25));text-align:left;vertical-align:top}
.amapi .amapi-code{display:inline-block;min-width:34px;padding:1px 6px;border-radius:6px;font-weight:600;text-align:center;color:#fff;background:#888}
.amapi .amapi-code--ok{background:#2e7d32}.amapi .amapi-code--warn{background:#b26a00}.amapi .amapi-code--bad{background:#b3261e}
.amapi .amapi-msg{margin:0 0 14px;padding:10px 14px;border-radius:10px}
.amapi .amapi-msg--ok{background:rgba(46,125,50,.12);color:var(--c-text,inherit)}.amapi .amapi-msg--bad{background:rgba(179,38,30,.12);color:var(--c-text,inherit)}
.amapi .amapi-cats{max-height:300px;overflow:auto}
@media (max-width:600px){.amapi .amapi-kv{grid-template-columns:1fr}}
</style>
<div class="amapi">
<?php if ($AM['notice'] !== '') { ?><div class="amapi-msg amapi-msg--ok"><?php echo amapi_h($AM['notice']); ?></div><?php } ?>
<?php if ($AM['error'] !== '') { ?><div class="amapi-msg amapi-msg--bad"><?php echo amapi_h($AM['error']); ?></div><?php } ?>
<?php if ($AM['denied']) { ?><div class="amapi-msg amapi-msg--bad">Ваша група користувачів не має доступу до API.</div><?php } ?>

<div class="int-card int-card--form">
  <div class="int-sec">
    <p style="margin:0;line-height:1.6">Синхронізація каталогу, цін, залишків і замовлень з вашим інтернет-магазином: модуль <b>allmarketsync</b> для PrestaShop
      або інша платформа через Seller API. Внесіть ці дані в налаштування модуля (розділ «Підключення», режим <b>API</b>).</p>
  </div>
  <div class="int-sec">
    <div class="int-sec__t">Дані для підключення</div>
    <dl class="amapi-kv">
      <dt>URL API</dt><dd><code><?php echo amapi_h($AM['api_url']); ?></code></dd>
      <dt>ID продавця</dt><dd><code><?php echo amapi_h($AM['shop_id']); ?></code></dd>
      <dt>API-ключ</dt>
      <dd>
        <?php if ($AM['new_key'] !== '') { ?>
          <div class="amapi-key" id="amapi-key"><?php echo amapi_h($AM['new_key']); ?></div>
          <button type="button" class="btn btn-default btn-sm" onclick="var r=document.createRange();r.selectNodeContents(document.getElementById('amapi-key'));var s=window.getSelection();s.removeAllRanges();s.addRange(r);try{document.execCommand('copy')}catch(e){}">Копіювати</button>
        <?php } elseif ($s && $s['key_hash'] !== '') { ?>
          <code><?php echo amapi_h($s['key_hint']); ?></code>
          <span class="help-block" style="display:inline;margin:0 0 0 6px">створено <?php echo date('d.m.Y H:i', (int) $s['key_created']); ?><?php if ($s['last_used']) { ?>, останнє звернення <?php echo date('d.m.Y H:i', (int) $s['last_used']); ?><?php } ?></span>
        <?php } else { ?>
          <span class="help-block" style="display:inline;margin:0">ще не створено</span>
        <?php } ?>
      </dd>
    </dl>
    <?php if (!$AM['denied']) { ?>
    <form method="post" style="margin-top:12px" onsubmit="return <?php echo $s ? "confirm('Створити новий ключ? Старий одразу перестане діяти — його треба буде замінити в модулі магазину.')" : 'true'; ?>;">
      <input type="hidden" name="amapi_token" value="<?php echo amapi_h($AM['token']); ?>">
      <input type="hidden" name="amapi_action" value="key">
      <button type="submit" class="btn btn-primary"><?php echo $s ? 'Створити новий ключ' : 'Створити API-ключ'; ?></button>
    </form>
    <?php } ?>
    <?php if ($s) { ?>
    <div class="help-block">Ключ показується повністю лише один раз. Замовлення, оформлені до <?php echo date('d.m.Y H:i', (int) $s['orders_from']); ?> (створення першого ключа), у магазин не передаються.</div>
    <?php } ?>
  </div>
</div>

<?php if ($s && !$AM['denied']) { ?>
<form method="post" class="int-form" autocomplete="off">
<input type="hidden" name="amapi_token" value="<?php echo amapi_h($AM['token']); ?>">
<input type="hidden" name="amapi_action" value="save">
<div class="int-card int-card--form">
  <div class="int-sec">
    <div class="int-sec__t">Налаштування</div>
    <div class="section">
      <label class="not-b int-switch"><input type="checkbox" name="enabled" value="1"<?php echo $s['enabled'] ? ' checked' : ''; ?>> Обмін увімкнено (приймати запити від магазину)</label>
    </div>
    <div class="section">
      <label class="field-label"><span class="text-primary">Категорія за замовчуванням</span></label>
      <select name="default_catid" class="form-control" style="max-width:480px">
        <option value="0">— не задано (товари без категорії відхиляються) —</option>
        <?php foreach ($AM['categories'] as $c) { ?>
          <option value="<?php echo (int) $c['catid']; ?>"<?php echo (int) $s['default_catid'] === $c['catid'] ? ' selected' : ''; ?>><?php echo amapi_h($c['name']); ?></option>
        <?php } ?>
      </select>
      <div class="help-block">Для товарів, яким у модулі не задано «Відповідність категорій». Номери категорій порталу — у таблиці внизу сторінки.</div>
    </div>
  </div>
  <div class="int-sec">
    <div class="int-sec__t">Вебхук (необов'язково)</div>
    <div class="section">
      <label class="field-label"><span class="text-primary">URL вебхука</span></label>
      <input type="url" name="webhook_url" class="form-control" style="max-width:640px" value="<?php echo amapi_h($s['webhook_url']); ?>" placeholder="https://ваш-магазин.ua/index.php?fc=module&amp;module=allmarketsync&amp;controller=webhook">
      <div class="help-block">Нові замовлення одразу надсилаються в магазин; без вебхука модуль забирає їх сам за розкладом.</div>
    </div>
    <div class="section">
      <label class="field-label"><span class="text-primary">Секрет вебхука</span></label>
      <input type="password" name="webhook_secret" class="form-control" style="max-width:420px" autocomplete="new-password" placeholder="<?php echo $s['webhook_secret'] !== '' ? 'збережено — залиште порожнім, щоб не змінювати' : 'з налаштувань модуля'; ?>">
      <?php if ($s['webhook_status'] !== '') { ?><div class="help-block">Останнє надсилання: <b><?php echo amapi_h($s['webhook_status']); ?></b></div><?php } ?>
    </div>
  </div>
  <div class="int-sec">
    <button type="submit" class="btn btn-primary">Зберегти</button>
    <?php if ($s['webhook_url'] !== '') { ?>
    <button type="submit" class="btn btn-default" name="amapi_action" value="test" formnovalidate>Перевірити вебхук</button>
    <?php } ?>
  </div>
</div>
</form>

<div class="int-card">
  <div class="int-sec">
    <div class="int-sec__t">Стан</div>
    <dl class="amapi-kv">
      <dt>Товарів з магазину</dt><dd><?php echo (int) $AM['stats']['items']; ?><?php if ($AM['stats']['items_deleted']) { ?> <span class="help-block" style="display:inline">(знято: <?php echo (int) $AM['stats']['items_deleted']; ?>)</span><?php } ?></dd>
      <dt>Замовлень (передано)</dt><dd><?php echo (int) $AM['stats']['orders']; ?> (<?php echo (int) $AM['stats']['orders_acked']; ?>)</dd>
    </dl>
  </div>
  <div class="int-sec">
    <div class="int-sec__t">Останні запити магазину</div>
    <?php if (!$AM['log']) { ?>
      <div class="help-block">Запитів ще не було.</div>
    <?php } else { ?>
    <div style="overflow-x:auto">
    <table class="amapi-tbl">
      <tr><th>Час</th><th>Запит</th><th>Код</th><th>Позицій</th><th>Помилок</th><th>Повідомлення</th></tr>
      <?php foreach ($AM['log'] as $l) { $cls = isset($codes[(int) $l['code']]) ? $codes[(int) $l['code']] : 'warn'; ?>
      <tr>
        <td style="white-space:nowrap"><?php echo date('d.m H:i:s', (int) $l['t']); ?></td>
        <td><code><?php echo amapi_h($l['method'] . ' ' . $l['route']); ?></code></td>
        <td><span class="amapi-code amapi-code--<?php echo $cls; ?>"><?php echo (int) $l['code']; ?></span></td>
        <td><?php echo (int) $l['items']; ?></td>
        <td><?php echo (int) $l['errors']; ?></td>
        <td><?php echo amapi_h($l['message']); ?></td>
      </tr>
      <?php } ?>
    </table>
    </div>
    <?php } ?>
  </div>
  <div class="int-sec">
    <div class="int-sec__t">Категорії порталу (для «Відповідності категорій» у модулі)</div>
    <div class="amapi-cats">
    <table class="amapi-tbl">
      <?php foreach ($AM['categories'] as $c) { ?>
      <tr><td style="width:80px"><code><?php echo (int) $c['catid']; ?></code></td><td><?php echo amapi_h($c['name']); ?></td></tr>
      <?php } ?>
    </table>
    </div>
  </div>
</div>
<?php } ?>
</div>
