<?php

declare(strict_types=1);

use Keboola\Component\Logger;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;
use Monolog\Handler\NullHandler;
use Keboola\DbWriter\PgSQLApplication;

require_once(dirname(__FILE__) . '/vendor/autoload.php');

define('APP_NAME', 'wr-db-pgsql');
define('ROOT_PATH', __DIR__);

$logger = new Logger();

$action = 'run';

try {
    $arguments = getopt('d::', ['data::']);
    if (!isset($arguments['data'])) {
        throw new UserException('Data folder not set.');
    }
    $config = json_decode(file_get_contents($arguments['data'] . '/config.json'), true);
    $config['parameters']['data_dir'] = $arguments['data'];
    $config['parameters']['writer_class'] = 'Pgsql';

    $action = isset($config['action']) ? $config['action'] : $action;

    $app = new PgSQLApplication($config, $logger);

    if ($action !== 'run') {
        $app['logger']->setHandlers(array(new NullHandler(Logger::INFO)));
    }

    echo $app->run();
} catch (UserException $e) {
    $logger->log('error', $e->getMessage());

    if ($action !== 'run') {
        echo $e->getMessage();
    }

    exit(1);
} catch (ApplicationException $e) {
    $logger->log('error', $e->getMessage(), (array) $e->getData());
    exit($e->getCode() > 1 ? $e->getCode(): 2);
} catch (\Throwable $e) {
    $logger->log('error', $e->getMessage(), [
        'errFile' => $e->getFile(),
        'errLine' => $e->getLine(),
//        'trace' => $e->getTrace()
    ]);
    exit(2);
}
exit(0);
