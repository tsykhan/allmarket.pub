<?php
/**
 * Outgoing webhook portal → shop (docs/API.md, «Вебхук»): POST JSON signed with
 * X-Allmarket-Signature: sha256=<hex HMAC-SHA256 of the raw body with the seller's secret>.
 */
class AmWebhook
{
    /** @return array [http code, decoded body|null, error string] */
    public static function send($url, $secret, array $event, $timeout = 10)
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = array(
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: allmarket.biz-webhook/1.0',
            'X-Allmarket-Signature: sha256=' . hash_hmac('sha256', $body, (string) $secret),
        );
        $code = 0;
        $resp = '';
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ));
            $resp = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resp === '' && curl_errno($ch)) {
                $err = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(array('http' => array(
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
                'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
            )));
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                $err = 'connection failed';
                $resp = '';
            }
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }
        $data = $resp !== '' ? json_decode($resp, true) : null;
        if (!$err && ($code < 200 || $code >= 300)) {
            $msg = is_array($data) && isset($data['error']) ? (is_array($data['error']) ? json_encode($data['error'], JSON_UNESCAPED_UNICODE) : $data['error']) : substr(strip_tags($resp), 0, 150);
            $err = 'HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '');
        }
        return array($code, is_array($data) ? $data : null, $err);
    }

    /** Only public http(s) URLs: the portal must not be usable to probe its own network. */
    public static function validUrl($url)
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower(isset($p['scheme']) ? $p['scheme'] : ''), array('http', 'https'), true)) {
            return false;
        }
        $host = $p['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (array) @gethostbynamel($host);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return getenv('AMAPI_ALLOW_PRIVATE_WEBHOOKS') ? true : false;
            }
        }
        return true;
    }

    /**
     * Delivers new/changed orders of every seller with a webhook URL. Run from cron.php.
     * @return array per-seller stats
     */
    public static function dispatch(AmDb $db, array $cfg, $limitPerSeller = 50)
    {
        $out = array();
        $sellers = $db->all("SELECT * FROM #__amapi_seller WHERE enabled=1 AND webhook_url<>'' AND key_hash<>''");
        foreach ($sellers as $s) {
            $member = AmAuth::member($db, $s['userid']);
            if (!$member || in_array((int) $member['groupid'], $cfg['denied_groups'], true)) {
                continue;
            }
            $s['member'] = $member;
            $s['username'] = $member['username'];
            $orders = new AmOrders($db, $cfg, $s);
            $stats = array('sent' => 0, 'ok' => 0, 'failed' => 0);
            if (!self::validUrl($s['webhook_url'])) {
                $db->exec('UPDATE #__amapi_seller SET webhook_status=? WHERE userid=?', array(date('Y-m-d H:i') . ' invalid URL', (int) $s['userid']));
                continue;
            }
            $rows = $db->all('SELECT o.*, a.acked, a.webhook_hash, a.webhook_tries FROM #__multitrade_order o
                LEFT JOIN #__amapi_order a ON a.orderid=o.itemid
                WHERE o.seller=? AND o.addtime>=? AND (a.orderid IS NULL OR a.acked=0 OR a.webhook_at < GREATEST(o.addtime, o.updatetime))
                ORDER BY o.itemid LIMIT ' . (int) $limitPerSeller, array($s['username'], (int) $s['orders_from']));
            $last = '';
            foreach ($rows as $r) {
                $order = $orders->format($r);
                $hash = md5(json_encode($order));
                if ($r['webhook_hash'] === $hash && $r['acked']) {
                    continue;
                }
                if ($r['webhook_hash'] === $hash && (int) $r['webhook_tries'] >= (int) $cfg['webhook_max_tries']) {
                    continue;
                }
                $event = array('event' => $r['acked'] ? 'order.updated' : 'order.created', 'order' => $order);
                list($code, $data, $err) = self::send($s['webhook_url'], $s['webhook_secret'], $event, (int) $cfg['webhook_timeout']);
                ++$stats['sent'];
                $now = time();
                if (!$err && is_array($data) && !empty($data['ok'])) {
                    ++$stats['ok'];
                    $fields = array('webhook_hash' => $hash, 'webhook_at' => $now, 'webhook_tries' => 0, 'webhook_error' => '');
                    if (isset($data['result']) && in_array($data['result'], array('imported', 'skipped', 'updated'), true)) {
                        $fields['acked'] = 1;
                        $fields['acked_at'] = $now;
                        if (!empty($data['external_order_id'])) {
                            $fields['external_order_id'] = mb_substr((string) $data['external_order_id'], 0, 64, 'UTF-8');
                        }
                        if (!empty($data['reference'])) {
                            $fields['reference'] = mb_substr((string) $data['reference'], 0, 64, 'UTF-8');
                        }
                    }
                    $orders->state((int) $r['itemid'], $fields);
                    $last = 'OK';
                } else {
                    ++$stats['failed'];
                    $tries = $r['webhook_hash'] === $hash ? (int) $r['webhook_tries'] + 1 : 1;
                    // webhook_at stays old so the order is picked up again on the next run.
                    $orders->state((int) $r['itemid'], array('webhook_hash' => $hash, 'webhook_tries' => $tries,
                        'webhook_error' => mb_substr($err ?: 'unexpected response', 0, 255, 'UTF-8')));
                    $last = $err ?: 'unexpected response';
                }
            }
            if ($last !== '') {
                $db->exec('UPDATE #__amapi_seller SET webhook_status=? WHERE userid=?', array(mb_substr(date('Y-m-d H:i') . ' ' . $last, 0, 255, 'UTF-8'), (int) $s['userid']));
            }
            $out[$s['username']] = $stats;
        }
        return $out;
    }
}
