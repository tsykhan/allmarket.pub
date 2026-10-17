<?php
/***************************************************************************
 * allmarket.biz Sync — builds allmarket.biz items from CS-Cart products.  *
 *                                                                         *
 * One item = one CS-Cart product. Variations (product_variations add-on)  *
 * are products too: items of one variation group share "group_id" (the    *
 * group's parent product) and carry the variation features as             *
 * attributes of type "variant".                                           *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

use Tygh\Registry;

class Catalog
{
    /** Fields sent by /offers/batch (price & stock only). */
    const OFFER_FIELDS = ['external_id', 'price', 'old_price', 'currency', 'quantity', 'available'];

    /** Variation group feature purposes (product_variations add-on). */
    const VARIATION_PURPOSES = ['group_variation_catalog_item', 'group_catalog_item'];

    protected $opts;
    protected $langCode;
    protected $currency;
    /** @var float units of the primary currency per unit of the export currency */
    protected $coefficient = 1.0;
    protected $decimals = 2;
    protected $hasVariations;
    protected $inventoryTracking;
    protected $allowNegative;
    /** @var array|null category_id => row */
    protected $categoryTree;
    /** @var int[]|null exported category ids (null = all) */
    protected $categoryFilter;

    public function __construct(array $opts)
    {
        $this->opts = $opts;
        $this->langCode = $opts['lang_code'];
        $this->currency = $opts['currency'];
        $currencies = Registry::get('currencies') ?: [];
        if (isset($currencies[$this->currency])) {
            $this->coefficient = (float) $currencies[$this->currency]['coefficient'] ?: 1.0;
            $this->decimals = (int) $currencies[$this->currency]['decimals'];
        }
        $this->hasVariations = Registry::get('addons.product_variations.status') === 'A'
            && in_array('parent_product_id', fn_get_table_fields('products'), true);
        $this->inventoryTracking = Registry::get('settings.General.inventory_tracking') !== 'N';
        $this->allowNegative = Registry::get('settings.General.allow_negative_amount') === 'Y';
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function getLangCode()
    {
        return $this->langCode;
    }

    public static function externalId($productId)
    {
        return (string) (int) $productId;
    }

    /** @return int product id or 0 */
    public static function parseExternalId($externalId)
    {
        // "12" (this add-on); "12-3" style ids are not used by CS-Cart.
        return preg_match('/^(\d+)$/', trim((string) $externalId), $m) ? (int) $m[1] : 0;
    }

    /* ------------------------------------------------------------------ */
    /* Product selection                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Yields arrays of product ids that pass the export filters.
     */
    public function productIdBatches($batchSize = 200)
    {
        $conditions = [db_quote("p.status = 'A'")];
        if (!empty($this->opts['company_id'])) {
            $conditions[] = db_quote('p.company_id = ?i', $this->opts['company_id']);
        }
        if ($this->hasVariations) {
            if (empty($this->opts['variations'])) {
                $conditions[] = 'p.parent_product_id = 0';
            } else {
                // A variation is sold only while its parent product is on sale.
                $conditions[] = "(p.parent_product_id = 0 OR EXISTS (SELECT 1 FROM ?:products pp WHERE pp.product_id = p.parent_product_id AND pp.status = 'A'))";
            }
        }
        $categories = $this->exportedCategoryIds();
        if ($categories !== null) {
            $conditions[] = db_quote(
                'EXISTS (SELECT 1 FROM ?:products_categories pc WHERE pc.product_id = p.product_id AND pc.category_id IN (?n))',
                $categories ?: [0]
            );
        }
        // Products of the trash category are deleted ones.
        $trash = $this->trashCategoryIds();
        if ($trash) {
            $conditions[] = db_quote(
                "NOT EXISTS (SELECT 1 FROM ?:products_categories pt WHERE pt.product_id = p.product_id AND pt.link_type = 'M' AND pt.category_id IN (?n))",
                $trash
            );
        }

        $lastId = 0;
        do {
            $ids = db_get_fields(
                'SELECT p.product_id FROM ?:products p WHERE ?p AND p.product_id > ?i ORDER BY p.product_id LIMIT ?i',
                implode(' AND ', $conditions),
                $lastId,
                (int) $batchSize
            );
            $ids = array_map('intval', $ids);
            if ($ids) {
                $lastId = end($ids);
                yield $ids;
            }
        } while (count($ids) === (int) $batchSize);
    }

    /* ------------------------------------------------------------------ */
    /* Items                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param int[] $productIds
     * @param bool  $withContent false = only offer fields (cheap)
     *
     * @return array[] items keyed by external_id
     */
    public function buildItems(array $productIds, $withContent = true)
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        $parentField = $this->hasVariations ? 'p.parent_product_id' : '0';
        list($stockField, $stockJoin) = $this->stockSql();
        $products = db_get_hash_array(
            "SELECT p.product_id, p.product_code, p.status, p.company_id, p.list_price, {$stockField} AS stock_amount, p.weight,
                    p.length, p.width, p.height, p.tracking, p.out_of_stock_actions, p.zero_price_action,
                    p.min_qty, {$parentField} AS parent_product_id,
                    pp.price, d.product, d.short_description, d.full_description, d.meta_description, d.page_title,
                    pc.category_id AS main_category_id
             FROM ?:products p
             LEFT JOIN ?:product_prices pp ON pp.product_id = p.product_id AND pp.lower_limit = 1 AND pp.usergroup_id = 0
             LEFT JOIN ?:product_descriptions d ON d.product_id = p.product_id AND d.lang_code = ?s
             LEFT JOIN ?:products_categories pc ON pc.product_id = p.product_id AND pc.link_type = 'M'
             {$stockJoin}
             WHERE p.product_id IN (?n)
             GROUP BY p.product_id",
            'product_id',
            $this->langCode,
            $productIds
        );
        if (!$products) {
            return [];
        }

        // Catalog promotions and the price for "all customers" (as on the storefront for a guest).
        fn_gather_additional_products_data($products, [
            'get_icon' => false,
            'get_detailed' => false,
            'get_additional' => false,
            'get_options' => false,
            'get_discounts' => true,
            'get_features' => false,
            'get_extra' => false,
            'get_taxed_prices' => false,
            'detailed_params' => false,
        ]);

        $groups = $this->opts['variations'] && $this->hasVariations ? $this->variationGroups(array_keys($products)) : [];
        $content = $withContent ? $this->loadContent($products, $groups) : [];

        $items = [];
        foreach ($productIds as $productId) {
            if (!isset($products[$productId])) {
                continue;
            }
            $product = $products[$productId];
            $item = $this->buildOffer($product);
            if (!empty($this->opts['only_in_stock']) && !$item['available']) {
                continue;
            }
            if ($withContent) {
                $item = array_merge($item, $this->buildContent($product, isset($groups[$productId]) ? $groups[$productId] : null, $content));
                // Stable key order: content hashes must not depend on merge order.
                ksort($item);
            }
            $items[$item['external_id']] = $item;
        }

        return $items;
    }

    protected function buildOffer(array $product)
    {
        $price = (float) $product['price'];
        $regular = max((float) (isset($product['base_price']) ? $product['base_price'] : $price), (float) $product['list_price']);

        $factor = 1 + ((float) $this->opts['markup']) / 100;
        $price = $this->convert($price * $factor);
        $regular = $this->convert($regular * $factor);

        $quantity = (int) $product['stock_amount'];
        $tracked = $this->inventoryTracking && $product['tracking'] !== 'D';
        $available = $product['status'] === 'A'
            && ($price > 0 || $product['zero_price_action'] === 'A')
            && (!$tracked || $quantity > 0 || $this->allowNegative || $product['out_of_stock_actions'] === 'B');

        return [
            'external_id' => self::externalId($product['product_id']),
            'price' => $price,
            'old_price' => $regular > $price ? $regular : null,
            'currency' => $this->currency,
            'quantity' => max(0, $quantity),
            'available' => $available,
        ];
    }

    /**
     * Stock: products.amount, or with the "Warehouses" add-on the warehouse total of the storefront
     * for products with "Track stock by warehouses".
     *
     * @return string[] [select expression, joins]
     */
    protected function stockSql()
    {
        if (Registry::get('addons.warehouses.status') !== 'A'
            || !in_array('is_stock_split_by_warehouses', fn_get_table_fields('products'), true)
        ) {
            return ['p.amount', ''];
        }
        $join = db_quote(
            'LEFT JOIN ?:warehouses_sum_products_amount ws ON ws.product_id = p.product_id AND ws.storefront_id = ?i
             LEFT JOIN ?:warehouses_sum_products_amount ws0 ON ws0.product_id = p.product_id AND ws0.storefront_id = 0',
            Config::storefrontId()
        );

        return ["(CASE WHEN p.is_stock_split_by_warehouses = 'Y' THEN COALESCE(ws.amount, ws0.amount, 0) ELSE p.amount END)", $join];
    }

    /** Primary currency → export currency. */
    protected function convert($amount)
    {
        return round((float) $amount / $this->coefficient, $this->decimals);
    }

    /**
     * @return array product_id => [group_id, parent_id, feature_ids[]]
     */
    protected function variationGroups(array $productIds)
    {
        $rows = db_get_array(
            'SELECT gp.product_id, gp.parent_product_id, gp.group_id FROM ?:product_variation_group_products gp WHERE gp.product_id IN (?n)',
            $productIds
        );
        if (!$rows) {
            return [];
        }
        $features = [];
        foreach (db_get_array(
            'SELECT group_id, feature_id FROM ?:product_variation_group_features WHERE group_id IN (?n) AND purpose IN (?a)',
            array_unique(array_column($rows, 'group_id')),
            self::VARIATION_PURPOSES
        ) as $row) {
            $features[(int) $row['group_id']][] = (int) $row['feature_id'];
        }
        $out = [];
        foreach ($rows as $row) {
            $parentId = (int) $row['parent_product_id'] ?: (int) $row['product_id'];
            $out[(int) $row['product_id']] = [
                'group_id' => (int) $row['group_id'],
                'parent_id' => $parentId,
                'feature_ids' => isset($features[(int) $row['group_id']]) ? $features[(int) $row['group_id']] : [],
            ];
        }

        return $out;
    }

    /**
     * Features, images and parent names for a batch of products.
     */
    protected function loadContent(array $products, array $groups)
    {
        $ids = array_keys($products);
        $parentIds = [];
        foreach ($products as $product) {
            if ((int) $product['parent_product_id']) {
                $parentIds[] = (int) $product['parent_product_id'];
            }
        }
        foreach ($groups as $group) {
            $parentIds[] = $group['parent_id'];
        }
        $parentIds = array_values(array_unique($parentIds));
        $extraIds = array_values(array_diff($parentIds, $ids));
        $imageIds = array_merge($ids, $extraIds);

        return [
            'features' => $this->loadFeatures($imageIds),
            'images_main' => fn_get_image_pairs($imageIds, 'product', 'M', false, true, $this->langCode),
            'images_more' => fn_get_image_pairs($imageIds, 'product', 'A', false, true, $this->langCode),
            'parents' => $parentIds ? db_get_hash_array(
                'SELECT product_id, product, short_description, full_description FROM ?:product_descriptions WHERE product_id IN (?n) AND lang_code = ?s',
                'product_id',
                $parentIds,
                $this->langCode
            ) : [],
        ];
    }

    /**
     * @return array product_id => [{feature_id, type, name, value, variant_id}]
     */
    protected function loadFeatures(array $productIds)
    {
        $rows = db_get_array(
            "SELECT fv.product_id, fv.feature_id, fv.variant_id, fv.value, fv.value_int, f.feature_type,
                    fd.description AS name, vd.variant
             FROM ?:product_features_values fv
             INNER JOIN ?:product_features f ON f.feature_id = fv.feature_id AND f.status = 'A' AND f.feature_type != 'G'
             LEFT JOIN ?:product_features_descriptions fd ON fd.feature_id = fv.feature_id AND fd.lang_code = ?s
             LEFT JOIN ?:product_feature_variant_descriptions vd ON vd.variant_id = fv.variant_id AND vd.lang_code = ?s
             WHERE fv.product_id IN (?n) AND fv.lang_code = ?s
             ORDER BY fv.product_id, f.position, fv.feature_id, fv.variant_id",
            $this->langCode,
            $this->langCode,
            $productIds,
            $this->langCode
        );
        $out = [];
        foreach ($rows as $row) {
            $type = $row['feature_type'];
            $variantId = (int) $row['variant_id'];
            $value = '';
            switch ($type) {
                case 'S': // text select
                case 'N': // number select
                case 'E': // brand / extended
                case 'M': // multiple checkboxes
                    $value = (string) $row['variant'];
                    break;
                case 'C': // single checkbox
                    if ($row['value'] !== 'Y') {
                        continue 2;
                    }
                    $value = __('yes', [], $this->langCode);
                    break;
                case 'O': // number
                    $value = $row['value_int'] === null || $row['value_int'] === '' ? (string) $row['value'] : self::number($row['value_int']);
                    break;
                case 'D': // date
                    $value = (int) $row['value_int'] ? date('Y-m-d', (int) $row['value_int']) : '';
                    break;
                default: // text
                    $value = (string) $row['value'];
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $out[(int) $row['product_id']][] = [
                'feature_id' => (int) $row['feature_id'],
                'type' => $type,
                'name' => trim((string) $row['name']),
                'value' => $value,
                'variant_id' => $variantId,
            ];
        }

        return $out;
    }

    protected function buildContent(array $product, $group, array $content)
    {
        $productId = (int) $product['product_id'];
        $variationFeatures = $group ? $group['feature_ids'] : [];
        $parentId = $group ? $group['parent_id'] : (int) $product['parent_product_id'];
        $parent = $parentId && $parentId !== $productId && isset($content['parents'][$parentId]) ? $content['parents'][$parentId] : null;

        // A variation whose common features were never synced from the parent inherits them.
        $features = isset($content['features'][$productId]) ? $content['features'][$productId] : [];
        if ($parent && !empty($content['features'][$parentId])) {
            $have = array_flip(array_column($features, 'feature_id'));
            foreach ($content['features'][$parentId] as $f) {
                if (!isset($have[$f['feature_id']]) && !in_array($f['feature_id'], $variationFeatures, true)) {
                    $features[] = $f;
                }
            }
        }

        $brand = '';
        $variantAttrs = [];
        $featureAttrs = [];
        foreach ($features as $f) {
            if ($f['type'] === 'E' && $brand === '') {
                $brand = $f['value'];
                continue;
            }
            $isVariant = in_array($f['feature_id'], $variationFeatures, true);
            $attr = [
                'name' => $f['name'],
                'value' => $f['value'],
                'type' => $isVariant ? 'variant' : 'feature',
                'id' => $f['feature_id'],
                'value_id' => $f['variant_id'],
            ];
            if (!$isVariant) {
                // Values without a variant (text, number, date, checkbox) are the product's own.
                $attr['custom'] = !$f['variant_id'];
                $featureAttrs[] = $attr;
            } else {
                $variantAttrs[] = $attr;
            }
        }

        $baseName = trim((string) $product['product']);
        $groupName = $parent && trim((string) $parent['product']) !== '' ? trim((string) $parent['product']) : $baseName;
        $description = (string) $product['full_description'];
        $shortDescription = (string) $product['short_description'];
        if ($parent && trim(strip_tags($description)) === '' && trim(strip_tags($shortDescription)) === '') {
            $description = (string) $parent['full_description'];
            $shortDescription = (string) $parent['short_description'];
        }
        $name = $baseName;
        if ($variantAttrs) {
            $values = [];
            foreach ($variantAttrs as $attr) {
                if (mb_stripos($name, $attr['value']) === false) {
                    $values[] = $attr['value'];
                }
            }
            if ($values) {
                $name .= ' (' . implode(', ', $values) . ')';
            }
        }

        $images = $this->imageUrls($productId, $content);
        if (!$images && $parent) {
            $images = $this->imageUrls($parentId, $content);
        }

        $categoryId = (int) $product['main_category_id'];

        return [
            'group_id' => $group ? (string) $group['parent_id'] : null,
            'product_id' => $productId,
            'combination_id' => 0,
            'name' => $name,
            'group_name' => $groupName,
            'sku' => (string) $product['product_code'],
            'ean' => '',
            'mpn' => '',
            'brand' => $brand,
            'category_id' => $categoryId ? (string) $categoryId : '',
            'category_path' => $this->categoryPath($categoryId),
            'allmarket_category_id' => $this->mapCategory($categoryId),
            'description' => $description,
            'short_description' => $shortDescription,
            'url' => fn_url('products.view?product_id=' . $productId, 'C', Config::protocol(), $this->langCode),
            'images' => $images,
            'attributes' => array_merge($variantAttrs, $featureAttrs),
            'condition' => 'new',
            'weight' => (float) $product['weight'],
            'weight_unit' => (string) Registry::get('settings.General.weight_symbol'),
            'dimensions' => [
                'width' => (float) $product['width'],
                'height' => (float) $product['height'],
                'depth' => (float) $product['length'],
                'unit' => '',
            ],
            'minimal_quantity' => max(1, (int) $product['min_qty']),
            'meta_title' => (string) $product['page_title'],
            'meta_description' => (string) $product['meta_description'],
        ];
    }

    /** Main image first, then the additional ones in their order. */
    protected function imageUrls($productId, array $content)
    {
        $urls = [];
        foreach (['images_main', 'images_more'] as $kind) {
            $pairs = isset($content[$kind][$productId]) ? $content[$kind][$productId] : [];
            uasort($pairs, function ($a, $b) {
                return (int) $a['position'] - (int) $b['position'];
            });
            foreach ($pairs as $pair) {
                if (!empty($pair['detailed']['image_path'])) {
                    $urls[] = self::absoluteUrl($pair['detailed']['image_path']);
                }
            }
        }

        return array_values(array_unique($urls));
    }

    protected static function absoluteUrl($url)
    {
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        if (!preg_match('#^https?://#', $url)) {
            return rtrim((string) Registry::get('config.current_location'), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }

    protected static function number($value)
    {
        $value = (float) $value;

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    protected function loadCategoryTree()
    {
        if ($this->categoryTree !== null) {
            return;
        }
        $condition = db_quote("c.status IN ('A', 'H')");
        if (in_array('is_trash', fn_get_table_fields('categories'), true)) {
            $condition .= " AND c.is_trash != 'Y'";
        }
        if (!empty($this->opts['company_id'])) {
            $condition .= db_quote(' AND c.company_id = ?i', $this->opts['company_id']);
        }
        $rows = db_get_array(
            'SELECT c.category_id, c.parent_id, c.id_path, c.level, c.position, cd.category AS name
             FROM ?:categories c
             LEFT JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
             WHERE ?p
             ORDER BY c.level, c.position, c.category_id',
            $this->langCode,
            $condition
        );
        $this->categoryTree = [];
        foreach ($rows as $row) {
            $this->categoryTree[(int) $row['category_id']] = $row;
        }
    }

    protected function trashCategoryIds()
    {
        if (!in_array('is_trash', fn_get_table_fields('categories'), true)) {
            return [];
        }

        return array_map('intval', db_get_fields("SELECT category_id FROM ?:categories WHERE is_trash = 'Y'"));
    }

    /**
     * Ids of the categories chosen in "Export categories" and all their subcategories,
     * or null when everything is exported.
     */
    protected function exportedCategoryIds()
    {
        if ($this->categoryFilter !== null) {
            return $this->categoryFilter ?: null;
        }
        $selected = isset($this->opts['categories']) ? $this->opts['categories'] : [];
        if (!$selected) {
            $this->categoryFilter = [];

            return null;
        }
        $ids = [];
        foreach ($selected as $categoryId) {
            $path = db_get_field('SELECT id_path FROM ?:categories WHERE category_id = ?i', $categoryId);
            if ($path === null || $path === false || $path === '') {
                continue;
            }
            $ids[] = (int) $categoryId;
            $ids = array_merge($ids, array_map('intval', db_get_fields(
                'SELECT category_id FROM ?:categories WHERE id_path LIKE ?l',
                $path . '/%'
            )));
        }
        $this->categoryFilter = array_values(array_unique($ids)) ?: [0];

        return $this->categoryFilter;
    }

    public function categoryPath($categoryId)
    {
        $this->loadCategoryTree();
        $names = [];
        $guard = 0;
        while ($categoryId && isset($this->categoryTree[$categoryId]) && $guard++ < 50) {
            array_unshift($names, $this->categoryTree[$categoryId]['name']);
            $categoryId = (int) $this->categoryTree[$categoryId]['parent_id'];
        }

        return implode(' > ', $names);
    }

    /**
     * Mapped allmarket.biz category, inherited from the nearest ancestor with a rule.
     * A "create inside" rule is not inherited as a category: the portal places such a branch itself.
     */
    public function mapCategory($categoryId)
    {
        $map = $this->opts['category_map'];
        if (!$map) {
            return null;
        }
        $this->loadCategoryTree();
        $guard = 0;
        while ($categoryId && $guard++ < 50) {
            if (isset($map[$categoryId])) {
                return $map[$categoryId]['mode'] === 'inside' ? null : (string) $map[$categoryId]['id'];
            }
            if (!isset($this->categoryTree[$categoryId])) {
                break;
            }
            $categoryId = (int) $this->categoryTree[$categoryId]['parent_id'];
        }

        return null;
    }

    /** Portal category to create this shop category inside of ("create inside" rule on this very category). */
    public function placeInside($categoryId)
    {
        $map = $this->opts['category_map'];
        $categoryId = (int) $categoryId;

        return isset($map[$categoryId]) && $map[$categoryId]['mode'] === 'inside' ? (string) $map[$categoryId]['id'] : null;
    }

    /**
     * Category rows for the placement panel: [{id, parent_id, name, depth}] in tree order.
     */
    public function categoryRows()
    {
        $this->loadCategoryTree();
        $children = [];
        foreach ($this->categoryTree as $id => $row) {
            $parent = isset($this->categoryTree[(int) $row['parent_id']]) ? (int) $row['parent_id'] : 0;
            $children[$parent][] = $id;
        }
        $out = [];
        $walk = function ($parent, $depth) use (&$walk, &$out, $children) {
            foreach (isset($children[$parent]) ? $children[$parent] : [] as $id) {
                $out[] = [
                    'id' => $id,
                    'parent_id' => $parent,
                    'name' => (string) $this->categoryTree[$id]['name'],
                    'depth' => $depth,
                ];
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return array[] [{id, parent_id, name, url, allmarket_category_id, allmarket_parent_id}] */
    public function getCategories()
    {
        $this->loadCategoryTree();
        $filter = $this->exportedCategoryIds();
        $keep = null;
        if ($filter !== null) {
            // Selected branches plus their ancestors, so the portal can rebuild the path.
            $keep = array_flip($filter);
            foreach ($filter as $categoryId) {
                if (!isset($this->categoryTree[$categoryId])) {
                    continue;
                }
                foreach (explode('/', (string) $this->categoryTree[$categoryId]['id_path']) as $ancestor) {
                    $keep[(int) $ancestor] = true;
                }
            }
        }

        $out = [];
        foreach ($this->categoryRows() as $row) {
            $id = $row['id'];
            if ($keep !== null && !isset($keep[$id])) {
                continue;
            }
            $out[] = [
                'id' => (string) $id,
                'parent_id' => $row['parent_id'] ? (string) $row['parent_id'] : null,
                'name' => $row['name'],
                'url' => fn_url('categories.view?category_id=' . $id, 'C', Config::protocol(), $this->langCode),
                'allmarket_category_id' => $this->mapCategory($id),
                'allmarket_parent_id' => $this->placeInside($id),
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Hashing                                                             */
    /* ------------------------------------------------------------------ */

    /** Offer fields in a fixed order, so the hash does not depend on how the item was built. */
    public static function offerPart(array $item)
    {
        $offer = [];
        foreach (self::OFFER_FIELDS as $field) {
            $offer[$field] = isset($item[$field]) ? $item[$field] : null;
        }

        return $offer;
    }

    public static function offerHash(array $item)
    {
        return md5(json_encode(self::offerPart($item)));
    }

    /** Hash of everything except price / stock. */
    public static function contentHash(array $item)
    {
        return md5(json_encode(array_diff_key($item, array_flip(self::OFFER_FIELDS))));
    }
}
