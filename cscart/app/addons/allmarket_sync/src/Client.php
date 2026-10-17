<?php
/***************************************************************************
 * allmarket.biz Sync — HTTP client for the allmarket.biz Seller API.      *
 * Contract: docs/API.md (the same as for the PrestaShop module).          *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

class Client
{
    const MAX_ATTEMPTS = 3;

    protected $baseUrl;
    protected $apiKey;
    protected $shopId;
    protected $timeout;
    protected $maxAttempts;

    public function __construct($baseUrl, $apiKey, $shopId, $timeout = 60, $maxAttempts = self::MAX_ATTEMPTS)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
        $this->shopId = (string) $shopId;
        $this->timeout = (int) $timeout;
        $this->maxAttempts = max(1, (int) $maxAttempts);
    }

    public static function fromConfig($timeout = 60, $maxAttempts = self::MAX_ATTEMPTS)
    {
        return new self(
            Config::get('api_url'),
            Config::get('api_key'),
            Config::get('shop_id'),
            $timeout,
            $maxAttempts
        );
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function ping()
    {
        return $this->request('GET', '/ping');
    }

    /** Portal category tree: [{id, parent_id, name, path, depth, items}] */
    public function getPortalCategories()
    {
        return $this->request('GET', '/portal-categories');
    }

    public function pushCategories(array $categories)
    {
        return $this->request('POST', '/categories/batch', ['categories' => $categories]);
    }

    /** Creates or fully updates products (content + price + stock). */
    public function pushProducts(array $products)
    {
        return $this->request('POST', '/products/batch', ['products' => $products]);
    }

    /** Updates only price / stock of already known products. */
    public function pushOffers(array $offers)
    {
        return $this->request('POST', '/offers/batch', ['offers' => $offers]);
    }

    /** @param string[] $externalIds */
    public function deleteProducts(array $externalIds)
    {
        return $this->request('POST', '/products/delete', ['external_ids' => array_values($externalIds)]);
    }

    public function getOrders($updatedSince, $page = 1, $limit = 50)
    {
        $query = ['page' => (int) $page, 'limit' => (int) $limit];
        if ($updatedSince) {
            $query['updated_since'] = $updatedSince;
        }

        return $this->request('GET', '/orders', null, $query);
    }

    public function ackOrder($amOrderId, array $data)
    {
        return $this->request('POST', '/orders/' . rawurlencode($amOrderId) . '/ack', $data);
    }

    public function pushOrderStatus($amOrderId, array $data)
    {
        return $this->request('POST', '/orders/' . rawurlencode($amOrderId) . '/status', $data);
    }

    /**
     * @return array decoded JSON response
     *
     * @throws ApiException
     */
    public function request($method, $path, $body = null, array $query = [])
    {
        if (!$this->isConfigured()) {
            throw new ApiException('allmarket.biz API URL or key is not configured');
        }
        if (!function_exists('curl_init')) {
            throw new ApiException('PHP cURL extension is required');
        }

        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($body !== null && $payload === false) {
            throw new ApiException('Cannot encode request: ' . json_last_error_msg());
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-Allmarket-Shop: ' . $this->shopId,
            'User-Agent: CS-Cart-allmarket_sync/' . Config::VERSION . ' (CS-Cart ' . (defined('PRODUCT_VERSION') ? PRODUCT_VERSION : '?') . ')',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING => '',
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $lastError = new ApiException("{$method} {$path}: network error: {$curlError}");
            } else {
                $data = json_decode($raw, true);
                if ($status >= 200 && $status < 300) {
                    if (!is_array($data)) {
                        throw new ApiException("{$method} {$path}: response is not JSON", $status);
                    }
                    if (isset($data['ok']) && $data['ok'] === false) {
                        throw new ApiException("{$method} {$path}: " . self::errorText($data, $raw), $status, $data);
                    }

                    return $data;
                }
                $lastError = new ApiException(
                    "{$method} {$path}: HTTP {$status}: " . self::errorText($data, $raw),
                    $status,
                    is_array($data) ? $data : null
                );
            }

            if (!$lastError->isTemporary() || $attempt === $this->maxAttempts) {
                break;
            }
            sleep($attempt * 2);
        }

        throw $lastError;
    }

    protected static function errorText($data, $raw)
    {
        if (is_array($data)) {
            if (!empty($data['error']['message'])) {
                return (string) $data['error']['message'];
            }
            if (!empty($data['error']) && is_string($data['error'])) {
                return $data['error'];
            }
            if (!empty($data['message'])) {
                return (string) $data['message'];
            }
        }

        return mb_substr(trim(strip_tags((string) $raw)), 0, 300);
    }

    /**
     * Splits a batch response into per-item results.
     * Items not mentioned in "results" are treated as accepted.
     *
     * @return array<string, string> external_id => error text
     */
    public static function itemErrors(array $response)
    {
        $errors = [];
        if (empty($response['results']) || !is_array($response['results'])) {
            return $errors;
        }
        foreach ($response['results'] as $result) {
            if (!isset($result['external_id'])) {
                continue;
            }
            $ok = !isset($result['status']) || in_array($result['status'], ['ok', 'created', 'updated', 'deleted'], true);
            if (!$ok) {
                $errors[(string) $result['external_id']] = isset($result['error'])
                    ? (is_string($result['error']) ? $result['error'] : json_encode($result['error']))
                    : (string) $result['status'];
            }
        }

        return $errors;
    }
}
