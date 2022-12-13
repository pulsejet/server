<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$sessionParams = ['cookie_samesite' => 'Lax'];
session_start($sessionParams);

use OCP\Security\ISecureRandom;

try {
    include __DIR__ . "/lib/context.php";
    include __DIR__ . "/lib/base.php";
} catch (\Exception $e) {
    print("swoole-error: ". $e->getMessage() . PHP_EOL);
    debug_print_backtrace();
}

function swooleSession($request, $response) {
    // https://github.com/phpearth/swoole-engine/blob/master/docs/sessions.md
    if (session_status() != PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($request->cookie[session_name()])) {
        // Client has session cookie set, but Swoole might have session_id() from some
        // other request, so we need to regenerate it
        session_id($request->cookie[session_name()]);
    } else {
        $params = session_get_cookie_params();

        if (session_id()) {
            session_id(\bin2hex(\random_bytes(32)));
        }
        $_SESSION = [];

        $response->rawcookie(
            session_name(),
            session_id(),
            $params['lifetime'] ? time() + $params['lifetime'] : null,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly'],
            $params['samesite']
        );
    }

    $_SESSION['key'] = $_SESSION['key'] ?? rand();

}

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$http = new \Swoole\Http\Server('127.0.0.1', 9501);
$http->set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    'enable_coroutine' => true,
    'document_root' => __DIR__,
    'enable_static_handler' => true,

    'http_parse_post' => true,
    'http_parse_cookie' => true,
    'http_parse_files' => true,
    'open_tcp_nodelay' => true,
    'enable_reuse_port' => true,
	'open_tcp_keepalive' => true,

    'worker_num' => 1,      // The number of worker processes to start
    // 'task_worker_num' => 4,  // The amount of task workers to start
    'backlog' => 128,       // TCP backlog connection number
]);

// Triggered when new worker processes starts
$http->on("WorkerStart", function($server, $workerId)
{
    echo "Worker Started: $workerId\n";
});

$http->on('request', function ($request, $response) {
    swooleSession($request, $response);

    $time = microtime(true);

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
        'SERVER_PROTOCOL' => 'HTTP/1.1',
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
        ob_start();
        print("swoole-error: ". $e->getMessage() . PHP_EOL);
        print($e->getTraceAsString());
        error_log(ob_get_clean());
    }
    $response->end(ob_get_clean());
    error_log($request->server['request_uri']. ' request took: ' . (microtime(true) - $time)*1000 . 'ms');
});

// Triggered when the server is shutting down
$http->on("Shutdown", function($workerId)
{
    echo "Server shutting down...\n";
});

// Triggered when worker processes are being stopped
$http->on("WorkerStop", function($server, $workerId)
{
    echo "Worker Stopped: $workerId\n";
});

// Triggered when task is created
$http->on("Task", function($workerId)
{
    echo "Task created: $workerId\n";
});

$http->start();