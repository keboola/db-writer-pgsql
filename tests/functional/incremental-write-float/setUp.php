<?php

declare(strict_types=1);

use Keboola\DbWriter\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    // create table with all column types
    $test->connection->exec('CREATE TABLE "incremental" (
        "name" VARCHAR(255),
        "int" int,
        "float" FLOAT PRIMARY KEY,
        "date" DATE,
        "timestamp" TIMESTAMP
    )');

    // insert 100 row to table and different all values
    $insert = $test
        ->connection
        ->getConnection()->prepare('INSERT INTO "incremental" VALUES (?, ?, ?, ?, ?)');
    for ($i = 1; $i <= 20; $i++) {
        $date = new DateTime('2023-01-01');
        $date->modify(sprintf('+%d days', $i));
        $date->modify(sprintf('+%d hours', $i));
        $date->modify(sprintf('+%d minutes', $i));
        $date->modify(sprintf('+%d seconds', $i));

        $insert->execute([
            'name' . $i,
            $i,
            $i + 0.5,
            $date->format('Y-m-d'),
            $date->format('Y-m-d H:i:s'),
        ]);
    }
};
