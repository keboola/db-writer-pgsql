<?php

declare(strict_types=1);

use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\Component\Logger;
use Keboola\DbWriter\PgsqlApplication;

require __DIR__ . '/../vendor/autoload.php';

$logger = new Logger();
try {
    $app = new PgsqlApplication($logger);
    $app->execute();
} catch (UserExceptionInterface $e) {
    $logger->error($e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $logger->critical(
        get_class($e) . ':' . $e->getMessage(),
        [
            'errFile' => $e->getFile(),
            'errLine' => $e->getLine(),
            'errCode' => $e->getCode(),
            'errTrace' => $e->getTraceAsString(),
            'errPrevious' => is_object($e->getPrevious()) ? get_class($e->getPrevious()) : '',
        ],
    );
    exit(2);
}
