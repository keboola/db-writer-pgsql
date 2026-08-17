<?php

declare(strict_types=1);

use Keboola\DbWriter\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    // user with DML privileges only, TRUNCATE is not granted
    $test->connection->exec('DO $$
        BEGIN
            IF EXISTS (SELECT FROM pg_roles WHERE rolname = \'limited_writer\') THEN
                DROP OWNED BY "limited_writer";
                DROP ROLE "limited_writer";
            END IF;
        END
    $$');
    $test->connection->exec('CREATE ROLE "limited_writer" LOGIN PASSWORD \'limited_writer_pwd\'');

    // table created by the database admin
    $test->connection->exec('CREATE TABLE "simple" (
        "id" INT NOT NULL PRIMARY KEY,
        "name" VARCHAR(255)
    )');
    $test->connection->exec('INSERT INTO "simple" ("id", "name") VALUES (100, \'Old Row\')');

    $test->connection->exec('GRANT USAGE, CREATE ON SCHEMA "public" TO "limited_writer"');
    $test->connection->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "simple" TO "limited_writer"');
};
