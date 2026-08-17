<?php

declare(strict_types=1);

use Keboola\DbWriter\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    // table created by the database admin - "name" column is missing
    $test->connection->exec('CREATE TABLE "simple" (
        "id" INT NOT NULL PRIMARY KEY
    )');
};
