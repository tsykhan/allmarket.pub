<?php
// Same as content_langs() in the portal's system/global.func.php, for use outside the VuCMS core.
function content_langs()
{
    global $VCM_LANGS;
    global $CFG;
    if (is_array($VCM_LANGS) && $VCM_LANGS) {
        return $VCM_LANGS;
    }
    return array($CFG["language"] => $CFG["language"]);
}
