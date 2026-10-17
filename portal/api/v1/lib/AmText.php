<?php
/**
 * Text helpers. VuCMS stores plain-text fields HTML-escaped (templates print them raw), so
 * everything written to title/brand/introduce/keyword goes through plain(); descriptions
 * go through html() — a whitelist sanitizer.
 */
class AmText
{
    private static $tags = array(
        'p' => array(), 'br' => array(), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(),
        'u' => array(), 's' => array(), 'sub' => array(), 'sup' => array(), 'span' => array(), 'div' => array(),
        'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
        'ul' => array(), 'ol' => array(), 'li' => array(), 'dl' => array(), 'dt' => array(), 'dd' => array(),
        'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(),
        'th' => array('colspan', 'rowspan'), 'td' => array('colspan', 'rowspan'),
        'blockquote' => array(), 'hr' => array(), 'pre' => array(), 'code' => array(),
        'a' => array('href', 'title'), 'img' => array('src', 'alt', 'title', 'width', 'height'),
    );
    // Removed together with their content.
    private static $drop = array('script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'select', 'textarea', 'link', 'meta', 'svg', 'math', 'template', 'noscript', 'frame', 'frameset', 'applet', 'base');

    /** Removes characters outside the BMP: the portal tables are utf8 (3-byte), strict mode rejects them. */
    public static function bmp($s)
    {
        $s = (string) $s;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $s);
    }

    public static function plain($s, $max = 0)
    {
        $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', self::bmp($s));
        $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
        if ($max > 0 && mb_strlen($s, 'UTF-8') > $max) {
            $s = rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…';
        }
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Inverse of plain() for values read from portal tables. */
    public static function unescape($s)
    {
        return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function html($html)
    {
        $html = trim(self::bmp($html));
        if ($html === '') {
            return '';
        }
        if (!class_exists('DOMDocument')) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="am-root">' . $html . '</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('am-root');
        if (!$root) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }
        self::clean($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function clean(DOMNode $node)
    {
        $children = array();
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) {
                $node->removeChild($c);
                continue;
            }
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->nodeName);
            if (in_array($tag, self::$drop, true)) {
                $node->removeChild($c);
                continue;
            }
            self::clean($c);
            if (!isset(self::$tags[$tag])) {
                // Unknown tag: keep its content.
                while ($c->firstChild) {
                    $node->insertBefore($c->firstChild, $c);
                }
                $node->removeChild($c);
                continue;
            }
            $attrs = array();
            foreach ($c->attributes as $a) {
                $attrs[] = $a->nodeName;
            }
            foreach ($attrs as $name) {
                $value = $c->getAttribute($name);
                $ok = in_array(strtolower($name), self::$tags[$tag], true);
                if ($ok && ($name === 'href' || $name === 'src')) {
                    $ok = (bool) preg_match('#^(https?:)?//|^/(?!/)|^mailto:#i', trim($value));
                }
                if (!$ok) {
                    $c->removeAttribute($name);
                }
            }
            if ($tag === 'a') {
                $c->setAttribute('rel', 'nofollow noopener');
                $c->setAttribute('target', '_blank');
            }
        }
    }

    /** Latin slug for linkurl ("show/12-slug.html"); the rewrite rule only uses the number. */
    public static function slug($s)
    {
        static $map = array(
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh',
            'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia', 'ё' => 'e', 'ы' => 'y',
            'э' => 'e', 'ъ' => '', '’' => '', "'" => '',
        );
        $s = mb_strtolower(self::unescape(strip_tags($s)), 'UTF-8');
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim(substr($s, 0, 60), '_');
        return $s !== '' ? $s : 'item';
    }

    /** "Іван Петренко" -> ["Іван", "Петренко"]. */
    public static function splitName($name)
    {
        $parts = preg_split('/\s+/u', trim((string) $name), 2);
        return array(isset($parts[0]) ? $parts[0] : '', isset($parts[1]) ? $parts[1] : '');
    }
}
