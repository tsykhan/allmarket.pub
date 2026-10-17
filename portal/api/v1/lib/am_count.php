<?php
// count() that tolerates non-arrays, for the portal's tree.class.php outside the VuCMS core.
function am_count($a)
{
    return is_array($a) || $a instanceof Countable ? count($a) : 0;
}
