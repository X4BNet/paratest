<?php

declare(strict_types=1);

// The private pool accepts requests only from the ParaTest process owning its socket.
(static function (): void {
    $path = $_SERVER['PARATEST_CONTEXT'] ?? '';
    $context = unserialize(file_get_contents($path), ['allowed_classes' => false]);
    if ($context['version'] !== PHP_VERSION_ID) {
        throw new RuntimeException('ParaTest requires matching CLI and FPM PHP versions.');
    }
    foreach ($context['environment'] as $name => $value) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $_SERVER[$name] = (string) $value;
    }
    chdir($context['cwd']);
    $input = stream_socket_client('unix://' . $context['commands'], $errno, $error, 10);
    if ($input === false) {
        throw new RuntimeException('Cannot connect to the ParaTest command channel: ' . $error);
    }
    define('STDIN', $input);
    define('STDOUT', fopen('php://output', 'wb'));
    define('STDERR', fopen('php://stderr', 'wb'));
    // Report engine fatals before an application's shutdown handler renders a web error.
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log(sprintf('ParaTest FPM fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));
        }
    });
    $wrapper = __DIR__ . '/phpunit-wrapper.php';
    $_SERVER['SCRIPT_FILENAME'] = $wrapper;
    $_SERVER['argv'] = $GLOBALS['argv'] = [$wrapper, ...$context['arguments']];
    $_SERVER['argc'] = $GLOBALS['argc'] = count($GLOBALS['argv']);
    $arguments = $context['arguments'];
    $getopt = [];
    for ($index = 0; $index < count($arguments); $index += 2) {
        $getopt[substr($arguments[$index], 2)] = $arguments[$index + 1];
    }
    $GLOBALS['paratestFpmOptions'] = $getopt;
    require $wrapper;
})();
