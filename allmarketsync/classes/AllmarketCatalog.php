<?php
/**
 * Builds allmarket.biz items (products / offers) from PrestaShop data.
 *
 * One item = one sellable offer: a product without combinations, or one
 * combination (then items of the same product share "group_id").
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketCatalog
{
    /** Fields sent by /offers/batch (price & stock only). */
    const OFFER_FIELDS = ['external_id', 'price', 'old_price', 'currency', 'quantity', 'available'];

    protected $idShop;
    protected $idLang;
    protected $opts;
    /** @var Context */
    protected $context;
    /** @var Currency */
    protected $currency;
    protected $stockManagement;
    /** @var array|null id_category => [id_parent, name, level_depth] */
    protected $categoryTree;

    public function __construct($idShop, array $opts)
    {
        $this->idShop = (int) $idShop;
        $this->opts = $opts;
        $this->idLang = (int) $opts['id_lang'];

        $this->context = Context::getContext()->cloneContext();
        $this->context->shop = new Shop($this->idShop);
        $this->context->language = new Language($this->idLang);
        $this->currency = new Currency((int) $opts['id_currency']);
        if (!Validate::isLoadedObject($this->currency)) {
            $this->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }
        $this->context->currency = $this->currency;
        if (!$this->context->link) {
            $this->context->link = new Link();
        }
        // Product::getPriceStatic() dies without a cart or employee in context (CLI / cron).
        if (!is_object($this->context->cart)) {
            $this->context->cart = new Cart();
        }
        $this->stockManagement = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getCurrencyIso()
    {
        return $this->currency->iso_code;
    }

    public static function externalId($idProduct, $idProductAttribute)
    {
        return $idProductAttribute ? (int) $idProduct . '-' . (int) $idProductAttribute : (string) (int) $idProduct;
    }

    /** @return int[] [id_product, id_product_attribute] or [0, 0] */
    public static function parseExternalId($externalId)
    {
        if (preg_match('/^(\d+)(?:-(\d+))?$/', trim((string) $externalId), $m)) {
            return [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0];
        }

        return [0, 0];
    }

    /**
     * Yields arrays of product ids that pass the export filters.
     */
    public function productIdBatches($batchSize = 200)
    {
        $p = _DB_PREFIX_;
        $join = '';
        if (!empty($this->opts['categories'])) {
            $join = "INNER JOIN `{$p}category_product` cp ON cp.`id_product` = ps.`id_product`
                     AND cp.`id_category` IN (" . implode(',', array_map('intval', $this->opts['categories'])) . ')';
        }
        $lastId = 0;
        do {
            $rows = Db::getInstance()->executeS(
                "SELECT DISTINCT ps.`id_product`
                 FROM `{$p}product_shop` ps
                 {$join}
                 WHERE ps.`id_shop` = {$this->idShop}
                   AND ps.`active` = 1
                   AND ps.`visibility` IN ('both', 'catalog', 'search')
                   AND ps.`id_product` > {$lastId}
                 ORDER BY ps.`id_product`
                 LIMIT " . (int) $batchSize
            );
            $ids = $rows ? array_map('intval', array_column($rows, 'id_product')) : [];
            if ($ids) {
                $lastId = end($ids);
                yield $ids;
            }
        } while (count($ids) === (int) $batchSize);
    }

    /**
     * @param bool $withContent false = only offer fields (cheap)
     *
     * @return array[] items keyed by external_id
     */
    public function buildItems($idProduct, $withContent = true)
    {
        $product = new Product((int) $idProduct, false, $this->idLang, $this->idShop, $this->context);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return [];
        }

        $combinations = [];
        if (!empty($this->opts['combinations']) && Combination::isFeatureActive() && $product->hasAttributes()) {
            foreach ($product->getAttributeCombinations($this->idLang) as $row) {
                $idPa = (int) $row['id_product_attribute'];
                if (!isset($combinations[$idPa])) {
                    $combinations[$idPa] = [
                        'reference' => $row['reference'],
                        'ean13' => $row['ean13'],
                        'mpn' => isset($row['mpn']) ? $row['mpn'] : '',
                        'weight' => (float) $row['weight'],
                        'minimal_quantity' => (int) $row['minimal_quantity'],
                        'attributes' => [],
                    ];
                }
                $combinations[$idPa]['attributes'][] = [
                    'name' => $row['group_name'],
                    'value' => $row['attribute_name'],
                    'type' => 'variant',
                    'id' => (int) $row['id_attribute_group'],
                    'value_id' => isset($row['id_attribute']) ? (int) $row['id_attribute'] : 0,
                ];
            }
        }

        $common = $withContent ? $this->buildCommonContent($product) : null;
        $combinationImages = ($withContent && $combinations) ? ($product->getCombinationImages($this->idLang) ?: []) : [];

        $items = [];
        foreach ($combinations ? array_keys($combinations) : [0] as $idPa) {
            $item = $this->buildOffer($product, $idPa);
            if (!empty($this->opts['only_in_stock']) && !$item['available']) {
                continue;
            }
            if ($withContent) {
                $item = array_merge($item, $common, $this->buildVariantContent(
                    $product,
                    $idPa,
                    $idPa ? $combinations[$idPa] : null,
                    $common,
                    isset($combinationImages[$idPa]) ? $combinationImages[$idPa] : []
                ));
                // Keep a stable key order so content hashes do not depend on merge order.
                ksort($item);
            }
            $items[$item['external_id']] = $item;
        }

        return $items;
    }

    protected function buildOffer(Product $product, $idPa)
    {
        $specificPrice = null;
        $price = Product::getPriceStatic(
            (int) $product->id, true, $idPa ?: null, 6, null, false, true, 1, false,
            null, null, null, $specificPrice, true, true, $this->context
        );
        $specificPrice = null;
        $regular = Product::getPriceStatic(
            (int) $product->id, true, $idPa ?: null, 6, null, false, false, 1, false,
            null, null, null, $specificPrice, true, true, $this->context
        );

        $factor = 1 + ((float) $this->opts['markup']) / 100;
        $precision = $this->pricePrecision();
        $price = Tools::ps_round($price * $factor, $precision);
        $regular = Tools::ps_round($regular * $factor, $precision);

        $quantity = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, (int) $idPa, $this->idShop);
        $allowOutOfStock = Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int) $product->id, $this->idShop));
        $available = (bool) $product->available_for_order
            && (!$this->stockManagement || $quantity > 0 || $allowOutOfStock);

        return [
            'external_id' => self::externalId($product->id, $idPa),
            'price' => (float) $price,
            'old_price' => $regular > $price ? (float) $regular : null,
            'currency' => $this->currency->iso_code,
            'quantity' => max(0, $quantity),
            'available' => $available,
        ];
    }

    protected function pricePrecision()
    {
        if (isset($this->currency->precision)) {
            return (int) $this->currency->precision;
        }

        return (int) Configuration::get('PS_PRICE_DISPLAY_PRECISION') ?: 2;
    }

    protected function buildCommonContent(Product $product)
    {
        $idCategory = (int) $product->id_category_default;
        $features = [];
        $rows = Product::getFrontFeaturesStatic($this->idLang, (int) $product->id) ?: [];
        // Custom (per-product) values vs. predefined ones: the portal keeps predefined values in its dictionary.
        $custom = [];
        $valueIds = array_filter(array_map(function ($f) {
            return isset($f['id_feature_value']) ? (int) $f['id_feature_value'] : 0;
        }, $rows));
        if ($valueIds) {
            foreach (Db::getInstance()->executeS('SELECT id_feature_value, custom FROM ' . _DB_PREFIX_ . 'feature_value
                WHERE id_feature_value IN (' . implode(',', $valueIds) . ')') ?: [] as $fv) {
                $custom[(int) $fv['id_feature_value']] = (bool) $fv['custom'];
            }
        }
        foreach ($rows as $feature) {
            $idValue = isset($feature['id_feature_value']) ? (int) $feature['id_feature_value'] : 0;
            $features[] = [
                'name' => $feature['name'],
                'value' => $feature['value'],
                'type' => 'feature',
                'id' => (int) $feature['id_feature'],
                'value_id' => $idValue,
                'custom' => $idValue ? (isset($custom[$idValue]) ? $custom[$idValue] : false) : true,
            ];
        }

        $images = [];
        $imageRows = $product->getImages($this->idLang) ?: [];
        usort($imageRows, function ($a, $b) {
            if ((int) $a['cover'] !== (int) $b['cover']) {
                return (int) $b['cover'] - (int) $a['cover'];
            }

            return (int) $a['position'] - (int) $b['position'];
        });
        foreach ($imageRows as $image) {
            $images[(int) $image['id_image']] = $this->imageUrl($product, (int) $image['id_image']);
        }

        return [
            'product_id' => (int) $product->id,
            'group_name' => (string) $product->name,
            'description' => (string) $product->description,
            'short_description' => (string) $product->description_short,
            'brand' => $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '',
            'category_id' => (string) $idCategory,
            'category_path' => $this->categoryPath($idCategory),
            'allmarket_category_id' => $this->mapCategory($idCategory),
            'condition' => (string) $product->condition,
            'features' => $features,
            'images_by_id' => $images,
            'dimensions' => [
                'width' => (float) $product->width,
                'height' => (float) $product->height,
                'depth' => (float) $product->depth,
                'unit' => (string) Configuration::get('PS_DIMENSION_UNIT'),
            ],
            'weight_unit' => (string) Configuration::get('PS_WEIGHT_UNIT'),
            'meta_title' => (string) $product->meta_title,
            'meta_description' => (string) $product->meta_description,
        ];
    }

    protected function buildVariantContent(Product $product, $idPa, $combination, array $common, array $combinationImages)
    {
        $attributes = $combination ? $combination['attributes'] : [];
        $name = (string) $product->name;
        if ($attributes) {
            $name .= ' (' . implode(', ', array_map(function ($a) {
                return $a['value'];
            }, $attributes)) . ')';
        }

        // Combination images first, then the rest of the product gallery.
        $images = $common['images_by_id'];
        if ($combinationImages) {
            $first = [];
            foreach ($combinationImages as $img) {
                $idImage = (int) $img['id_image'];
                if (isset($images[$idImage])) {
                    $first[$idImage] = $images[$idImage];
                }
            }
            $images = $first + $images;
        }

        $reference = $combination && $combination['reference'] !== '' ? $combination['reference'] : $product->reference;
        $ean = $combination && $combination['ean13'] !== '' ? $combination['ean13'] : $product->ean13;
        $mpn = $combination && $combination['mpn'] !== '' ? $combination['mpn'] : (isset($product->mpn) ? $product->mpn : '');

        // PrestaShop stores "0" for an unset EAN on some combinations.
        if (trim((string) $ean, '0') === '') {
            $ean = '';
        }

        return [
            'group_id' => $combination ? (string) (int) $product->id : null,
            'combination_id' => (int) $idPa,
            'name' => $name,
            'sku' => (string) $reference,
            'ean' => (string) $ean,
            'mpn' => (string) $mpn,
            'url' => $this->context->link->getProductLink($product, null, null, null, $this->idLang, $this->idShop, (int) $idPa),
            'images' => array_values($images),
            'images_by_id' => null,
            'attributes' => array_merge($attributes, $common['features']),
            'features' => null,
            'weight' => (float) $product->weight + ($combination ? $combination['weight'] : 0),
            'minimal_quantity' => max(1, $combination ? $combination['minimal_quantity'] : (int) $product->minimal_quantity),
        ];
    }

    protected function imageUrl(Product $product, $idImage)
    {
        $type = $this->opts['image_type'] !== '' ? $this->opts['image_type'] : null;
        $rewrite = is_array($product->link_rewrite) ? reset($product->link_rewrite) : $product->link_rewrite;
        $url = $this->context->link->getImageLink($rewrite, (int) $product->id . '-' . (int) $idImage, $type);
        if (strpos($url, '//') === 0) {
            $url = (Configuration::get('PS_SSL_ENABLED') ? 'https:' : 'http:') . $url;
        } elseif (!preg_match('#^https?://#', $url)) {
            $url = Tools::getShopDomainSsl(true) . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /* ---------------- categories ---------------- */

    protected function loadCategoryTree()
    {
        if ($this->categoryTree !== null) {
            return;
        }
        $p = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT c.`id_category`, c.`id_parent`, c.`level_depth`, c.`is_root_category`, cl.`name`, cl.`link_rewrite`
             FROM `{$p}category` c
             INNER JOIN `{$p}category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = {$this->idShop}
             INNER JOIN `{$p}category_lang` cl ON cl.`id_category` = c.`id_category`
                 AND cl.`id_lang` = {$this->idLang} AND cl.`id_shop` = {$this->idShop}
             WHERE c.`active` = 1
             ORDER BY c.`level_depth`, c.`nleft`"
        ) ?: [];
        $this->categoryTree = [];
        foreach ($rows as $row) {
            $this->categoryTree[(int) $row['id_category']] = $row;
        }
    }

    /** Category is exported if it is below the shop's home category. */
    protected function isExportedCategory(array $row)
    {
        return (int) $row['level_depth'] > 1 && !(int) $row['is_root_category'];
    }

    public function categoryPath($idCategory)
    {
        $this->loadCategoryTree();
        $names = [];
        $guard = 0;
        while (isset($this->categoryTree[$idCategory]) && $this->isExportedCategory($this->categoryTree[$idCategory]) && $guard++ < 50) {
            array_unshift($names, $this->categoryTree[$idCategory]['name']);
            $idCategory = (int) $this->categoryTree[$idCategory]['id_parent'];
        }

        return implode(' > ', $names);
    }

    /**
     * Mapped allmarket.biz category, inherited from the nearest ancestor with a rule.
     * A "create inside" rule is not inherited as a category: the portal places such a branch itself.
     */
    public function mapCategory($idCategory)
    {
        $map = $this->opts['category_map'];
        if (!$map) {
            return null;
        }
        $this->loadCategoryTree();
        $guard = 0;
        while ($idCategory && $guard++ < 50) {
            if (isset($map[$idCategory])) {
                return $map[$idCategory]['mode'] === 'inside' ? null : (string) $map[$idCategory]['id'];
            }
            if (!isset($this->categoryTree[$idCategory])) {
                break;
            }
            $idCategory = (int) $this->categoryTree[$idCategory]['id_parent'];
        }

        return null;
    }

    /** Portal category to create this shop category inside of ("create inside" rule on this very category). */
    public function placeInside($idCategory)
    {
        $map = $this->opts['category_map'];
        $idCategory = (int) $idCategory;

        return isset($map[$idCategory]) && $map[$idCategory]['mode'] === 'inside' ? (string) $map[$idCategory]['id'] : null;
    }

    /** @return array[] [{id, parent_id, name, url, allmarket_category_id, allmarket_parent_id}] */
    public function getCategories()
    {
        $this->loadCategoryTree();
        $out = [];
        foreach ($this->categoryTree as $id => $row) {
            if (!$this->isExportedCategory($row)) {
                continue;
            }
            $parent = (int) $row['id_parent'];
            $parentExported = isset($this->categoryTree[$parent]) && $this->isExportedCategory($this->categoryTree[$parent]);
            $out[] = [
                'id' => (string) $id,
                'parent_id' => $parentExported ? (string) $parent : null,
                'name' => $row['name'],
                'url' => $this->context->link->getCategoryLink($id, $row['link_rewrite'], $this->idLang, null, $this->idShop),
                'allmarket_category_id' => $this->mapCategory($id),
                'allmarket_parent_id' => $this->placeInside($id),
            ];
        }

        return $out;
    }

    /* ---------------- hashing ---------------- */

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
        $content = array_diff_key($item, array_flip(self::OFFER_FIELDS));
        unset($content['images_by_id'], $content['features']);

        return md5(json_encode($content));
    }

    /** Removes helper fields that must not be sent. */
    public static function cleanItem(array $item)
    {
        unset($item['images_by_id'], $item['features']);

        return $item;
    }
}
