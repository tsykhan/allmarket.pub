<?php
/**
 * Error returned by the allmarket.biz API.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketApiException extends Exception
{
    /** @var int HTTP status, 0 for network errors */
    public $httpStatus;

    /** @var array|null decoded response body */
    public $response;

    public function __construct($message, $httpStatus = 0, $response = null)
    {
        parent::__construct($message, (int) $httpStatus);
        $this->httpStatus = (int) $httpStatus;
        $this->response = $response;
    }

    /** True for errors worth retrying later (network, 429, 5xx). */
    public function isTemporary()
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
