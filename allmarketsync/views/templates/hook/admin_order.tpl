{**
 * allmarket.biz info on the order page.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 *}
<div class="card mt-2 panel">
  <div class="card-header panel-heading">
    <h3 class="card-header-title">allmarket.biz</h3>
  </div>
  <div class="card-body">
    <p>{l s='Order on allmarket.biz:' mod='allmarketsync'} <strong>#{$am_order.am_number|escape:'html':'UTF-8'}</strong></p>
    <p>{l s='Status on allmarket.biz:' mod='allmarketsync'} <code>{$am_order.am_status|escape:'html':'UTF-8'}</code></p>
    {if $am_order.pending_status}
      <p class="text-warning">{l s='Waiting to be sent:' mod='allmarketsync'} <code>{$am_order.pending_status|escape:'html':'UTF-8'}</code></p>
    {/if}
    {if $am_order.error}
      <p class="text-danger"><small>{$am_order.error|escape:'html':'UTF-8'}</small></p>
    {/if}
  </div>
</div>
