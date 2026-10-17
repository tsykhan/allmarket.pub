<?php
/**
 * Portal categories of the multitrade module («Мегамаркет»): resolving the shop's tree onto them,
 * creating missing ones, item counters and the category cache — mirroring the portal admin
 * (admin/category.inc.php: add / repair / count, system/cache.func.php: cache_category).
 */
class AmCategories
{
    private $db;
    private $cfg;
    private $mid;
    private $cats;
    private $portal;
    public $created = 0;

    public function __construct(AmDb $db, array $cfg)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->mid = (int) $cfg['module_id'];
    }

    /** Portal settings needed here: multilang, cache dir, file mode, languages. */
    public function portal()
    {
        if ($this->portal === null) {
            $CFG = array();
            if (!defined('IN_VUCMS')) {
                define('IN_VUCMS', true);
            }
            $root = $this->cfg['portal_root'];
            if (is_file($root . '/vucmsconf.inc.php')) {
                include $root . '/vucmsconf.inc.php';
            }
            $cacheDir = !empty($CFG['cache_dir']) ? $CFG['cache_dir'] : $root . '/file/cache';
            $langs = is_file($cacheDir . '/langs.php') ? include $cacheDir . '/langs.php' : null;
            $this->portal = array(
                'multilang' => !empty($CFG['multilang']),
                'cache_dir' => $cacheDir,
                'file_mod' => isset($CFG['file_mod']) ? (int) $CFG['file_mod'] : 0,
                'langs' => is_array($langs) && $langs ? $langs : array($this->cfg['content_lang'] => $this->cfg['content_lang']),
            );
        }
        return $this->portal;
    }

    public function all()
    {
        if ($this->cats === null) {
            $this->cats = array();
            foreach ($this->db->all('SELECT catid, catname, langs, parentid, arrparentid, arrchildid, child, group_list, group_show, group_add
                FROM #__category WHERE moduleid=? ORDER BY listorder, catid', array($this->mid)) as $c) {
                $this->cats[(int) $c['catid']] = $c;
            }
        }
        return $this->cats;
    }

    public function valid($id)
    {
        $id = (int) $id;
        $cats = $this->all();
        return $id > 0 && isset($cats[$id]) ? $id : 0;
    }

    /** @var array catid => old arrparentid of categories moved in this request (their ancestors need a recount) */
    public $moved = array();

    /**
     * Shop category tree → portal catid per shop category.
     *  - explicit mapping (allmarket_category_id that differs from the parent's — the module passes a parent's
     *    mapping down to its children): the shop category IS that portal category;
     *  - allmarket_parent_id («create inside»): the shop category goes under that portal category;
     *  - otherwise it goes under its parent's portal category (root for top-level ones).
     * Under the target parent an existing child with the same name is reused; a category the API created earlier
     * for this seller is moved there; a missing one is created when create_categories is on.
     *
     * @param array $list   [shop_id => ['parent' => shop_id|'', 'name' => …, 'am' => int, 'ap' => int]]
     * @param array $known  shop_id => catid stored by earlier requests
     * @return array shop_id => catid (0 = unresolved)
     */
    public function resolve(array $list, array $known, $userid = 0)
    {
        $out = array();
        $create = !empty($this->cfg['create_categories']);
        $own = $this->ownedBy($userid);
        $visit = function ($id, $depth) use (&$visit, &$out, $list, $known, $create, $own, $userid) {
            if (isset($out[$id])) {
                return $out[$id];
            }
            if (!isset($list[$id]) || $depth > 30) {
                return isset($known[$id]) ? $this->valid($known[$id]) : 0;
            }
            $c = $list[$id];
            $am = $this->valid($c['am']);
            $ap = isset($c['ap']) ? $this->valid($c['ap']) : 0;
            $parent = $c['parent'];
            $parentCat = $parent !== '' ? $visit($parent, $depth + 1) : 0;
            $parentAm = $parent !== '' && isset($list[$parent]) ? $this->valid($list[$parent]['am']) : 0;
            if ($am && !$ap && ($parent === '' || $am !== $parentAm)) {
                return $out[$id] = $am;
            }
            if (!$create) {
                return $out[$id] = $am;
            }
            $target = $ap ? $ap : $parentCat;
            if (!$ap && $parent !== '' && !$parentCat) {
                return $out[$id] = $am;
            }
            $prev = isset($known[$id]) ? $this->valid($known[$id]) : 0;
            $cats = $this->all();
            if ($prev && isset($own[$prev]) && (int) $cats[$prev]['parentid'] === (int) $target) {
                return $out[$id] = $prev;
            }
            $found = $this->findChild($target, $c['name']);
            if ($found) {
                return $out[$id] = $found;
            }
            if ($prev && isset($own[$prev]) && $prev !== (int) $target && !$this->isAncestor($prev, $target)) {
                $this->move($prev, $target);
                return $out[$id] = $prev;
            }
            $new = $this->create($target, $c['name']);
            if ($new && $userid) {
                $this->db->query('REPLACE INTO #__amapi_created (catid, userid, shop_id, created) VALUES (?,?,?,?)', array($new, (int) $userid, (string) $id, time()));
            }
            return $out[$id] = $new;
        };
        foreach (array_keys($list) as $id) {
            $visit((string) $id, 0);
        }
        return $out;
    }

    /** catid => true for portal categories the API created for this seller. */
    public function ownedBy($userid)
    {
        $o = array();
        if ($userid && $this->db->tableExists('amapi_created')) {
            foreach ($this->db->all('SELECT catid FROM #__amapi_created WHERE userid=?', array((int) $userid)) as $r) {
                $o[(int) $r['catid']] = true;
            }
        }
        return $o;
    }

    private function isAncestor($cat, $of)
    {
        $cats = $this->all();
        $guard = 0;
        while ($of && isset($cats[$of]) && $guard++ < 30) {
            if ((int) $of === (int) $cat) {
                return true;
            }
            $of = (int) $cats[$of]['parentid'];
        }
        return false;
    }

    /** Re-parents a category (tree fields are fixed by repairTree()). */
    private function move($catid, $parent)
    {
        $cats = $this->all();
        $this->moved[$catid] = $cats[$catid]['arrparentid'];
        $this->db->exec('UPDATE #__category SET parentid=? WHERE catid=?', array((int) $parent, (int) $catid));
        $this->cats = null;
    }

    /**
     * Removes categories the API created that nothing uses any more: no shop category of any seller points at them,
     * no items (in any status) and no subcategories. Returns removed catids.
     */
    public function cleanup()
    {
        if (!$this->db->tableExists('amapi_created')) {
            return array();
        }
        $removed = array();
        for ($pass = 0; $pass < 10; $pass++) {
            $rows = $this->db->all('SELECT c.catid, c.arrparentid FROM #__amapi_created x
                JOIN #__category c ON c.catid=x.catid
                WHERE NOT EXISTS (SELECT 1 FROM #__amapi_category a WHERE a.catid=x.catid)
                  AND NOT EXISTS (SELECT 1 FROM #__multitrade m WHERE m.catid=x.catid)
                  AND NOT EXISTS (SELECT 1 FROM #__category k WHERE k.parentid=x.catid)');
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $this->db->exec('DELETE FROM #__category WHERE catid=? AND moduleid=?', array((int) $r['catid'], $this->mid));
                $this->db->exec('DELETE FROM #__amapi_created WHERE catid=?', array((int) $r['catid']));
                $removed[(int) $r['catid']] = $r['arrparentid'];
            }
        }
        // Forget rows whose category was deleted elsewhere (admin).
        $this->db->exec('DELETE x FROM #__amapi_created x LEFT JOIN #__category c ON c.catid=x.catid WHERE c.catid IS NULL');
        if ($removed) {
            $this->cats = null;
        }
        return $removed;
    }

    /** Category tree for the module's picker: depth-first in the portal's order, names in the content language. */
    public function tree()
    {
        $lang = $this->cfg['content_lang'];
        $rows = $this->db->all('SELECT catid, parentid, catname, langs, item FROM #__category WHERE moduleid=? ORDER BY listorder, catid', array($this->mid));
        $byParent = array();
        $all = array();
        foreach ($rows as $r) {
            $langs = $r['langs'] !== '' ? json_decode($r['langs'], true) : array();
            $name = isset($langs[$lang]['catname']) && $langs[$lang]['catname'] !== '' ? $langs[$lang]['catname'] : $r['catname'];
            $all[(int) $r['catid']] = array('name' => AmText::unescape($name), 'parent' => (int) $r['parentid'],
                'items' => isset($langs[$lang]['item']) ? (int) $langs[$lang]['item'] : (int) $r['item']);
            $byParent[(int) $r['parentid']][] = (int) $r['catid'];
        }
        $out = array();
        $walk = function ($parent, $path, $depth) use (&$walk, &$out, $byParent, $all) {
            if (empty($byParent[$parent]) || $depth > 20) {
                return;
            }
            foreach ($byParent[$parent] as $id) {
                $p = $path ? $path . ' > ' . $all[$id]['name'] : $all[$id]['name'];
                $out[] = array('id' => (string) $id, 'parent_id' => $parent ? (string) $parent : null, 'name' => $all[$id]['name'],
                    'path' => $p, 'depth' => $depth, 'items' => $all[$id]['items']);
                $walk($id, $p, $depth + 1);
            }
        };
        $walk(0, '', 0);
        // Categories whose parent is outside the module (broken tree) at the end, as roots.
        $seen = array();
        foreach ($out as $o) {
            $seen[(int) $o['id']] = true;
        }
        foreach ($all as $id => $c) {
            if (!isset($seen[$id])) {
                $out[] = array('id' => (string) $id, 'parent_id' => null, 'name' => $c['name'], 'path' => $c['name'], 'depth' => 0, 'items' => $c['items']);
            }
        }
        return $out;
    }

    /**
     * Child of $parent whose name (base or any language) equals $name, case-insensitively. Names longer
     * than the catname column are stored shortened with "…", so the shortened form matches too.
     */
    public function findChild($parent, $name)
    {
        $want = array_unique(array_filter(array(self::norm($name), self::norm(AmText::plain($name, 50)))));
        if (!$want) {
            return 0;
        }
        foreach ($this->all() as $id => $c) {
            if ((int) $c['parentid'] !== (int) $parent) {
                continue;
            }
            if (in_array(self::norm($c['catname']), $want, true)) {
                return $id;
            }
            $langs = $c['langs'] !== '' ? json_decode($c['langs'], true) : array();
            foreach (is_array($langs) ? $langs : array() as $l) {
                if (isset($l['catname']) && in_array(self::norm($l['catname']), $want, true)) {
                    return $id;
                }
            }
        }
        return 0;
    }

    /** Creates a category like the admin's «Додати» does; returns the new catid. */
    public function create($parent, $name)
    {
        $cats = $this->all();
        $parent = (int) $parent;
        $p = $parent && isset($cats[$parent]) ? $cats[$parent] : null;
        $catname = AmText::plain($name, 50);
        if ($catname === '') {
            return 0;
        }
        $first = mb_substr(AmText::unescape($catname), 0, 1, 'UTF-8');
        $letter = preg_match('/^[a-zа-яё0-9 ]$/iu', $first) ? mb_strtoupper($first, 'UTF-8') : '';
        $lang = $this->cfg['content_lang'];
        $groups = $p ? $p : array('group_list' => '1,3,5,6,10,7', 'group_show' => '1,3,5,6,10,7', 'group_add' => '1,5,6,10,7');
        $arrparentid = $p ? $p['arrparentid'] . ',' . $parent : '0';
        $id = $this->db->insertRow('category', array(
            'moduleid' => $this->mid,
            'catname' => $catname,
            'langs' => json_encode(array($lang => array('catname' => $catname, 'letter' => $letter, 'item' => 0)), JSON_UNESCAPED_UNICODE),
            'catdir' => '',
            'linkurl' => '',
            'letter' => $letter,
            'level' => 1,
            'item' => 0,
            'property' => 0,
            'parentid' => $parent,
            'arrparentid' => $arrparentid,
            'child' => 0,
            'arrchildid' => '',
            'listorder' => 0,
            'group_list' => $groups['group_list'],
            'group_show' => $groups['group_show'],
            'group_add' => $groups['group_add'],
        ));
        if (!$id) {
            return 0;
        }
        $catdir = AmText::slug($name);
        if ($catdir === 'item' || $this->db->value('SELECT catid FROM #__category WHERE catdir=? AND moduleid=? AND catid<>?', array($catdir, $this->mid, $id))) {
            $catdir = substr($catdir, 0, 50) . '_' . $id;
        }
        $this->db->exec('UPDATE #__category SET catdir=?, linkurl=?, listorder=?, arrchildid=? WHERE catid=?',
            array($catdir, $catdir . '-' . $id . '-1.html', $id, (string) $id, $id));
        foreach (explode(',', $arrparentid) as $a) {
            $a = (int) $a;
            if ($a) {
                $this->db->exec("UPDATE #__category SET child=1, arrchildid=IF(arrchildid='' OR child=0, CONCAT(catid, ',', ?), CONCAT(arrchildid, ',', ?)) WHERE catid=?",
                    array($id, $id, $a));
            }
        }
        $this->cats = null;
        ++$this->created;
        return $id;
    }

    /** arrparentid / arrchildid / child of all module categories, as the admin's repair() computes them. */
    public function repairTree()
    {
        $this->cats = null;
        $cats = $this->all();
        $childs = array();
        foreach ($cats as $id => $c) {
            $parents = array();
            $p = (int) $c['parentid'];
            $guard = 0;
            while ($p && isset($cats[$p]) && $p !== $id && $guard++ < 30) {
                $parents[] = $p;
                $p = (int) $cats[$p]['parentid'];
            }
            $arrparentid = $parents ? '0,' . implode(',', array_reverse($parents)) : '0';
            if ($arrparentid !== $c['arrparentid']) {
                $this->db->exec('UPDATE #__category SET arrparentid=? WHERE catid=?', array($arrparentid, $id));
            }
            foreach ($parents as $a) {
                $childs[$a][] = $id;
            }
        }
        foreach ($cats as $id => $c) {
            $arrchildid = isset($childs[$id]) ? $id . ',' . implode(',', $childs[$id]) : (string) $id;
            $child = isset($childs[$id]) ? 1 : 0;
            if ($arrchildid !== $c['arrchildid'] || (int) $c['child'] !== $child) {
                $this->db->exec('UPDATE #__category SET arrchildid=?, child=? WHERE catid=?', array($arrchildid, $child, $id));
            }
        }
        $this->cats = null;
    }

    /**
     * Item counters as the admin's «Оновити статистику» computes them: published items of the category and
     * all its subcategories; with multilang the base counter takes lang ''/ru-ru and langs[xx].item lang ''/xx.
     * Ancestors are recounted too.
     */
    public function recount(array $catids)
    {
        $cats = $this->all();
        $todo = array();
        foreach ($catids as $id) {
            $id = (int) $id;
            if (!isset($cats[$id])) {
                continue;
            }
            $todo[$id] = true;
            foreach (explode(',', $cats[$id]['arrparentid']) as $a) {
                if ((int) $a && isset($cats[(int) $a])) {
                    $todo[(int) $a] = true;
                }
            }
        }
        if (!$todo) {
            return;
        }
        $portal = $this->portal();
        foreach (array_keys($todo) as $id) {
            $c = $this->db->one('SELECT arrchildid, child, langs FROM #__category WHERE catid=?', array($id));
            $ids = array();
            foreach (explode(',', $c['child'] ? $c['arrchildid'] : (string) $id) as $x) {
                if ((int) $x) {
                    $ids[] = (int) $x;
                }
            }
            $ids = $ids ?: array($id);
            $by = array();
            foreach ($this->db->all('SELECT lang, COUNT(*) n FROM #__multitrade WHERE status=3 AND catid IN (' . implode(',', $ids) . ') GROUP BY lang') as $r) {
                $by[$r['lang']] = (int) $r['n'];
            }
            $n = function ($k) use ($by) {
                return isset($by[$k]) ? $by[$k] : 0;
            };
            if ($portal['multilang']) {
                $item = $n('') + $n('ru-ru');
                $langs = $c['langs'] !== '' ? json_decode($c['langs'], true) : array();
                $langs = is_array($langs) ? $langs : array();
                foreach ($portal['langs'] as $k => $v) {
                    if ($k !== 'ru-ru') {
                        $langs[$k]['item'] = $n('') + $n($k);
                    }
                }
                $this->db->exec('UPDATE #__category SET item=?, langs=? WHERE catid=?',
                    array($item, json_encode($langs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id));
            } else {
                $this->db->exec('UPDATE #__category SET item=? WHERE catid=?', array(array_sum($by), $id));
            }
        }
    }

    /** Rewrites file/cache/category-<mid>.php and the select trees exactly like cache_category(). */
    public function rebuildCache()
    {
        $portal = $this->portal();
        $dir = $portal['cache_dir'];
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $data = array();
        foreach ($this->db->all('SELECT * FROM #__category WHERE moduleid=? ORDER BY listorder, catid', array($this->mid)) as $r) {
            $data[$r['catid']] = $r;
        }
        $a = array();
        foreach ($data as $r) {
            $e = $r['catid'];
            foreach (array('listorder', 'moduleid', 'item', 'template', 'show_template', 'seo_title', 'seo_keywords', 'seo_description', 'group_list', 'group_show', 'group_add') as $d) {
                unset($r[$d]);
            }
            $a[$e] = $r;
        }
        $this->write($dir . '/category-' . $this->mid . '.php',
            "<?php defined('IN_VUCMS') or exit('Access Denied'); return " . self::stripNr(var_export($a, true)) . '; ?>');
        if (count($data) < 100 && is_file($this->cfg['portal_root'] . '/system/tree.class.php')) {
            if (!function_exists('am_count')) {
                require __DIR__ . '/am_count.php';
            }
            require_once $this->cfg['portal_root'] . '/system/tree.class.php';
            $build = function ($lang) use ($data) {
                $categorys = array();
                foreach ($data as $id => $cat) {
                    if ($lang !== '') {
                        $langs = is_array($cat['langs']) ? $cat['langs'] : json_decode($cat['langs'], true);
                        if (isset($langs[$lang]) && is_array($langs[$lang])) {
                            foreach ($langs[$lang] as $k => $v) {
                                if ($v) {
                                    $cat[$k] = $v;
                                }
                            }
                        }
                    }
                    $categorys[$id] = array('id' => $id, 'parentid' => $cat['parentid'], 'name' => $cat['catname']);
                }
                $tree = new tree();
                $tree->tree($categorys);
                return $tree->get_tree(0, "<option value=\\\"\$id\\\">\$spacer\$name</option>") . '</select>';
            };
            $this->write($dir . '/catetree-' . $this->mid . '.php', $build(''));
            if ($portal['multilang']) {
                foreach ($portal['langs'] as $key => $val) {
                    if ($key !== 'ru-ru') {
                        $this->write($dir . '/catetree-' . $this->mid . '-' . $key . '.php', $build($key));
                    }
                }
            }
        } elseif (is_file($dir . '/catetree-' . $this->mid . '.php')) {
            @unlink($dir . '/catetree-' . $this->mid . '.php');
        }
        return true;
    }

    private function write($file, $content)
    {
        $tmp = $file . '.amapi-' . getmypid();
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        $mod = $this->portal()['file_mod'];
        if ($mod) {
            @chmod($tmp, $mod);
        }
        return rename($tmp, $file);
    }

    private static function stripNr($s)
    {
        return str_replace(array(chr(13), chr(10), "\n", "\r", "\t", '  '), '', $s);
    }

    private static function norm($s)
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', AmText::unescape(strip_tags((string) $s)))), 'UTF-8');
    }
}
