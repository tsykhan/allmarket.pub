<?php
/**
 * Exchange log stored in the allmarket_log table.
 *
 * @author    allmarket.biz
 * @copyright 2026 allmarket.biz
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AllmarketLog
{
    const KEEP_ROWS = 5000;

    public static function info($task, $message)
    {
        self::write('info', $task, $message);
    }

    public static function warning($task, $message)
    {
        self::write('warning', $task, $message);
    }

    public static function error($task, $message)
    {
        self::write('error', $task, $message);
    }

    protected static function write($level, $task, $message)
    {
        // PrestaShop exceptions arrive HTML-escaped; the log view escapes on output.
        $message = html_entity_decode((string) $message, ENT_QUOTES, 'UTF-8');
        if (PHP_SAPI === 'cli' && defined('ALLMARKET_CLI_VERBOSE')) {
            fwrite(STDERR, '[' . date('H:i:s') . "] {$level} {$task}: {$message}\n");
        }

        Db::getInstance()->insert('allmarket_log', [
            'level' => pSQL($level),
            'task' => pSQL(Tools::substr($task, 0, 32)),
            'message' => pSQL(Tools::substr($message, 0, 4000)),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        // Trim roughly once per 100 writes.
        if (mt_rand(1, 100) === 1) {
            $p = _DB_PREFIX_;
            $minId = (int) Db::getInstance()->getValue(
                "SELECT `id_allmarket_log` FROM `{$p}allmarket_log` ORDER BY `id_allmarket_log` DESC LIMIT " . self::KEEP_ROWS . ', 1'
            );
            if ($minId) {
                Db::getInstance()->delete('allmarket_log', '`id_allmarket_log` <= ' . $minId);
            }
        }
    }

    public static function latest($limit = 100)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'allmarket_log` ORDER BY `id_allmarket_log` DESC LIMIT ' . (int) $limit
        ) ?: [];
    }

    public static function clear()
    {
        return Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'allmarket_log`');
    }
}
