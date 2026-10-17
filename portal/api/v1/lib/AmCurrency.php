<?php
/**
 * Prices on the portal are kept in the seller's accounting currency: company.currency
 * (Кабінет → Валюти), or the portal default (all_setting money_unit_rate, USD) when it is empty —
 * the same rule as am_user_currency() in the core. Shop prices in another currency are converted
 * with the seller's manual rate (company.fx_manual) or the portal's market rates (all_fx, units per 1 USD).
 */
class AmCurrency
{
    /** Accounting currency of the seller. */
    public static function base(AmDb $db, array $cfg, array $member)
    {
        $c = isset($member['c_currency']) ? strtoupper(trim((string) $member['c_currency'])) : '';
        if (preg_match('/^[A-Z]{3}$/', $c)) {
            return $c;
        }
        static $portal = null;
        if ($portal === null) {
            $portal = '';
            try {
                $portal = strtoupper((string) $db->value("SELECT item_value FROM #__setting WHERE item_key='money_unit_rate' LIMIT 1"));
            } catch (Exception $e) {
                $portal = '';
            }
            if (!preg_match('/^[A-Z]{3}$/', $portal)) {
                $portal = !empty($cfg['portal_currency']) ? $cfg['portal_currency'] : 'USD';
            }
        }
        return $portal;
    }

    /**
     * Converts an amount from $from into the seller's accounting currency.
     * @throws InvalidArgumentException when no rate is known
     */
    public static function toBase(AmDb $db, array $cfg, array $member, $amount, $from)
    {
        $base = self::base($db, $cfg, $member);
        $from = strtoupper(trim((string) $from));
        if ($from === '' || $from === $base) {
            return round((float) $amount, 2);
        }
        $rate = self::rate($db, $member, $base, $from);
        if (!$rate) {
            throw new InvalidArgumentException('currency ' . $from . ' cannot be converted to ' . $base . ' (no rate on the portal)');
        }
        return round((float) $amount / $rate, 2);
    }

    /** Units of $target in 1 unit of $base (like am_user_rate() in the core). */
    public static function rate(AmDb $db, array $member, $base, $target)
    {
        if ($target === $base) {
            return 1.0;
        }
        $man = !empty($member['c_fx_manual']) ? json_decode($member['c_fx_manual'], true) : array();
        if (is_array($man) && isset($man[$target]['mode'], $man[$target]['rate']) && $man[$target]['mode'] === 'manual' && $man[$target]['rate'] > 0) {
            return (float) $man[$target]['rate'];
        }
        $ut = self::perUsd($db, $target);
        $ub = self::perUsd($db, $base);
        return $ut > 0 && $ub > 0 ? $ut / $ub : null;
    }

    private static function perUsd(AmDb $db, $c)
    {
        static $cache = array();
        if ($c === 'USD') {
            return 1.0;
        }
        if (!array_key_exists($c, $cache)) {
            $cache[$c] = null;
            if ($db->tableExists('fx')) {
                $v = $db->value('SELECT usd FROM #__fx WHERE currency=?', array($c));
                $cache[$c] = $v > 0 ? (float) $v : null;
            }
        }
        return $cache[$c];
    }
}
