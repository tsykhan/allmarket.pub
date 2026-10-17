{*
 * Category placement on allmarket.biz: for each shop category the portal category it corresponds to,
 * or the portal category it is created inside. Works in the PrestaShop 1.6 and 1.7+/8 back office.
 *}
<div class="panel" id="am-catmap">
  <div class="panel-heading"><i class="icon-sitemap"></i> {l s='Category placement on allmarket.biz' mod='allmarketsync'}</div>

  {if $am_feed_mode}
    <div class="alert alert-info">{l s='In feed mode the portal reads the rules from the feed (allmarketId / allmarketParentId attributes of categories).' mod='allmarketsync'}</div>
  {/if}

  {if !$am_portal_ok}
    <div class="alert alert-warning">
      {l s='The category tree of allmarket.biz is not available, so the rules can only be edited as text.' mod='allmarketsync'}
      {if $am_portal_error}<br><b>{$am_portal_error|escape:'html':'UTF-8'}</b>{/if}
    </div>
    <form method="post" action="{$am_action|escape:'html':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <div class="col-lg-12">
          <textarea name="am_raw" rows="8" class="form-control">{$am_raw_map|escape:'html':'UTF-8'}</textarea>
          <p class="help-block">{l s='One rule per line: shop_category_id=portal_category_id (the shop category is that portal category) or shop_category_id>portal_category_id (it is created inside that portal category). Subcategories follow their parent.' mod='allmarketsync'}</p>
        </div>
      </div>
      <button type="submit" name="submitAllmarketCategoryMap" class="btn btn-primary"><i class="icon-save"></i> {l s='Save' mod='allmarketsync'}</button>
      {if !$am_feed_mode}
        <button type="submit" name="submitAllmarketRefreshPortalCats" class="btn btn-default"><i class="icon-refresh"></i> {l s='Load categories from allmarket.biz' mod='allmarketsync'}</button>
      {/if}
    </form>
  {else}
    <p class="help-block">
      {l s='Choose where each branch of your catalog goes on allmarket.biz. "Is the portal category" — products and subcategories go into the chosen portal category. "Create inside" — the shop category is created as a subcategory of the chosen one. Rows without a choice follow their parent; top-level categories without a choice are created at the portal root. Subcategories missing on the portal are created automatically.' mod='allmarketsync'}
    </p>
    <form method="post" action="{$am_action|escape:'html':'UTF-8'}">
      <div class="form-group" style="max-width:360px">
        <input type="text" class="form-control" id="am-catmap-filter" placeholder="{l s='Filter shop categories' mod='allmarketsync'}" autocomplete="off">
      </div>
      <table class="table" id="am-catmap-table">
        <thead>
          <tr>
            <th>{l s='Shop category' mod='allmarketsync'}</th>
            <th style="width:30%">{l s='allmarket.biz category' mod='allmarketsync'}</th>
            <th style="width:22%">{l s='How' mod='allmarketsync'}</th>
            <th>{l s='Result on allmarket.biz' mod='allmarketsync'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $am_rows as $r}
            <tr data-name="{$r.name|escape:'html':'UTF-8'|lower}">
              <td style="padding-left:{$r.depth*20+8}px">{if $r.depth}<span class="text-muted">&#8627;</span> {/if}{$r.name|escape:'html':'UTF-8'} <span class="text-muted small">#{$r.id|intval}</span></td>
              <td><select name="am_cat[{$r.id|intval}]" class="form-control am-portal-select" data-value="{$r.portal|escape:'html':'UTF-8'}"></select></td>
              <td>
                <select name="am_mode[{$r.id|intval}]" class="form-control am-mode-select">
                  <option value="map"{if $r.mode == 'map'} selected{/if}>{l s='Is the portal category' mod='allmarketsync'}</option>
                  <option value="inside"{if $r.mode == 'inside'} selected{/if}>{l s='Create inside' mod='allmarketsync'}</option>
                </select>
              </td>
              <td class="text-muted small">{$r.effective|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <div class="checkbox">
        <label><input type="checkbox" name="am_send_now" value="1" checked> {l s='Send the catalog to allmarket.biz right after saving' mod='allmarketsync'}</label>
      </div>
      <button type="submit" name="submitAllmarketCategoryMap" class="btn btn-primary"><i class="icon-save"></i> {l s='Save' mod='allmarketsync'}</button>
      <button type="submit" name="submitAllmarketRefreshPortalCats" class="btn btn-default"><i class="icon-refresh"></i> {l s='Reload categories from allmarket.biz' mod='allmarketsync'}</button>
    </form>
    <script type="text/javascript">
      (function () {
        var cats = {$am_portal_json nofilter};
        var none = '{l s='— follows the parent —' mod='allmarketsync' js=1}';
        var missing = '{l s='not found on allmarket.biz' mod='allmarketsync' js=1}';
        function esc(s) {
          return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
        }
        var html = '<option value="">' + esc(none) + '</option>', known = {};
        for (var i = 0; i < cats.length; i++) {
          var c = cats[i], pad = '';
          for (var d = 0; d < c.depth; d++) { pad += '   '; }
          known[c.id] = true;
          html += '<option value="' + esc(c.id) + '" title="' + esc(c.path) + '">' + pad + esc(c.name) + '</option>';
        }
        var selects = document.querySelectorAll('#am-catmap .am-portal-select');
        for (var j = 0; j < selects.length; j++) {
          var s = selects[j], v = s.getAttribute('data-value') || '';
          s.innerHTML = html;
          if (v && !known[v]) {
            s.insertAdjacentHTML('beforeend', '<option value="' + esc(v) + '">#' + esc(v) + ' — ' + esc(missing) + '</option>');
          }
          s.value = v;
          var mode = s.parentNode.parentNode.querySelector('.am-mode-select');
          mode.disabled = !v;
          s.onchange = (function (m) { return function () { m.disabled = !this.value; }; })(mode);
        }
        var filter = document.getElementById('am-catmap-filter');
        filter.oninput = filter.onkeyup = function () {
          var q = this.value.toLowerCase(), rows = document.querySelectorAll('#am-catmap-table tbody tr');
          for (var k = 0; k < rows.length; k++) {
            rows[k].style.display = !q || rows[k].getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
          }
        };
      })();
    </script>
  {/if}
</div>
