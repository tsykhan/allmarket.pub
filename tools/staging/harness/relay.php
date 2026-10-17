<?php
// Staging-only: makes http://localhost:8088 inside amportal-web reach the PrestaShop container
// with the Host header PrestaShop expects (it redirects any other host).
//   docker exec -d amportal-web php -S 127.0.0.1:8088 /var/www/html/staging/relay.php
$target = getenv('RELAY_TARGET') ?: 'http://amtest-ps';
$headers = array('Host: localhost:8088');
foreach (getallheaders() as $k => $v) {
    if (!in_array(strtolower($k), array('host', 'content-length', 'connection'), true)) {
        $headers[] = $k . ': ' . $v;
    }
}
$ch = curl_init($target . $_SERVER['REQUEST_URI']);
curl_setopt_array($ch, array(
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
    CURLOPT_POSTFIELDS => file_get_contents('php://input'),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
http_response_code($code);
foreach (explode("\r\n", substr($resp, 0, $hsize)) as $h) {
    if (stripos($h, 'content-type:') === 0) {
        header($h);
    }
}
echo substr($resp, $hsize);
