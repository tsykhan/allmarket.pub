<?php
/**
 * Orders: all_multitrade_order rows (one row = one product line) where seller = the API seller.
 * API order id = "AM-<all_multitrade_order.itemid>".
 *
 * Portal statuses (lang/ua-ua/multitrade.inc.php, multitrade_status):
 *  0 placed, waiting for seller   1 seller confirmed, waiting for payment   2 paid, waiting for delivery
 *  3 delivered, waiting for buyer 4 completed   5 refund requested   6 refunded to buyer
 *  7 paid out to seller   8 closed by buyer   9 closed by seller   10 waiting for payment confirmation
 */
class AmOrders
{
    private static $toApi = array(
        0 => 'new', 1 => 'accepted', 2 => 'processing', 3 => 'shipped', 4 => 'delivered',
        5 => 'returned', 6 => 'returned', 7 => 'delivered', 8 => 'cancelled', 9 => 'cancelled', 10 => 'accepted',
    );
    private static $paid = array(2, 3, 4, 5, 7);

    private $db;
    private $cfg;
    private $seller;

    public function __construct(AmDb $db, array $cfg, array $seller)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->seller = $seller;
    }

    /** GET /orders: not acknowledged yet OR changed since updated_since. */
    public function listOrders($since, $page, $limit)
    {
        $serverTime = gmdate('Y-m-d\TH:i:s\Z');
        $page = max(1, (int) $page);
        $limit = max(1, min((int) $this->cfg['orders_max_limit'], (int) $limit ?: 50));
        $sinceTs = $since !== '' && $since !== null ? strtotime($since) : false;

        $where = 'o.seller=? AND o.addtime>=?';
        $params = array($this->seller['username'], (int) $this->seller['orders_from']);
        if ($sinceTs) {
            $where .= ' AND (a.acked IS NULL OR a.acked=0 OR o.updatetime>=? OR o.addtime>=?)';
            $params[] = $sinceTs;
            $params[] = $sinceTs;
        } else {
            $where .= ' AND (a.acked IS NULL OR a.acked=0)';
        }
        $params[] = ($page - 1) * $limit;
        $params[] = $limit + 1;
        $rows = $this->db->all('SELECT o.*, a.acked FROM #__multitrade_order o
            LEFT JOIN #__amapi_order a ON a.orderid=o.itemid
            WHERE ' . $where . ' ORDER BY o.itemid LIMIT ?, ?', $params);
        $hasMore = count($rows) > $limit;
        $orders = array();
        foreach (array_slice($rows, 0, $limit) as $r) {
            $orders[] = $this->format($r);
        }
        return array('ok' => true, 'server_time' => $serverTime, 'has_more' => $hasMore, 'orders' => $orders);
    }

    public function ack($id, array $body)
    {
        $o = $this->find($id);
        if (!$o) {
            return null;
        }
        $this->state((int) $o['itemid'], array(
            'acked' => 1, 'acked_at' => time(), 'webhook_error' => '',
            'external_order_id' => self::str(isset($body['external_order_id']) ? $body['external_order_id'] : '', 64),
            'reference' => self::str(isset($body['reference']) ? $body['reference'] : '', 64),
        ));
        return array('ok' => true);
    }

    /**
     * POST /orders/{id}/status. Only transitions that do not move money are applied:
     * accepted (0→1), cancelled (0/1→9, stock returned), shipped (2→3 + tracking).
     * Anything else is recorded and answered with applied=false, so the shop does not retry forever.
     */
    public function status($id, array $body)
    {
        $o = $this->find($id);
        if (!$o) {
            return null;
        }
        $want = isset($body['status']) ? (string) $body['status'] : '';
        $cur = (int) $o['status'];
        $tracking = self::str(isset($body['tracking_number']) ? $body['tracking_number'] : '', 50);
        $carrier = self::str(isset($body['carrier']) ? $body['carrier'] : '', 50);
        $now = time();
        $set = array();
        $note = '';

        if ($want === 'accepted' && $cur === 0) {
            $set['status'] = 1;
        } elseif ($want === 'cancelled' && in_array($cur, array(0, 1), true)) {
            $set['status'] = 9;
            // Same as the portal does when a deal is closed: return the stock, drop the order counter.
            $this->db->exec('UPDATE #__multitrade SET amount=amount+?, orders=IF(orders>=?, orders-?, 0) WHERE itemid=?',
                array((int) $o['number'], (int) $o['number'], (int) $o['number'], (int) $o['multitradeid']));
        } elseif ($want === 'shipped' && $cur === 2) {
            $set['status'] = 3;
        } elseif (isset(self::$toApi[$cur]) && self::$toApi[$cur] === $want) {
            $note = 'already in this status';
        } else {
            $note = 'transition ' . self::$toApi[$cur] . ' -> ' . $want . ' is not applied automatically (portal status ' . $cur . ')';
        }
        if ($tracking !== '' && ($want === 'shipped' || $o['send_no'] === '')) {
            $set['send_no'] = $tracking;
            if ($carrier !== '') {
                $set['send_type'] = $carrier;
            }
            if ($o['send_time'] === '') {
                $ts = !empty($body['changed_at']) ? strtotime($body['changed_at']) : false;
                $set['send_time'] = date('Y-m-d', $ts ?: $now);
            }
        }
        if ($set) {
            $set['updatetime'] = $now;
            $set['editor'] = $this->seller['username'];
            $this->db->updateRow('multitrade_order', $set, 'itemid=?', array((int) $o['itemid']));
        }
        $this->state((int) $o['itemid'], array(
            'shop_status' => self::str($want, 32), 'shop_status_at' => $now, 'tracking_number' => $tracking,
            'external_order_id' => self::str(isset($body['external_order_id']) ? $body['external_order_id'] : '', 64),
            'reference' => self::str(isset($body['reference']) ? $body['reference'] : '', 64),
        ), true);
        $applied = isset($set['status']);
        $out = array('ok' => true, 'applied' => $applied, 'status' => self::$toApi[$applied ? $set['status'] : $cur]);
        if ($note !== '') {
            $out['message'] = $note;
        }
        return $out;
    }

    /** Order in the API format (also used for the outgoing webhook). */
    public function format(array $o)
    {
        $map = $this->db->one('SELECT external_id, sku FROM #__amapi_item WHERE userid=? AND itemid=?',
            array((int) $this->seller['userid'], (int) $o['multitradeid']));
        $buyer = $this->db->one('SELECT truename, email, mobile FROM #__member WHERE username=?', array($o['buyer']));
        $status = (int) $o['status'];
        list($first, $last) = AmText::splitName(AmText::unescape($o['buyer_name'] !== '' ? $o['buyer_name'] : ($buyer ? $buyer['truename'] : $o['buyer'])));
        $phone = $o['buyer_mobile'] !== '' ? $o['buyer_mobile'] : ($o['buyer_phone'] !== '' ? $o['buyer_phone'] : ($buyer ? $buyer['mobile'] : ''));
        $number = max(1, (int) $o['number']);
        $lineTotal = (float) $o['amount'];
        $feeName = AmText::unescape($o['fee_name']);
        $paysys = AmText::unescape($o['paysys']);
        $comment = array();
        if ($o['note'] !== '') {
            $comment[] = AmText::unescape($o['note']);
        }
        if ($o['options'] !== '') {
            $comment[] = AmText::unescape($o['options']);
        }
        $updated = max((int) $o['addtime'], (int) $o['updatetime']);

        return array(
            'id' => 'AM-' . $o['itemid'],
            'number' => (string) $o['itemid'],
            'status' => isset(self::$toApi[$status]) ? self::$toApi[$status] : 'new',
            'portal_status' => $status,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $o['addtime']),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $updated),
            'currency' => AmCurrency::base($this->db, $this->cfg, $this->seller['member']),
            'customer' => array(
                'first_name' => $first,
                'last_name' => $last,
                'email' => $buyer && $buyer['email'] !== '' ? $buyer['email'] : '',
                'phone' => $phone,
                'username' => $o['buyer'],
            ),
            'delivery' => array(
                'method' => self::slugMethod($feeName),
                'method_name' => $feeName,
                'country' => 'UA',
                'region' => '',
                'city' => '',
                'warehouse' => '',
                'address' => AmText::unescape($o['buyer_address']),
                'postcode' => $o['buyer_postcode'],
                'recipient_first_name' => $first,
                'recipient_last_name' => $last,
                'cost' => (float) $o['fee'],
                'tracking_number' => $o['send_no'],
            ),
            'payment' => array(
                'method' => self::slugPayment($paysys),
                'method_name' => $paysys,
                'paid' => in_array($status, self::$paid, true),
            ),
            'comment' => implode("\n", $comment),
            'items' => array(array(
                'external_id' => $map ? $map['external_id'] : '',
                'sku' => $map ? $map['sku'] : '',
                'name' => AmText::unescape($o['title']),
                'price' => round($lineTotal / $number, 2),
                'quantity' => $number,
                'portal_item_id' => (int) $o['multitradeid'],
            )),
            'total' => round($lineTotal + (float) $o['fee'], 2),
        );
    }

    /** Order row by "AM-123" / "123", only if it belongs to this seller and is in the API window. */
    public function find($id)
    {
        $id = preg_replace('/^AM-/i', '', trim((string) $id));
        if (!ctype_digit($id)) {
            return null;
        }
        return $this->db->one('SELECT * FROM #__multitrade_order WHERE itemid=? AND seller=? AND addtime>=?',
            array((int) $id, $this->seller['username'], (int) $this->seller['orders_from']));
    }

    public function state($orderid, array $fields, $keepEmpty = false)
    {
        if ($keepEmpty) {
            foreach (array('external_order_id', 'reference', 'tracking_number') as $k) {
                if (isset($fields[$k]) && $fields[$k] === '') {
                    unset($fields[$k]);
                }
            }
        }
        $exists = $this->db->value('SELECT orderid FROM #__amapi_order WHERE orderid=?', array($orderid));
        if ($exists) {
            $this->db->updateRow('amapi_order', $fields, 'orderid=?', array($orderid));
        } else {
            $this->db->insertRow('amapi_order', $fields + array('orderid' => $orderid, 'userid' => (int) $this->seller['userid']));
        }
    }

    private static function slugMethod($name)
    {
        $n = mb_strtolower($name, 'UTF-8');
        if (preg_match('/нова\s*пошта|новая\s*почта|nova\s*poshta/u', $n)) {
            return 'nova_poshta';
        }
        if (preg_match('/укрпошт|укрпочт|ukrposhta/u', $n)) {
            return 'ukrposhta';
        }
        if (preg_match('/meest|міст/u', $n)) {
            return 'meest';
        }
        if (preg_match('/самовив|самовыв|pickup/u', $n)) {
            return 'pickup';
        }
        if (preg_match('/кур.?єр|курьер|courier/u', $n)) {
            return 'courier';
        }
        return $n === '' ? '' : 'other';
    }

    private static function slugPayment($name)
    {
        $n = mb_strtolower($name, 'UTF-8');
        if (preg_match('/налож|наклад|cod|при отриманн|при получен/u', $n)) {
            return 'cod';
        }
        if ($n === '') {
            return '';
        }
        $s = preg_replace('/[^a-z0-9]+/', '_', AmText::slug($n));
        return trim($s, '_') ?: 'other';
    }

    private static function str($v, $max)
    {
        return mb_substr(trim((string) $v), 0, $max, 'UTF-8');
    }
}
