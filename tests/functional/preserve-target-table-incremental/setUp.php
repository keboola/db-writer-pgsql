<?php

declare(strict_types=1);

use Keboola\DbWriter\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    // table created by the database admin, incremental load must not re-create it
    $test->connection->exec('CREATE TABLE "simple" (
        "note" VARCHAR(255) DEFAULT \'created by admin\',
        "name" VARCHAR(255),
        "id" INT NOT NULL PRIMARY KEY
    )');

    $test->connection->exec('INSERT INTO "simple" ("note", "name", "id") VALUES (\'old row\', \'Old Row\', 100)');
};
