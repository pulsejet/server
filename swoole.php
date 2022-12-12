<?php

use OCP\Security\ISecureRandom;

include __DIR__ . "/lib/context.php";
include __DIR__ . "/lib/base.php";

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

    ContextManager::set('_GET', (array)$request->get);
    ContextManager::set('_POST', (array)$request->post);
    ContextManager::set('_FILES', (array)$request->files);
    ContextManager::set('_COOKIE', (array)$request->cookie);
    ContextManager::set('__RESPONSE', $response);
    ContextManager::set('__REQUEST', $request);

    try {
        $ocRequest = new \OC\AppFramework\Http\Request(
            [
                'server' => [
                    'REQUEST_URI' => $request->server['request_uri'],
                ],
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
    }
});

$http->start();