<?php
// Loaded by run.php. How a derived site's own pages are asked for over loopback (JoomlaDerivedRows::fetch).
require_once __DIR__ . '/../lib/LoopbackRoute.php';
echo "\nLoopback route\n";
check('loopback: an https site is asked over plain http first, then https resolved to 127.0.0.1',
    LoopbackRoute::routes('https://northwind.example/'),
    [['url' => 'http://127.0.0.1/', 'host' => 'northwind.example', 'resolve' => null],
     ['url' => 'https://northwind.example/', 'host' => 'northwind.example', 'resolve' => 'northwind.example:443:127.0.0.1']]);
check('loopback: an http site has one route, its port and path kept',
    LoopbackRoute::routes('http://northwind.example:8080/shop/'),
    [['url' => 'http://127.0.0.1:8080/shop/', 'host' => 'northwind.example:8080', 'resolve' => null]]);
check('loopback: an https port names the resolved address, plain http stays on 80',
    LoopbackRoute::routes('https://northwind.example:8443/shop'),
    [['url' => 'http://127.0.0.1/shop/', 'host' => 'northwind.example', 'resolve' => null],
     ['url' => 'https://northwind.example:8443/shop/', 'host' => 'northwind.example:8443', 'resolve' => 'northwind.example:8443:127.0.0.1']]);
check('loopback: 200 is a page, a redirect or no answer tries the next route, anything else is no page',
    array_map([LoopbackRoute::class, 'outcome'], [200, 301, 302, 308, 0, 404, 500]), ['page', 'next', 'next', 'next', 'next', 'none', 'none']);
// A proxy (Joomla's proxy_enable, or http_proxy/https_proxy in the environment) would carry the call
// to the customer's real host: every route switches it off, and the https one resolves to loopback.
$lrRoutes = LoopbackRoute::routes('https://northwind.example/');
check('loopback: the plain http route goes through no proxy', LoopbackRoute::curlOptions($lrRoutes[0]), [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
check('loopback: the https route goes through no proxy, to 127.0.0.1, without the certificate check',
    LoopbackRoute::curlOptions($lrRoutes[1]),
    [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_RESOLVE => ['northwind.example:443:127.0.0.1'], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
check('loopback: without curl there is no route at all (streams honour the proxy)', LoopbackRoute::routes('http://northwind.example/', false), []);
check('loopback: nor for an https site', LoopbackRoute::routes('https://northwind.example/', false), []);
check('loopback: a curl without TLS asks an https site over plain http only',
    LoopbackRoute::routes('https://northwind.example/', true, false), [['url' => 'http://127.0.0.1/', 'host' => 'northwind.example', 'resolve' => null]]);
check('loopback: whether curl can speak TLS is a yes or a no', is_bool(LoopbackRoute::tls()), true);
