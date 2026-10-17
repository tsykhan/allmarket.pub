{* allmarket.biz Sync — overview: connection, tasks, cabinet URLs, status mapping, orders, log *}
{capture name="mainbox"}

<div class="am-sync">

{* ---------- connection ---------- *}
<h4 class="subheader">{__("allmarket_sync.connection")}</h4>
<form action="{""|fn_url}" method="post" name="allmarket_sync_ping_form" class="form-horizontal form-edit">
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.status")}:</label>
        <div class="controls">
            <p>
                {if $am_configured}
                    <span class="label label-success">{__("allmarket_sync.configured")}</span>
                {else}
                    <span class="label label-important">{__("allmarket_sync.not_configured")}</span>
                {/if}
                &nbsp;{__("allmarket_sync.mode")}: <strong>{__("allmarket_sync.mode_`$am_mode`")}</strong>
                &nbsp;{__("currency")}: <strong>{$am_currency}</strong>
            </p>
            <button type="submit" class="btn" name="dispatch[allmarket_sync.ping]">{__("allmarket_sync.check_connection")}</button>
            <a class="btn" href="{"addons.update?addon=allmarket_sync"|fn_url}">{__("settings")}</a>
        </div>
    </div>
</form>

{* ---------- tasks ---------- *}
<h4 class="subheader">{__("allmarket_sync.tasks")}</h4>
<form action="{""|fn_url}" method="post" name="allmarket_sync_run_form">
    <div class="table-responsive-wrapper">
    <table class="table table-middle table--relative">
        <thead>
            <tr>
                <th width="14%">{__("allmarket_sync.task")}</th>
                <th width="16%">{__("allmarket_sync.last_run")}</th>
                <th>{__("allmarket_sync.result")}</th>
                <th width="12%">&nbsp;</th>
            </tr>
        </thead>
        <tbody>
        {foreach $am_tasks as $task}
            {$last = $am_last.$task}
            <tr>
                <td><strong>{__("allmarket_sync.task_`$task`")}</strong><div class="muted">{$task}</div></td>
                <td>{if $last}{$last.date}{else}—{/if}</td>
                <td>
                    {if $last && $last.error}
                        <span class="text-error">{$last.error}</span>
                    {elseif $last}
                        <span class="muted">{foreach $last.stats as $k => $v}{$k}={if is_array($v)}…{else}{$v}{/if}{if !$v@last}, {/if}{/foreach}</span>
                    {/if}
                </td>
                <td class="right">
                    <button type="submit" class="btn btn-small" name="dispatch[allmarket_sync.run]" value="1" onclick="this.form.task.value='{$task}';">{__("allmarket_sync.run")}</button>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    </div>
    <input type="hidden" name="task" value="">
    <label class="checkbox inline"><input type="checkbox" name="force" value="1"> {__("allmarket_sync.force")}</label>
    <p class="muted">{__("allmarket_sync.force_help")}</p>
    <p>
        {__("allmarket_sync.items_on_portal")}:
        {foreach $am_items as $status => $cnt}<span class="label">{$status}: {$cnt}</span> {foreachelse}0{/foreach}
    </p>
</form>

{* ---------- cabinet / cron URLs ---------- *}
<h4 class="subheader">{__("allmarket_sync.for_cabinet")}</h4>
<form action="{""|fn_url}" method="post" name="allmarket_sync_secret_form" class="form-horizontal form-edit">
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.webhook_url")}:</label>
        <div class="controls"><input type="text" class="input-xxlarge" readonly value="{$am_urls.webhook}" onclick="this.select()"></div>
    </div>
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.webhook_secret")}:</label>
        <div class="controls">
            <input type="text" class="input-xlarge" readonly value="{$am_urls.webhook_secret}" onclick="this.select()">
            <button type="submit" class="btn btn-small cm-confirm" name="dispatch[allmarket_sync.reset_secret]" onclick="this.form.key.value='webhook_secret';">{__("allmarket_sync.reset")}</button>
        </div>
    </div>
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.feed_full")}:</label>
        <div class="controls"><input type="text" class="input-xxlarge" readonly value="{$am_urls.feed_full}" onclick="this.select()"></div>
    </div>
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.feed_offers")}:</label>
        <div class="controls">
            <input type="text" class="input-xxlarge" readonly value="{$am_urls.feed_offers}" onclick="this.select()">
            <button type="submit" class="btn btn-small cm-confirm" name="dispatch[allmarket_sync.reset_secret]" onclick="this.form.key.value='feed_token';">{__("allmarket_sync.reset")}</button>
        </div>
    </div>
    <div class="control-group">
        <label class="control-label">{__("allmarket_sync.cron")}:</label>
        <div class="controls">
            <p class="muted">{__("allmarket_sync.cron_help")}</p>
            <input type="text" class="input-xxlarge" readonly value="*/15 * * * * {$am_urls.cli_offers}" onclick="this.select()"><br>
            <input type="text" class="input-xxlarge" readonly value="0 3 * * * {$am_urls.cli_catalog}" onclick="this.select()"><br>
            <p class="muted">{__("allmarket_sync.cron_web_help")}</p>
            <input type="text" class="input-xxlarge" readonly value="{$am_urls.cron_offers}" onclick="this.select()"><br>
            <input type="text" class="input-xxlarge" readonly value="{$am_urls.cron_catalog}" onclick="this.select()">
            <button type="submit" class="btn btn-small cm-confirm" name="dispatch[allmarket_sync.reset_secret]" onclick="this.form.key.value='cron_token';">{__("allmarket_sync.reset")}</button>
        </div>
    </div>
    <input type="hidden" name="key" value="">
