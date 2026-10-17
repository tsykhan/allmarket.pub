<?php
/**
 * Seller API keys. The key is shown to the seller once; only its SHA-256 is stored.
 * A request is accepted when the key matches and X-Allmarket-Shop is the seller's userid
 * (or username).
 */
class AmAuth
{
    const PREFIX = 'am_';

    public static function newKey()
    {
        return self::PREFIX . bin2hex(random_bytes(20));
    }

    public static function hash($key)
    {
        return hash('sha256', (string) $key);
    }

    /**
     * @return array|null seller: amapi_seller row + member/company fields (userid, username, groupid, company…)
     */
    public static function seller(AmDb $db, array $cfg, $key, $shop)
    {
        $key = trim((string) $key);
        if ($key === '' || strpos($key, self::PREFIX) !== 0) {
            return null;
        }
        $s = $db->one('SELECT * FROM #__amapi_seller WHERE key_hash=?', array(self::hash($key)));
        if (!$s || !hash_equals($s['key_hash'], self::hash($key))) {
            return null;
        }
        $shop = trim((string) $shop);
        if ($shop !== '' && $shop !== (string) $s['userid'] && strcasecmp($shop, $s['username']) !== 0) {
            return null;
        }
        $m = self::member($db, $s['userid']);
        if (!$m) {
            return null;
        }
        $s['member'] = $m;
        $s['username'] = $m['username'];
        $s['blocked'] = !$s['enabled'] || in_array((int) $m['groupid'], $cfg['denied_groups'], true);
        return $s;
    }

    /** Member + company row merged (company fields prefixed with c_). */
    public static function member(AmDb $db, $userid)
    {
        $m = $db->one('SELECT * FROM #__member WHERE userid=?', array((int) $userid));
        if (!$m) {
            return null;
        }
        $c = $db->one('SELECT * FROM #__company WHERE userid=?', array((int) $userid));
        if ($c) {
            foreach ($c as $k => $v) {
                $m['c_' . $k] = $v;
            }
        }
        return $m;
    }

    /** Creates or rotates the key; returns the plain key (to be shown once). */
    public static function issue(AmDb $db, $userid, $username)
    {
        $key = self::newKey();
        $now = time();
        $row = $db->one('SELECT userid, orders_from FROM #__amapi_seller WHERE userid=?', array((int) $userid));
        if ($row) {
            $db->exec('UPDATE #__amapi_seller SET key_hash=?, key_hint=?, key_created=?, username=? WHERE userid=?',
                array(self::hash($key), substr($key, 0, 7) . '…' . substr($key, -4), $now, $username, (int) $userid));
        } else {
            // Orders placed before the first key are not offered for import.
            $db->query('INSERT INTO #__amapi_seller (userid, username, key_hash, key_hint, enabled, orders_from, created, key_created)
                VALUES (?,?,?,?,1,?,?,?)', array((int) $userid, $username, self::hash($key),
                substr($key, 0, 7) . '…' . substr($key, -4), $now, $now, $now));
        }
        return $key;
    }
}
