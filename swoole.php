<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use OCP\Security\ISecureRandom;

try {
    include __DIR__ . "/lib/context.php";
    include __DIR__ . "/lib/base.php";
} catch (\Exception $e) {
    print("swoole-error: ". $e->getMessage() . PHP_EOL);
    debug_print_backtrace();
}

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$http = new \Swoole\Http\Server('127.0.0.1', 9501);
$http->set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    'enable_coroutine' => true,
    'document_root' => __DIR__,
    'enable_static_handler' => true,
]);

$http->on('request', function ($request, $response) {
    // $response->end('<pre>' . json_encode($request));

    ContextManager::set('__id', Co::getCid());

    ContextManager::set('__RESPONSE', $response);
    ContextManager::set('__REQUEST', $request);

    ContextManager::set('_GET', (array)$request->get);
    ContextManager::set('_POST', (array)$request->post);
    ContextManager::set('_FILES', (array)$request->files);
    ContextManager::set('_COOKIE', (array)$request->cookie);
    ContextManager::set('_SERVER', [
        'REQUEST_URI' => $request->server['request_uri'],
        'REQUEST_METHOD' => $request->server['request_method'],
        'SCRIPT_NAME' => '/index.php',
    ]);
    ContextManager::set('_ENV', (array)$request->header);

    ob_start();
    try {
        $ocRequest = new \OC\AppFramework\Http\Request(
            [
                'server' => ContextManager::get('_SERVER'),
                'get' => $request->get,
                'post' => $request->post,
                'files' => $request->files,
                'cookies' => $request->cookie,
                'headers' => $request->header,
                'method' => $request->server['request_method'],
                'urlParams' => [],
            ],
            new \OC\AppFramework\Http\RequestId('', \OC::$server->get(ISecureRandom::class)),
            \OC::$server->get(\OCP\IConfig::class),
            \OC::$server->get(CsrfTokenManager::class),
        );

        OC::handleRequest($ocRequest);
    } catch (\Exception $e) {
        print("swoole-error: ". $e->getMessage() . PHP_EOL);
        print($e->getTraceAsString());
    }
    $response->end(ob_get_clean());
});

$http->start();