</form>

{* ---------- status mapping ---------- *}
<h4 class="subheader">{__("allmarket_sync.status_map")}</h4>
<p class="muted">{__("allmarket_sync.status_map_help")}</p>
<form action="{""|fn_url}" method="post" name="allmarket_sync_status_form">
    <table class="table table-middle" style="max-width:640px">
        <thead><tr><th>{__("allmarket_sync.cscart_status")}</th><th>{__("allmarket_sync.am_status")}</th></tr></thead>
        <tbody>
        {foreach $am_statuses as $code => $name}
            <tr>
                <td>{$name} <span class="muted">({$code})</span></td>
                <td>
                    <select name="status_map[{$code}]">
                        <option value="">— {__("allmarket_sync.not_sent")} —</option>
                        {foreach $am_am_statuses as $am}
                            {if $am != "new"}<option value="{$am}"{if $am_status_map.$code == $am} selected{/if}>{__("allmarket_sync.am_status_`$am`")} ({$am})</option>{/if}
                        {/foreach}
                    </select>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    <button type="submit" class="btn btn-primary" name="dispatch[allmarket_sync.save_statuses]">{__("save")}</button>
</form>

{* ---------- orders ---------- *}
<h4 class="subheader">{__("allmarket_sync.orders")}</h4>
<form action="{""|fn_url}" method="post" name="allmarket_sync_orders_form">
{if $am_orders}
    <div class="table-responsive-wrapper">
    <table class="table table-middle">
        <thead>
            <tr>
                <th>allmarket.biz</th>
                <th>{__("order")}</th>
                <th>{__("allmarket_sync.am_status")}</th>
                <th>{__("allmarket_sync.sent")}</th>
                <th>{__("allmarket_sync.result")}</th>
                <th>{__("date")}</th>
            </tr>
        </thead>
        <tbody>
        {foreach $am_orders as $o}
            <tr>
                <td>{$o.am_order_id}{if $o.am_number && $o.am_number != $o.am_order_id} <span class="muted">#{$o.am_number}</span>{/if}</td>
                <td>{if $o.order_id}<a href="{"orders.details?order_id=`$o.order_id`"|fn_url}">#{$o.order_id}</a>{else}—{/if}</td>
                <td>{$o.am_status}</td>
                <td>{$o.pushed_status}{if $o.pending_status} <span class="label label-warning">{__("allmarket_sync.queued")}: {$o.pending_status}</span>{/if}</td>
                <td>
                    {if $o.error}<span class="text-error">{$o.error|truncate:200}</span>{if !$o.order_id}
                        <button type="submit" class="btn btn-small" name="dispatch[allmarket_sync.retry_order]" onclick="this.form.link_id.value='{$o.link_id}';">{__("allmarket_sync.retry")}</button>{/if}
                    {elseif $o.order_id}{if $o.acked}<span class="muted">ack</span>{else}<span class="label label-warning">{__("allmarket_sync.no_ack")}</span>{/if}{/if}
                </td>
                <td>{$o.created|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    </div>
{else}
    <p class="no-items">{__("no_data")}</p>
{/if}
<input type="hidden" name="link_id" value="">
</form>

{* ---------- log ---------- *}
<h4 class="subheader">{__("allmarket_sync.log")}</h4>
<form action="{""|fn_url}" method="post" name="allmarket_sync_log_form">
    <p>
        <a class="btn btn-small{if !$am_level} active{/if}" href="{"allmarket_sync.manage"|fn_url}">{__("all")}</a>
        <a class="btn btn-small{if $am_level == "error"} active{/if}" href="{"allmarket_sync.manage?level=error"|fn_url}">{__("error")}</a>
        <a class="btn btn-small{if $am_level == "warning"} active{/if}" href="{"allmarket_sync.manage?level=warning"|fn_url}">{__("warning")}</a>
        <button type="submit" class="btn btn-small cm-confirm" name="dispatch[allmarket_sync.clear_log]">{__("allmarket_sync.clear_log")}</button>
    </p>
</form>
{if $am_log}
    <div class="table-responsive-wrapper">
    <table class="table table-condensed">
        <tbody>
        {foreach $am_log as $l}
            <tr>
                <td width="13%" class="nowrap muted">{$l.timestamp|date_format:"%Y-%m-%d %H:%M:%S"}</td>
                <td width="8%">{if $l.level == "error"}<span class="label label-important">error</span>{elseif $l.level == "warning"}<span class="label label-warning">warning</span>{else}<span class="label">info</span>{/if}</td>
                <td width="9%">{$l.task}</td>
                <td style="word-break:break-word">{$l.message}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    </div>
{else}
    <p class="no-items">{__("no_data")}</p>
{/if}

</div>
{/capture}

{include file="common/mainbox.tpl" title=__("allmarket_sync") content=$smarty.capture.mainbox}
