<?php

declare(strict_types=1);

use Keboola\DbWriter\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    // table created by the database admin and referenced with ON DELETE CASCADE
    $test->connection->exec('CREATE TABLE "simple" (
        "id" INT NOT NULL PRIMARY KEY,
        "name" VARCHAR(255)
    )');

    $test->connection->exec('CREATE TABLE "simple_child" (
        "id" INT NOT NULL PRIMARY KEY,
        "simple_id" INT REFERENCES "simple" ("id") ON DELETE CASCADE
    )');

    $test->connection->exec('INSERT INTO "simple" ("id", "name") VALUES (100, \'Old Row\')');
    $test->connection->exec('INSERT INTO "simple_child" ("id", "simple_id") VALUES (1, 100)');
};
