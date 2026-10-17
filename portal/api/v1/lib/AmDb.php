<?php
/**
 * Thin mysqli wrapper. `#__` in SQL is replaced with the table prefix, `?` with quoted values
 * (int/float as numbers, null as NULL, everything else as an escaped string).
 * Client-side quoting keeps it independent of mysqlnd (no get_result()).
 */
class AmDb
{
    public $pre;
    /** @var mysqli */
    private $m;
    private $columns = array();

    public function __construct(array $c)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $host = $c['host'];
        $port = 3306;
        if (preg_match('/^(.+):(\d+)$/', $host, $mm)) {
            $host = $mm[1];
            $port = (int) $mm[2];
        }
        $this->m = new mysqli($host, $c['user'], $c['pass'], $c['name'], $port);
        $this->m->set_charset(!empty($c['charset']) ? $c['charset'] : 'utf8');
        $this->pre = $c['prefix'];
    }

    public static function fromPortal($root, array $override = array())
    {
        return new self(self::portalConfig($root, $override));
    }

    /** Connection settings from the portal's dbvucmsconf.inc.php (same keys the VuCMS core uses). */
    public static function portalConfig($root, array $override = array())
    {
        $CFG = array();
        if (!defined('IN_VUCMS')) {
            define('IN_VUCMS', true);
        }
        $file = $root . '/dbvucmsconf.inc.php';
        if (is_file($file)) {
            include $file;
        }
        return array_merge(array(
            'host' => isset($CFG['db_host']) ? $CFG['db_host'] : 'localhost',
            'user' => isset($CFG['db_user']) ? $CFG['db_user'] : '',
            'pass' => isset($CFG['db_pass']) ? $CFG['db_pass'] : '',
            'name' => isset($CFG['db_name']) ? $CFG['db_name'] : '',
            'charset' => isset($CFG['db_charset']) ? $CFG['db_charset'] : 'utf8',
            'prefix' => isset($CFG['tb_pre']) ? $CFG['tb_pre'] : 'all_',
        ), $override);
    }

    public function quote($v)
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (string) $v : '0';
        }
        return "'" . $this->m->real_escape_string((string) $v) . "'";
    }

    public function sql($sql, array $p = array())
    {
        $sql = str_replace('#__', $this->pre, $sql);
        if (!$p) {
            return $sql;
        }
        $i = 0;
        $self = $this;
        return preg_replace_callback('/\?/', function () use (&$i, $p, $self) {
            if (!array_key_exists($i, $p)) {
                throw new RuntimeException('Not enough SQL parameters');
            }
            return $self->quote($p[$i++]);
        }, $sql);
    }

    /** @return mysqli_result|bool */
    public function query($sql, array $p = array())
    {
        return $this->m->query($this->sql($sql, $p));
    }

    public function all($sql, array $p = array())
    {
        $r = $this->query($sql, $p);
        $out = array();
        while ($row = $r->fetch_assoc()) {
            $out[] = $row;
        }
        $r->free();
        return $out;
    }

    public function one($sql, array $p = array())
    {
        $r = $this->query($sql, $p);
        $row = $r->fetch_assoc();
        $r->free();
        return $row ?: null;
    }

    public function value($sql, array $p = array())
    {
        $row = $this->one($sql, $p);
        return $row ? reset($row) : null;
    }

    public function exec($sql, array $p = array())
    {
        $this->query($sql, $p);
        return $this->m->affected_rows;
    }

    public function insertId()
    {
        return (int) $this->m->insert_id;
    }

    /** Column definitions of a table: name => [type, null, default, extra]. Cached per request. */
    public function columns($table)
    {
        if (!isset($this->columns[$table])) {
            $cols = array();
            foreach ($this->all('SHOW COLUMNS FROM `#__' . $table . '`') as $c) {
                $cols[$c['Field']] = $c;
            }
            $this->columns[$table] = $cols;
        }
        return $this->columns[$table];
    }

    public function tableExists($table)
    {
        return (bool) $this->one('SHOW TABLES LIKE ?', array($this->pre . $table));
    }

    /**
     * INSERT/UPDATE only the columns that exist in the table, so the code survives schema drift
     * between portal versions. NOT NULL columns without a default get an empty value on insert
     * (MySQL strict mode refuses the row otherwise).
     */
    public function insertRow($table, array $data)
    {
        $cols = $this->columns($table);
        $row = array();
        foreach ($cols as $name => $c) {
            if (array_key_exists($name, $data)) {
                $row[$name] = $data[$name];
            } elseif ($c['Null'] === 'NO' && $c['Default'] === null && strpos($c['Extra'], 'auto_increment') === false) {
                $row[$name] = self::emptyValue($c['Type']);
            }
        }
        $names = array();
        $vals = array();
        foreach ($row as $k => $v) {
            $names[] = '`' . $k . '`';
            $vals[] = $this->quote(self::fit($cols[$k]['Type'], $v));
        }
        $this->query('INSERT INTO `#__' . $table . '` (' . implode(',', $names) . ') VALUES (' . implode(',', $vals) . ')');
        return $this->insertId();
    }

    public function updateRow($table, array $data, $where, array $p = array())
    {
        $cols = $this->columns($table);
        $set = array();
        foreach ($data as $k => $v) {
            if (isset($cols[$k])) {
                $set[] = '`' . $k . '`=' . $this->quote(self::fit($cols[$k]['Type'], $v));
            }
        }
        if (!$set) {
            return 0;
        }
        // Bind the WHERE parameters on their own: the SET values are already quoted and may contain "?".
        return $this->exec('UPDATE `#__' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $this->sql($where, $p));
    }

    private static function emptyValue($type)
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/', $type)) {
            return 0;
        }
        if (strpos($type, 'datetime') === 0) {
            return '1970-01-01 00:00:00';
        }
        if (strpos($type, 'date') === 0) {
            return '1970-01-01';
        }
        return '';
    }

    /** Truncates strings to varchar(N) (by characters) so strict mode does not reject the row. */
    private static function fit($type, $v)
    {
        if (is_string($v) && preg_match('/^(?:var)?char\((\d+)\)/', $type, $m) && mb_strlen($v, 'UTF-8') > (int) $m[1]) {
            $v = mb_substr($v, 0, (int) $m[1], 'UTF-8');
            // Do not leave half of an HTML entity at the end.
            $v = preg_replace('/&[#a-z0-9]{0,8}$/i', '', $v);
        }
        return $v;
    }
}
