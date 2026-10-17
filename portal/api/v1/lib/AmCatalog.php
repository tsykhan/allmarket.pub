<?php
/**
 * Catalog endpoints: categories, products (upsert into all_multitrade), offers, delete.
 * One external_id = one multitrade item; combinations arrive as separate positions with group_id.
 */
class AmCatalog
{
    private $db;
    private $cfg;
    private $seller;
    private $cats;
    private $touchedCats = array();
    /** @var AmCategories|null */
    private $categories;

    public function __construct(AmDb $db, array $cfg, array $seller)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->seller = $seller;
    }

    public function categories(array $list)
    {
        $uid = (int) $this->seller['userid'];
        $tree = array();
        $results = array();
        foreach ($list as $c) {
            $id = isset($c['id']) ? self::id($c['id']) : '';
            if ($id === '') {
                $results[] = array('id' => isset($c['id']) ? $c['id'] : null, 'status' => 'error', 'error' => 'id is required');
                continue;
            }
            $tree[$id] = array(
                'parent' => isset($c['parent_id']) && $c['parent_id'] !== null ? self::id($c['parent_id']) : '',
                'name' => isset($c['name']) ? (string) $c['name'] : '',
                'url' => isset($c['url']) ? (string) $c['url'] : '',
                'am' => isset($c['allmarket_category_id']) ? (int) $c['allmarket_category_id'] : 0,
                'ap' => isset($c['allmarket_parent_id']) ? (int) $c['allmarket_parent_id'] : 0,
            );
        }
        // Parents sent earlier (outside this batch).
        $known = array();
        foreach ($this->db->all('SELECT shop_id, catid FROM #__amapi_category WHERE userid=?', array($uid)) as $r) {
            $known[$r['shop_id']] = (int) $r['catid'];
        }
        $cats = $this->categories ?: ($this->categories = new AmCategories($this->db, $this->cfg));
        $resolved = $cats->resolve($tree, $known, $uid);
        $touched = array();
        foreach ($tree as $id => $c) {
            $catid = isset($resolved[$id]) ? (int) $resolved[$id] : 0;
            $this->db->query('REPLACE INTO #__amapi_category (userid, shop_id, parent_id, name, url, catid, updated) VALUES (?,?,?,?,?,?,?)', array(
                $uid, (string) $id, $c['parent'], mb_substr(AmText::plain($c['name']), 0, 255, 'UTF-8'), mb_substr($c['url'], 0, 255, 'UTF-8'), $catid, time(),
            ));
            // The shop category now lives elsewhere: its products follow without being re-sent.
            $prev = isset($known[(string) $id]) ? (int) $known[(string) $id] : 0;
            if ($catid && $prev && $prev !== $catid) {
                $n = $this->db->exec('UPDATE #__multitrade m JOIN #__amapi_item a ON a.itemid=m.itemid
                    SET m.catid=? WHERE a.userid=? AND a.shop_category_id=? AND m.catid=? AND m.username=?',
                    array($catid, $uid, (string) $id, $prev, $this->seller['username']));
                if ($n) {
                    $touched[$prev] = $touched[$catid] = true;
                }
            }
            $r = array('id' => (string) $id, 'status' => 'ok', 'portal_category_id' => $catid ?: null);
            if ($c['am'] && !$cats->valid($c['am'])) {
                $r['warning'] = 'unknown allmarket_category_id ' . $c['am'];
            }
            if ($c['ap'] && !$cats->valid($c['ap'])) {
                $r['warning'] = 'unknown allmarket_parent_id ' . $c['ap'];
            }
            $results[] = $r;
        }
        $this->tidy($cats, $touched);
        return $results;
    }

    public function products(array $list)
    {
        $maps = $this->maps(self::column($list, 'external_id'));
        $results = array();
        foreach ($list as $p) {
            $ext = isset($p['external_id']) ? self::id($p['external_id']) : '';
            try {
                if ($ext === '') {
                    throw new InvalidArgumentException('external_id is required');
                }
                $results[] = array('external_id' => $ext, 'status' => $this->upsert($ext, $p, isset($maps[$ext]) ? $maps[$ext] : null));
            } catch (InvalidArgumentException $e) {
                $results[] = array('external_id' => $ext !== '' ? $ext : (isset($p['external_id']) ? $p['external_id'] : null), 'status' => 'error', 'error' => $e->getMessage());
            }
        }
        $this->recount();
        return $results;
    }

    public function offers(array $list)
    {
        $maps = $this->maps(self::column($list, 'external_id'));
        $results = array();
        foreach ($list as $o) {
            $ext = isset($o['external_id']) ? self::id($o['external_id']) : '';
            $map = isset($maps[$ext]) ? $maps[$ext] : null;
            if (!$map || $map['deleted'] || $map['item_status'] === null) {
                $results[] = array('external_id' => $ext, 'status' => 'error', 'error' => 'unknown external_id, send it via /products/batch first');
                continue;
            }
            try {
                $price = $this->price($o);
                $amount = $this->amount($o);
            } catch (InvalidArgumentException $e) {
                $results[] = array('external_id' => $ext, 'status' => 'error', 'error' => $e->getMessage());
                continue;
            }
            // edittime is not touched: it drives "recently updated" ordering on the portal,
            // price/stock ticks every 15 minutes must not lift items to the top.
            if ((float) $map['item_price'] != $price || (int) $map['item_amount'] !== $amount) {
                $this->db->updateRow('multitrade', array('price' => $price, 'amount' => $amount), 'itemid=?', array((int) $map['itemid']));
            }
            $this->db->exec('UPDATE #__amapi_item SET old_price=?, updated=? WHERE id=?', array($this->oldPrice($o, $price), time(), (int) $map['id']));
            $results[] = array('external_id' => $ext, 'status' => 'updated');
        }
        return $results;
    }

    public function delete(array $ids)
    {
        $maps = $this->maps($ids);
        $results = array();
        foreach ($ids as $raw) {
            $ext = self::id($raw);
            if (isset($maps[$ext])) {
                $m = $maps[$ext];
                if ($m['item_status'] !== null && (int) $m['item_status'] !== 0) {
                    $this->db->exec('UPDATE #__multitrade SET status=0 WHERE itemid=? AND username=?', array((int) $m['itemid'], $this->seller['username']));
                    $this->touchedCats[(int) $m['item_catid']] = true;
                }
                $this->db->exec('UPDATE #__amapi_item SET deleted=1, updated=? WHERE id=?', array(time(), (int) $m['id']));
            }
            $results[] = array('external_id' => $ext, 'status' => 'deleted');
        }
        $this->recount();
        return $results;
    }

    private function upsert($ext, array $p, $map)
    {
        if (!isset($p['name']) || trim((string) $p['name']) === '') {
            throw new InvalidArgumentException('name is required');
        }
        $price = $this->price($p);
        $amount = $this->amount($p);
        // The shop category's portal category (mapped or created by /categories/batch) is the most precise;
        // allmarket_category_id from the module is the mapping inherited from a parent.
        $shopCat = 0;
        if (isset($p['category_id']) && $p['category_id'] !== '') {
            $shopCat = $this->catid($this->db->value('SELECT catid FROM #__amapi_category WHERE userid=? AND shop_id=?',
                array((int) $this->seller['userid'], self::id($p['category_id']))));
        }
        $mapped = $this->catid(isset($p['allmarket_category_id']) ? $p['allmarket_category_id'] : null);
        $catid = !empty($this->cfg['create_categories']) ? ($shopCat ?: $mapped) : ($mapped ?: $shopCat);
        if (!$catid) {
            $catid = $this->catid($this->seller['default_catid']);
        }
        if (!$catid) {
            throw new InvalidArgumentException('category is required: set allmarket_category_id or a default category in the allmarket.biz cabinet');
        }

        $now = time();
        $title = AmText::plain($p['name'], 100);
        $brand = AmText::plain(isset($p['brand']) ? $p['brand'] : '', 100);
        $short = !empty($p['short_description']) ? $p['short_description'] : (isset($p['description']) ? $p['description'] : '');
        $images = array();
        foreach (isset($p['images']) && is_array($p['images']) ? $p['images'] : array() as $url) {
            $url = trim((string) $url);
            if (preg_match('#^https?://[^\s"\'<>]+$#i', $url) && strlen($url) <= 255) {
                $images[] = $url;
            }
        }
        $item = array(
            'catid' => $catid,
            'typeid' => self::condition(isset($p['condition']) ? $p['condition'] : 'new'),
            'mtypeid' => 1,
            'title' => $title,
            'introduce' => AmText::plain($short, 255),
            'brand' => $brand,
            'price' => $price,
            'amount' => $amount,
            'minamount' => max(1, isset($p['minimal_quantity']) ? (int) $p['minimal_quantity'] : 1),
            'keyword' => implode(',', array($title, $brand, $this->catPath($catid))),
            // Production keeps the article here (e.g. "MA-1377"); the 2017 schema had '0'.
            'sku' => AmText::plain(isset($p['sku']) ? $p['sku'] : '', 64),
            'ubrandid' => $this->brandId(isset($p['brand']) ? $p['brand'] : ''),
            'editor' => $this->seller['username'],
            'edittime' => $now,
            'editdate' => date('Y-m-d', $now),
            'ip' => self::ip(),
        );
        for ($i = 0; $i <= 8; $i++) {
            $item[$i ? 'thumb' . $i : 'thumb'] = isset($images[$i]) ? $images[$i] : '';
        }
        $item += $this->sellerFields();

        $current = null;
        if ($map && $map['item_status'] !== null) {
            $current = $map;
        }
        if ($current) {
            $itemid = (int) $map['itemid'];
            // Keep moderator decisions (1 rejected, 2 pending). A hidden item (0) comes back when the API removed it
            // itself, or always with republish_hidden: an active product in the shop is visible on the portal.
            $status = (int) $current['item_status'];
            if ($status === 0 && ($map['deleted'] || !empty($this->cfg['republish_hidden']))) {
                $status = (int) $this->cfg['item_status'];
            }
            $item['status'] = $status;
            $item['linkurl'] = 'show/' . $itemid . '-' . AmText::slug($p['name']) . '.html';
            $this->db->updateRow('multitrade', $item, 'itemid=?', array($itemid));
            $this->touchedCats[(int) $current['item_catid']] = true;
            $result = 'updated';
        } else {
            $item['status'] = (int) $this->cfg['item_status'];
            $item['lang'] = (string) $this->cfg['item_lang'];
            $item['addtime'] = $now;
            $item['adddate'] = date('Y-m-d', $now);
            $itemid = $this->db->insertRow('multitrade', $item);
            $this->db->updateRow('multitrade', array('linkurl' => 'show/' . $itemid . '-' . AmText::slug($p['name']) . '.html'), 'itemid=?', array($itemid));
            $result = 'created';
        }
        $this->touchedCats[$catid] = true;
        $attrs = isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : array();
        $features = null;
        try {
            $features = AmFeatures::save($this->cfg, $itemid, (int) $this->seller['userid'], $attrs);
        } catch (Throwable $e) {
            error_log('allmarket api features: ' . $e->getMessage());
        }
        // Without the portal's features module the attributes stay readable as a table in the description.
        $content = AmText::html(isset($p['description']) ? $p['description'] : '') . ($features === null ? self::attributesTable($attrs) : '');
        $this->db->query('REPLACE INTO #__multitrade_data (itemid, content) VALUES (?, ?)', array($itemid, $content));

        $row = array(
            (int) $this->seller['userid'], $ext, $itemid,
            isset($p['group_id']) && $p['group_id'] !== null ? self::id($p['group_id']) : '',
            isset($p['category_id']) ? self::id($p['category_id']) : '',
            mb_substr(isset($p['sku']) ? (string) $p['sku'] : '', 0, 64, 'UTF-8'),
            mb_substr(isset($p['ean']) ? (string) $p['ean'] : '', 0, 32, 'UTF-8'),
            $this->oldPrice($p, $price), $now, $now,
        );
        $this->db->query('INSERT INTO #__amapi_item (userid, external_id, itemid, group_id, shop_category_id, sku, ean, old_price, deleted, created, updated)
            VALUES (?,?,?,?,?,?,?,?,0,?,?)
            ON DUPLICATE KEY UPDATE itemid=VALUES(itemid), group_id=VALUES(group_id), shop_category_id=VALUES(shop_category_id),
              sku=VALUES(sku), ean=VALUES(ean), old_price=VALUES(old_price), deleted=0, updated=VALUES(updated)', $row);
        return $result;
    }

    /** Approved entry of the portal's global brand directory (all_ubrand, status 1) by name; never creates brands. */
    private function brandId($name)
    {
        static $hasTable = null;
        $name = trim(AmText::unescape(strip_tags((string) $name)));
        if ($name === '') {
            return 0;
        }
        if ($hasTable === null) {
            $hasTable = $this->db->tableExists('ubrand');
        }
        if (!$hasTable) {
            return 0;
        }
        return (int) $this->db->value('SELECT itemid FROM #__ubrand WHERE status=1 AND (name=? OR name=?) ORDER BY itemid LIMIT 1',
            array($name, htmlspecialchars($name, ENT_QUOTES, 'UTF-8')));
    }

    /** Seller's contact fields that VuCMS denormalizes into every item row. */
    private function sellerFields()
    {
        $m = $this->seller['member'];
        $c = function ($k, $fallback = '') use ($m) {
            return isset($m['c_' . $k]) && $m['c_' . $k] !== '' ? $m['c_' . $k] : $fallback;
        };
        return array(
            'username' => $m['username'],
            'groupid' => (int) $m['groupid'],
            'company' => $c('company', $m['company']),
            'vip' => (int) $c('vip', 0),
            'validated' => (int) $c('validated', 0),
            'truename' => $m['truename'],
            'telephone' => $c('telephone'),
            'mobile' => $m['mobile'],
            'address' => $c('address'),
            'email' => $m['email'],
            'msn' => isset($m['msn']) ? $m['msn'] : '',
            'icq' => isset($m['icq']) ? $m['icq'] : '',
            'jabber' => isset($m['jabber']) ? $m['jabber'] : '',
            'skype' => isset($m['skype']) ? $m['skype'] : '',
            'areaid' => (int) $c('areaid', isset($m['areaid']) ? $m['areaid'] : 0),
        );
    }

    /** external_id => map row + current item status/price/amount/catid (null when the item row is gone). */
    private function maps(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map(array('AmCatalog', 'id'), $ids), 'strlen')));
        if (!$ids) {
            return array();
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge(array($this->seller['username'], (int) $this->seller['userid']), $ids);
        $rows = $this->db->all('SELECT a.*, m.status AS item_status, m.price AS item_price, m.amount AS item_amount, m.catid AS item_catid
            FROM #__amapi_item a LEFT JOIN #__multitrade m ON m.itemid=a.itemid AND m.username=?
            WHERE a.userid=? AND a.external_id IN (' . $in . ')', $params);
        $out = array();
        foreach ($rows as $r) {
            $out[$r['external_id']] = $r;
        }
        return $out;
    }

    private function loadCats()
    {
        if ($this->cats === null) {
            $this->cats = array();
            foreach ($this->db->all('SELECT catid, catname, parentid, arrparentid FROM #__category WHERE moduleid=?', array((int) $this->cfg['module_id'])) as $c) {
                $this->cats[(int) $c['catid']] = $c;
            }
        }
        return $this->cats;
    }

    /** Valid multitrade category id or 0. */
    private function catid($id)
    {
        $id = (int) $id;
        $cats = $this->loadCats();
        return $id > 0 && isset($cats[$id]) ? $id : 0;
    }

    /** "Parent &raquo; Child" — the format VuCMS keeps in multitrade.keyword (used by search). */
    private function catPath($catid)
    {
        $cats = $this->loadCats();
        $names = array();
        foreach (explode(',', $cats[$catid]['arrparentid'] . ',' . $catid) as $id) {
            $id = (int) $id;
            if ($id && isset($cats[$id])) {
                $names[] = $cats[$id]['catname'];
            }
        }
        return implode(' &raquo; ', $names);
    }

    /** Item counters (category + ancestors) and the category cache, as the portal admin keeps them. */
    private function recount()
    {
        $ids = $this->touchedCats;
        $this->touchedCats = array();
        $cats = $this->categories ?: ($this->categories = new AmCategories($this->db, $this->cfg));
        $this->tidy($cats, $ids);
    }

    /**
     * After categories were created / moved or items changed category: removes API-created categories nothing uses,
     * fixes the tree, recounts the touched categories with their old and new ancestors, rewrites the cache.
     */
    private function tidy(AmCategories $cats, array $touched)
    {
        $removed = $cats->cleanup();
        $structural = $cats->created || $cats->moved || $removed;
        if ($structural) {
            $cats->repairTree();
            $this->cats = null;
        }
        $ids = array_filter(array_map('intval', array_keys($touched)));
        // Moved and removed categories: their old ancestors lost items (array_merge would renumber the catid keys).
        foreach (array($cats->moved, $removed) as $list) {
            foreach ($list as $catid => $oldParents) {
                $ids[] = (int) $catid;
                foreach (explode(',', (string) $oldParents) as $a) {
                    if ((int) $a) {
                        $ids[] = (int) $a;
                    }
                }
            }
        }
        $ids = array_unique($ids);
        if ($ids) {
            $cats->recount($ids);
        }
        if ($structural || $ids) {
            $cats->rebuildCache();
        }
        $cats->created = 0;
        $cats->moved = array();
    }

    /** Portal category tree for the module's picker. */
    public function portalTree()
    {
        $cats = $this->categories ?: ($this->categories = new AmCategories($this->db, $this->cfg));
        return $cats->tree();
    }

    private function price(array $p)
    {
        if (!isset($p['price']) || !is_numeric($p['price']) || $p['price'] < 0) {
            throw new InvalidArgumentException('price is required');
        }
        return $this->money($p['price'], $p);
    }

    /**
     * Amount in the seller's accounting currency (Кабінет → Валюти; empty = portal default, USD):
     * the portal shows multitrade.price in that currency, so shop prices in another currency are converted.
     */
    private function money($amount, array $p)
    {
        $cur = isset($p['currency']) && $p['currency'] !== '' ? strtoupper((string) $p['currency']) : '';
        if ($cur !== '' && !empty($this->cfg['currencies']) && !in_array($cur, $this->cfg['currencies'], true)) {
            throw new InvalidArgumentException('currency ' . $cur . ' is not accepted, use ' . implode('/', $this->cfg['currencies']));
        }
        return AmCurrency::toBase($this->db, $this->cfg, $this->seller['member'], $amount, $cur);
    }

    private function amount(array $p)
    {
        if (isset($p['available']) && !$p['available']) {
            return 0;
        }
        $q = isset($p['quantity']) ? (int) $p['quantity'] : 0;
        // Real stock. Available with no stock (orders allowed out of stock) gives backorder_amount, 0 by default.
        return $q > 0 ? min($q, 4294967295) : (isset($this->cfg['backorder_amount']) ? max(0, (int) $this->cfg['backorder_amount']) : 0);
    }

    private function oldPrice(array $p, $price)
    {
        if (!isset($p['old_price']) || !is_numeric($p['old_price'])) {
            return null;
        }
        $old = $this->money($p['old_price'], $p);
        return $old > $price ? $old : null;
    }

    private static function condition($c)
    {
        $map = array('new' => 0, 'used' => 2, 'refurbished' => 3);
        return isset($map[$c]) ? $map[$c] : 0;
    }

    private static function attributesTable($attrs)
    {
        $rows = '';
        foreach (is_array($attrs) ? $attrs : array() as $a) {
            if (!isset($a['name'], $a['value']) || $a['value'] === '') {
                continue;
            }
            $rows .= '<tr><th>' . AmText::plain($a['name']) . '</th><td>' . AmText::plain($a['value']) . '</td></tr>';
        }
        return $rows !== '' ? "\n<table class=\"am-attributes\">" . $rows . '</table>' : '';
    }

    public static function id($v)
    {
        $v = trim((string) $v);
        return preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $v) ? $v : '';
    }

    private static function column(array $list, $key)
    {
        $out = array();
        foreach ($list as $row) {
            if (is_array($row) && isset($row[$key])) {
                $out[] = $row[$key];
            }
        }
        return $out;
    }

    private static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 50) : '';
    }
}
