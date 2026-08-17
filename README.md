# Postgre SQL DB Writer

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/keboola/db-writer-pgsql/blob/master/LICENSE.md)

Writes data to pgsql Database.

## Example configuration

```json
    {
      "db": {        
        "host": "HOST",
        "port": "PORT",
        "database": "DATABASE",
        "user": "USERNAME",
        "password": "PASSWORD",
        "ssh": {
          "enabled": true,
          "keys": {
            "private": "ENCRYPTED_PRIVATE_SSH_KEY",
            "public": "PUBLIC_SSH_KEY"
          },
          "sshHost": "PROXY_HOSTNAME"
        }
      },
      "tables": [
        {
          "tableId": "simple",
          "dbName": "simple",
          "export": true, 
          "incremental": true,
          "primaryKey": ["id"],
          "items": [
            {
              "name": "id",
              "dbName": "id",
              "type": "int",
              "size": null,
              "nullable": null,
              "default": null
            },
            {
              "name": "name",
              "dbName": "name",
              "type": "nvarchar",
              "size": 255,
              "nullable": null,
              "default": null
            },
            {
              "name": "glasses",
              "dbName": "glasses",
              "type": "nvarchar",
              "size": 255,
              "nullable": null,
              "default": null
            }
          ]                                
        }
      ]
    }
```

## Writing to a table created in advance

By default, a **Full Load** (`"incremental": false`) drops the destination table and creates it
again from the configured columns, so the writer must own the table.

With `"preserveTargetTable": true`, the table is not dropped nor created — the writer only
replaces its content:

```json
    {
      "tableId": "simple",
      "dbName": "simple",
      "incremental": false,
      "preserveTargetTable": true,
      "items": [...]
    }
```

- The table must already exist in the configured schema, otherwise the job fails with a user
  error. The same applies to an **Incremental Load** with this option enabled. Views are not
  supported.
- All configured columns must exist in the table; other columns of the table keep their default
  value (or `NULL`), so a `NOT NULL` column without a default must be part of the configuration.
  Column order in the table does not matter, the column types of the table are used.
- Structure, owner, indexes, constraints, triggers and privileges of the table are preserved.
- The rows are removed with `TRUNCATE`, or with `DELETE` when the table is referenced by a
  foreign key of another table or when the `TRUNCATE` privilege is not granted. Removing the rows
  and inserting the new ones runs in a single transaction.
- A table referenced by a foreign key with `ON DELETE CASCADE`, `SET NULL` or `SET DEFAULT` is
  **refused** with a user error, deleting its rows would change data of a table which is not part
  of the configuration. Rows referenced by a `NO ACTION` / `RESTRICT` foreign key must be deleted
  from the referencing table first.
- Because the rows are deleted (not truncated) in those cases, row level `DELETE` triggers do
  fire and dead tuples are left behind, autovacuum has to reclaim the space.
- Required privileges on the table are `SELECT`, `INSERT` and `DELETE` (`TRUNCATE` is used when
  granted). `CREATE` on the schema is **still** required, the staging table the data are loaded
  into is created there.

## Development

App is developed on localhost using TDD.

1. Clone from repository: `git clone git@github.com:keboola/db-writer-pgsql.git`
2. Change directory: `cd db-writer-pgsql`
3. Install composer dependencies: `docker-compose run --rm dev composer install`
4. Develop
5. Run tests: `docker-compose run --rm dev composer tests` or all checks: `docker-compose run --rm dev composer ci`
