<?php /** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

$secrets = require 'secrets.php';

spl_autoload_register(static function(string $className) {
    require_once __DIR__ . '/classes/' . $className . '.php';
});

$backup = new Backup($secrets);
$backup->savePage([
    'page'                  => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    'timestamp'             => isset($_GET['timestamp']) ? (int) $_GET['timestamp'] : NULL,
    'isCurrentlyScrobbling' => isset($_GET['isCurrentlyScrobbling']),
    'collectNowPlaying'     => isset($_GET['collectNowPlaying']),
]);
