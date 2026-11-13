<?php

declare(strict_types=1);

use RuneCloud\System\Main;
use RuneCloud\Http\Request;

require __DIR__ . '/autoload.php';

$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$authorization = ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    ?: ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
    ?: ($headers['authorization'] ?? '');

$requestBody = file_get_contents('php://input', false, null, 0, 65537);
$application = new Main();
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$application->run(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    $basePath,
    new Request(
        $authorization,
        $_SERVER['CONTENT_TYPE'] ?? '',
        $requestBody === false ? '' : $requestBody,
        $_FILES,
        ini_parse_quantity(ini_get('post_max_size')) > 0
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_parse_quantity(ini_get('post_max_size'))
    )
);
