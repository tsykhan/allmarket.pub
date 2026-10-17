{**
 * allmarket.biz exchange — tools, schedule and log.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 *}
<div class="panel" id="allmarket-tools">
  <h3><i class="icon-cogs"></i> {l s='Exchange' mod='allmarketsync'}</h3>

  <div class="row">
    <div class="col-lg-6">
      <h4>{l s='Status' mod='allmarketsync'}</h4>
      <table class="table">
        <tbody>
          <tr>
            <td>{l s='Items on allmarket.biz' mod='allmarketsync'}</td>
            <td><strong>{if isset($am_offer_counts.active)}{$am_offer_counts.active|intval}{else}0{/if}</strong>
              {if isset($am_offer_counts.deleted) && $am_offer_counts.deleted}
                <span class="text-muted">({l s='removed:' mod='allmarketsync'} {$am_offer_counts.deleted|intval})</span>
              {/if}
            </td>
          </tr>
          <tr>
            <td>{l s='Orders from allmarket.biz' mod='allmarketsync'}</td>
            <td><strong>{$am_order_stats.imported|intval}</strong>
              {if $am_order_stats.failed|intval}
                <span class="label label-danger">{l s='not imported:' mod='allmarketsync'} {$am_order_stats.failed|intval}</span>
              {/if}
            </td>
          </tr>
          {foreach from=$am_last_runs key=task item=run}
            <tr>
              <td>{l s='Last run' mod='allmarketsync'}: <code>{$task|escape:'html':'UTF-8'}</code></td>
              <td>
                {if $run}
                  {$run.date|escape:'html':'UTF-8'}
                  {if $run.error}
                    <span class="label label-danger" title="{$run.error|escape:'html':'UTF-8'}">{l s='error' mod='allmarketsync'}</span>
                    <small class="text-danger">{$run.error|truncate:120:'…'|escape:'html':'UTF-8'}</small>
                  {else}
                    <span class="label label-success">OK</span>
                    <small class="text-muted">
                      {foreach from=$run.stats key=k item=v}{if !is_array($v)}{$k|escape:'html':'UTF-8'}: {$v|escape:'html':'UTF-8'}; {/if}{/foreach}
                    </small>
                  {/if}
                {else}
                  <span class="text-muted">—</span>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    <div class="col-lg-6">
      <h4>{l s='Run now' mod='allmarketsync'}</h4>
      <form method="post" action="{$am_action|escape:'html':'UTF-8'}" class="form-inline">
        <p>
          <button type="submit" name="submitAllmarketPing" class="btn btn-default"><i class="icon-signal"></i> {l s='Test connection' mod='allmarketsync'}</button>
        </p>
        <p>
          <button type="submit" name="submitAllmarketCatalog" class="btn btn-primary"><i class="icon-upload"></i> {l s='Sync catalog' mod='allmarketsync'}</button>
          <button type="submit" name="submitAllmarketOffers" class="btn btn-default"><i class="icon-refresh"></i> {l s='Sync prices & stock' mod='allmarketsync'}</button>
          <button type="submit" name="submitAllmarketOrders" class="btn btn-default"><i class="icon-download"></i> {l s='Import orders' mod='allmarketsync'}</button>
          <button type="submit" name="submitAllmarketFeed" class="btn btn-default"><i class="icon-file-code-o"></i> {l s='Rebuild XML feeds' mod='allmarketsync'}</button>
        </p>
        <p class="checkbox">
          <label><input type="checkbox" name="force" value="1"> {l s='Force: resend everything and skip the delete safety check' mod='allmarketsync'}</label>
        </p>
        <p>
          <button type="submit" name="submitAllmarketRetryOrders" class="btn btn-default btn-sm">{l s='Retry failed orders' mod='allmarketsync'}</button>
          <button type="submit" name="submitAllmarketResetHashes" class="btn btn-default btn-sm"
                  onclick="return confirm('{l s='The whole catalog will be resent on the next sync. Continue?' mod='allmarketsync' js=1}');">{l s='Reset sync state' mod='allmarketsync'}</button>
          <button type="submit" name="submitAllmarketRegenerateTokens" class="btn btn-default btn-sm"
                  onclick="return confirm('{l s='Old cron, feed and webhook URLs will stop working. Continue?' mod='allmarketsync' js=1}');">{l s='Regenerate tokens' mod='allmarketsync'}</button>
        </p>
        <p class="help-block">{l s='Large catalogs are better synced by cron or CLI: the browser request may time out.' mod='allmarketsync'}</p>
      </form>
    </div>
  </div>

  <hr>
  <h4>{l s='Schedule (cron)' mod='allmarketsync'}</h4>
  <p class="help-block">{l s='Recommended: prices & stock every 15 minutes, orders and statuses every 5 minutes, full catalog nightly.' mod='allmarketsync'}</p>
  <pre style="white-space:pre-wrap;word-break:break-all">*/15 * * * * {$am_cli|escape:'html':'UTF-8'} offers
