<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Aetherfall\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\Aetherfall\Support\Env::load(BASE_PATH . '/.env');

set_exception_handler(static function (Throwable $error): void {
    \Aetherfall\Support\Logger::error($error);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Fehler: {$error->getMessage()}\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ein interner Fehler ist aufgetreten. Details wurden protokolliert.'], JSON_UNESCAPED_UNICODE);
});

