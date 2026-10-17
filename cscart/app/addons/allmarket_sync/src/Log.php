<?php
/***************************************************************************
 * allmarket.biz Sync — exchange log (table ?:allmarket_sync_log).         *
 ***************************************************************************/

namespace Tygh\Addons\AllmarketSync;

class Log
{
    const KEEP_ROWS = 5000;

    /** CLI runs echo log lines to STDERR when set. */
    public static $echo = false;

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
        $message = html_entity_decode((string) $message, ENT_QUOTES, 'UTF-8');
        // utf8 (3-byte) tables: drop emoji and other 4-byte characters.
        $message = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $message);
        if (self::$echo && defined('STDERR')) {
            fwrite(STDERR, '[' . date('H:i:s') . "] {$level} {$task}: {$message}\n");
        }

        db_query('INSERT INTO ?:allmarket_sync_log ?e', [
            'company_id' => Config::companyId(),
            'level' => $level,
            'task' => mb_substr((string) $task, 0, 32),
            'message' => mb_substr($message, 0, 4000),
            'timestamp' => TIME,
        ]);

        // Trim roughly once per 100 writes.
        if (mt_rand(1, 100) === 1) {
            $minId = (int) db_get_field(
                'SELECT log_id FROM ?:allmarket_sync_log ORDER BY log_id DESC LIMIT ?i, 1',
                self::KEEP_ROWS
            );
            if ($minId) {
                db_query('DELETE FROM ?:allmarket_sync_log WHERE log_id <= ?i', $minId);
            }
        }
    }

    public static function latest($limit = 100, $level = '')
    {
        $condition = db_quote('company_id = ?i', Config::companyId());
        if ($level !== '') {
            $condition .= db_quote(' AND level = ?s', $level);
        }

        return db_get_array('SELECT * FROM ?:allmarket_sync_log WHERE ?p ORDER BY log_id DESC LIMIT ?i', $condition, (int) $limit);
    }

    public static function clear()
    {
        db_query('DELETE FROM ?:allmarket_sync_log WHERE company_id = ?i', Config::companyId());
    }
}
