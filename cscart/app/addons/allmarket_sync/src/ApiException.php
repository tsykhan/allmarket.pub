<?php
/***************************************************************************
 * allmarket.biz Sync — API error.                                         *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

class ApiException extends \Exception
{
    /** @var int */
    public $httpStatus;

    /** @var array|null decoded response body */
    public $response;

    public function __construct($message, $httpStatus = 0, $response = null)
    {
        parent::__construct($message, (int) $httpStatus);
        $this->httpStatus = (int) $httpStatus;
        $this->response = $response;
    }

    /** Network errors, 429 and 5xx are worth retrying. */
    public function isTemporary()
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
