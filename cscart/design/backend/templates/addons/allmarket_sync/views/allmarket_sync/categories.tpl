{*
 * Category placement on allmarket.biz: for each shop category the portal category it corresponds to,
 * or the portal category it is created inside.
 *}
{capture name="mainbox"}
<div id="am-catmap">

{if $am_feed_mode}
    <div class="alert alert-info">{__("allmarket_sync.placement_feed_mode")}</div>
{/if}

{if !$am_portal_ok}
    <div class="alert alert-warning">
        {__("allmarket_sync.portal_tree_unavailable")}
        {if $am_portal_error}<br><b>{$am_portal_error}</b>{/if}
    </div>
    <form action="{""|fn_url}" method="post" name="allmarket_sync_catmap_raw">
        <textarea name="am_raw" rows="10" class="input-full" style="width:100%;max-width:640px">{$am_raw_map}</textarea>
        <p class="muted">{__("allmarket_sync.placement_rules_help")}</p>
        <button type="submit" class="btn btn-primary" name="dispatch[allmarket_sync.save_categories]">{__("save")}</button>
        {if !$am_feed_mode}
            <button type="submit" class="btn" name="dispatch[allmarket_sync.refresh_portal_cats]">{__("allmarket_sync.load_portal_cats")}</button>
        {/if}
    </form>
{else}
    <p class="muted">{__("allmarket_sync.placement_help")}</p>
    <form action="{""|fn_url}" method="post" name="allmarket_sync_catmap">
        <p><input type="text" id="am-catmap-filter" class="input-large" placeholder="{__("allmarket_sync.filter_categories")}" autocomplete="off"></p>
        <div class="table-responsive-wrapper">
        <table class="table table-middle" id="am-catmap-table">
            <thead>
                <tr>
                    <th>{__("allmarket_sync.shop_category")}</th>
                    <th width="30%">{__("allmarket_sync.portal_category")}</th>
                    <th width="18%">{__("allmarket_sync.how")}</th>
                    <th>{__("allmarket_sync.result_on_portal")}</th>
                </tr>
            </thead>
            <tbody>
            {foreach $am_rows as $r}
                <tr data-name="{$r.name|lower}">
                    <td style="padding-left:{$r.depth*20+8}px">{if $r.depth}<span class="muted">&#8627;</span> {/if}{$r.name} <span class="muted">#{$r.id}</span></td>
                    <td><select name="am_cat[{$r.id}]" class="am-portal-select" data-value="{$r.portal}" style="width:100%"></select></td>
                    <td>
                        <select name="am_mode[{$r.id}]" class="am-mode-select" style="width:100%">
                            <option value="map"{if $r.mode == "map"} selected{/if}>{__("allmarket_sync.mode_map")}</option>
                            <option value="inside"{if $r.mode == "inside"} selected{/if}>{__("allmarket_sync.mode_inside")}</option>
                        </select>
                    </td>
                    <td class="muted">{$r.effective}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        </div>
        <label class="checkbox"><input type="checkbox" name="am_send_now" value="1" checked> {__("allmarket_sync.send_now")}</label>
        <button type="submit" class="btn btn-primary" name="dispatch[allmarket_sync.save_categories]">{__("save")}</button>
        <button type="submit" class="btn" name="dispatch[allmarket_sync.refresh_portal_cats]">{__("allmarket_sync.reload_portal_cats")}</button>
    </form>
    <script>
    (function () {
        var cats = {$am_portal_json nofilter};
        var none = '{__("allmarket_sync.follows_parent")|escape:"javascript" nofilter}';
        var missing = '{__("allmarket_sync.not_found_on_portal")|escape:"javascript" nofilter}';
        function esc(s) {
            return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
        }
        var html = '<option value="">' + esc(none) + '</option>', known = { };
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
{/capture}

{include file="common/mainbox.tpl" title=__("allmarket_sync_categories") content=$smarty.capture.mainbox}