*/5 * * * * {$am_cli|escape:'html':'UTF-8'} orders &amp;&amp; {$am_cli|escape:'html':'UTF-8'} statuses
30 3 * * * {$am_cli|escape:'html':'UTF-8'} catalog</pre>
  <p>{l s='Or by URL (wget/curl):' mod='allmarketsync'}</p>
  <table class="table">
    {foreach from=$am_cron_urls key=task item=url}
      <tr><td style="width:90px"><code>{$task|escape:'html':'UTF-8'}</code></td><td><input type="text" readonly class="form-control" value="{$url|escape:'html':'UTF-8'}" onclick="this.select()"></td></tr>
    {/foreach}
  </table>

  <h4>{l s='For the allmarket.biz cabinet' mod='allmarketsync'}</h4>
  <table class="table">
    <tr><td style="width:220px">{l s='Full XML feed' mod='allmarketsync'}{if $am_mode == 'api'} <span class="text-muted">({l s='feed mode is off' mod='allmarketsync'})</span>{/if}</td>
      <td><input type="text" readonly class="form-control" value="{$am_feed_full|escape:'html':'UTF-8'}" onclick="this.select()"></td></tr>
    <tr><td>{l s='Prices & stock XML feed' mod='allmarketsync'}</td>
      <td><input type="text" readonly class="form-control" value="{$am_feed_offers|escape:'html':'UTF-8'}" onclick="this.select()"></td></tr>
    <tr><td>{l s='Webhook URL' mod='allmarketsync'}</td>
      <td><input type="text" readonly class="form-control" value="{$am_webhook_url|escape:'html':'UTF-8'}" onclick="this.select()"></td></tr>
    <tr><td>{l s='Webhook secret' mod='allmarketsync'}</td>
      <td><input type="text" readonly class="form-control" value="{$am_webhook_secret|escape:'html':'UTF-8'}" onclick="this.select()"></td></tr>
  </table>
</div>

<div class="panel">
  <h3><i class="icon-list-alt"></i> {l s='Log' mod='allmarketsync'}
    <span class="panel-heading-action">
      <form method="post" action="{$am_action|escape:'html':'UTF-8'}" style="display:inline">
        <button type="submit" name="submitAllmarketClearLog" class="btn btn-default btn-xs">{l s='Clear' mod='allmarketsync'}</button>
      </form>
    </span>
  </h3>
  {if $am_logs}
    <table class="table table-condensed">
      <thead><tr><th style="width:150px">{l s='Date' mod='allmarketsync'}</th><th style="width:80px">{l s='Task' mod='allmarketsync'}</th><th>{l s='Message' mod='allmarketsync'}</th></tr></thead>
      <tbody>
        {foreach from=$am_logs item=log}
          <tr class="{if $log.level == 'error'}danger{elseif $log.level == 'warning'}warning{/if}">
            <td>{$log.date_add|escape:'html':'UTF-8'}</td>
            <td><code>{$log.task|escape:'html':'UTF-8'}</code></td>
            <td style="word-break:break-word">{$log.message|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p class="text-muted">{l s='No entries yet.' mod='allmarketsync'}</p>
  {/if}
</div>
