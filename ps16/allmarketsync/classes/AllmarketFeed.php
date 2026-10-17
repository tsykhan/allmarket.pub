<?php
/**
 * XML feed (YML-compatible) for allmarket.biz import by URL.
 *
 * "full"   — catalog with categories and complete offer content;
 * "offers" — compact price & stock feed for frequent refresh.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketFeed
{
    const TYPES = ['full', 'offers'];

    /** @var AllmarketCatalog */
    protected $catalog;
    protected $idShop;

    public function __construct(AllmarketCatalog $catalog, $idShop)
    {
        $this->catalog = $catalog;
        $this->idShop = (int) $idShop;
    }

    public static function dir()
    {
        return _PS_MODULE_DIR_ . 'allmarketsync/feeds/';
    }

    public static function path($type, $idShop)
    {
        return self::dir() . $type . '_' . (int) $idShop . '.xml';
    }

    /**
     * Writes the feed to a temp file and atomically replaces the old one.
     *
     * @return int number of offers written
     */
    public function generate($type)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown feed type ' . $type);
        }
        if (!class_exists('XMLWriter')) {
            throw new RuntimeException('PHP XMLWriter extension is required');
        }
        $target = self::path($type, $this->idShop);
        $tmp = $target . '.tmp' . getmypid();

        $full = $type === 'full';
        $context = $this->catalog->getContext();
        $currency = $this->catalog->getCurrencyIso();

        $xml = new XMLWriter();
        if (!$xml->openUri($tmp)) {
            throw new RuntimeException('Cannot write ' . $tmp);
        }
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', date('Y-m-d H:i'));
        $xml->startElement('shop');
        $xml->writeElement('name', Configuration::get('PS_SHOP_NAME', null, null, $this->idShop));
        $xml->writeElement('company', Configuration::get('PS_SHOP_NAME', null, null, $this->idShop));
        $xml->writeElement('url', $context->shop->getBaseURL(true));
        $xml->writeElement('platform', 'PrestaShop');
        $xml->writeElement('version', _PS_VERSION_);
        $xml->writeElement('seller_id', (string) Configuration::get('ALLMARKET_SHOP_ID'));

        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', $currency);
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();

        if ($full) {
            $xml->startElement('categories');
            foreach ($this->catalog->getCategories() as $category) {
                $xml->startElement('category');
                $xml->writeAttribute('id', $category['id']);
                if ($category['parent_id']) {
                    $xml->writeAttribute('parentId', $category['parent_id']);
                }
                if ($category['allmarket_category_id']) {
                    $xml->writeAttribute('allmarketId', $category['allmarket_category_id']);
                }
                if (!empty($category['allmarket_parent_id'])) {
                    $xml->writeAttribute('allmarketParentId', $category['allmarket_parent_id']);
                }
                $xml->text($category['name']);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $count = 0;
        $xml->startElement('offers');
        foreach ($this->catalog->productIdBatches() as $ids) {
            foreach ($ids as $idProduct) {
                try {
                    $items = $this->catalog->buildItems($idProduct, $full);
                } catch (Exception $e) {
                    AllmarketLog::error('feed', 'Product #' . $idProduct . ': ' . $e->getMessage());
                    continue;
                }
                foreach ($items as $item) {
                    $full ? $this->writeFullOffer($xml, $item) : $this->writeShortOffer($xml, $item);
                    ++$count;
                }
            }
            $xml->flush();
        }
        $xml->endElement(); // offers
        $xml->endElement(); // shop
        $xml->endElement(); // yml_catalog
        $xml->endDocument();
        $xml->flush();
        unset($xml);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace ' . $target);
        }

        return $count;
    }

    protected function startOffer(XMLWriter $xml, array $item)
    {
        $xml->startElement('offer');
        $xml->writeAttribute('id', $item['external_id']);
        if (!empty($item['group_id'])) {
            $xml->writeAttribute('group_id', $item['group_id']);
        }
        $xml->writeAttribute('available', $item['available'] ? 'true' : 'false');
        $xml->writeElement('price', self::num($item['price']));
        if ($item['old_price'] !== null) {
            $xml->writeElement('oldprice', self::num($item['old_price']));
        }
        $xml->writeElement('currencyId', $item['currency']);
        $xml->writeElement('quantity_in_stock', (string) (int) $item['quantity']);
    }

    protected function writeShortOffer(XMLWriter $xml, array $item)
    {
        $this->startOffer($xml, $item);
        $xml->endElement();
    }

    protected function writeFullOffer(XMLWriter $xml, array $item)
    {
        $this->startOffer($xml, $item);
        $xml->writeElement('url', $item['url']);
        $xml->writeElement('categoryId', $item['category_id']);
        if ($item['allmarket_category_id']) {
            $xml->writeElement('allmarketCategoryId', $item['allmarket_category_id']);
        }
        foreach ($item['images'] as $image) {
            $xml->writeElement('picture', $image);
        }
        $xml->writeElement('name', $item['name']);
        if ($item['brand'] !== '') {
            $xml->writeElement('vendor', $item['brand']);
        }
        if ($item['sku'] !== '') {
            $xml->writeElement('vendorCode', $item['sku']);
        }
        if ($item['ean'] !== '') {
            $xml->writeElement('barcode', $item['ean']);
        }
        $xml->startElement('description');
        $xml->writeCdata(self::cdataSafe($item['description'] !== '' ? $item['description'] : $item['short_description']));
        $xml->endElement();
        if ($item['weight'] > 0) {
            $xml->writeElement('weight', self::num($item['weight']));
        }
        $xml->writeElement('condition', $item['condition']);
        foreach ($item['attributes'] as $attribute) {
            $xml->startElement('param');
            $xml->writeAttribute('name', $attribute['name']);
            $xml->text((string) $attribute['value']);
            $xml->endElement();
        }
        $xml->endElement();
    }

    protected static function num($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    protected static function cdataSafe($html)
    {
        // XMLWriter refuses CDATA containing the terminator.
        return str_replace(']]>', ']]&gt;', (string) $html);
    }
}
