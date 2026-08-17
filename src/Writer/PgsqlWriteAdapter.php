<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\PDO\PdoConnection;
use Keboola\DbWriterAdapter\PDO\PdoWriteAdapter;
use Keboola\DbWriterAdapter\Query\QueryBuilder;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ItemConfig;
use Keboola\DbWriterConfig\Exception\PropertyNotSetException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * @property PgsqlQueryBuilder $queryBuilder
 * @property PgsqlConnection $connection
 */
class PgsqlWriteAdapter extends PdoWriteAdapter
{
    public function drop(string $tableName): void
    {
        $locks = $this->connection->fetchAll(
            $this->queryBuilder->lockTableQueryStatement($this->connection, $tableName),
        );

        if (count($locks) > 0) {
            $this->logger->info(sprintf(
                'Table "%s" is locked by $locks transactions, waiting for them to finish',
                $tableName,
            ));
        }

        $this->connection->exec(
            $this->queryBuilder->dropQueryStatement($this->connection, $tableName),
        );
    }

    public function writeData(string $tableName, ExportConfig $exportConfig): void
    {
        $this->withStageTable(
            $tableName,
            $exportConfig,
            fn(string $stageTableName) => $this->moveDataFromStageToTable(
                $stageTableName,
                $tableName,
                $exportConfig,
            ),
        );
    }

    /**
     * Replaces all rows of an existing table, keeping its structure, owner and privileges intact.
     * Data are removed and inserted in a single transaction, so the table is never left empty
     * when the insert fails.
     */
    public function replaceData(string $tableName, ExportConfig $exportConfig): void
    {
        $this->withStageTable($tableName, $exportConfig, function (string $stageTableName) use (
            $tableName,
            $exportConfig,
        ): void {
            $pdo = $this->connection->getConnection();
            $pdo->beginTransaction();
            try {
                $this->removeAllRows($tableName);
                $this->moveDataFromStageToTable($stageTableName, $tableName, $exportConfig, 1);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        });
    }

    /**
     * @param ItemConfig[] $items
     * @throws UserException
     */
    public function validateTableColumns(string $tableName, array $items): void
    {
        $dbColumns = array_map(fn(array $column) => $column['Field'], $this->getTableInfo($tableName));

        $missingColumns = [];
        foreach ($items as $item) {
            if (strtolower($item->getType()) === 'ignore') {
                continue;
            }
            if (!in_array($item->getDbName(), $dbColumns, true)) {
                $missingColumns[] = $item->getDbName();
            }
        }

        if ($missingColumns) {
            throw new UserException(sprintf(
                'Columns "%s" not found in destination table "%s". Found columns: "%s".',
                implode('", "', $missingColumns),
                $tableName,
                implode('", "', $dbColumns),
            ), 400);
        }
    }

    /**
     * @param callable(string): void $writeCallback
     * @throws PropertyNotSetException
     * @throws UserException
     */
    private function withStageTable(string $tableName, ExportConfig $exportConfig, callable $writeCallback): void
    {
        $stageTableName = $this->generateTmpName($tableName);

        $this->logger->debug(sprintf('Creating staging table "%s"', $stageTableName));
        $this->createStage($stageTableName, $exportConfig->getItems());
        $this->logger->debug(sprintf('Staging table "%s" created', $stageTableName));

        try {
            $this->writeDataToStage($stageTableName, $exportConfig);
            $writeCallback($stageTableName);
        } finally {
            $this->logger->debug(sprintf('Dropping staging table "%s"', $stageTableName));
            $this->drop($stageTableName);
            $this->logger->debug(sprintf('Staging table "%s" dropped', $stageTableName));
        }
    }

    /**
     * TRUNCATE is not allowed on a table referenced by a foreign key of another table,
     * DELETE is used in that case.
     */
    private function removeAllRows(string $tableName): void
    {
        $references = $this->connection->fetchAll(
            $this->queryBuilder->foreignKeyReferencesQueryStatement($this->connection, $tableName),
            1,
        );

        if ($references) {
            $referencingTables = [];
            foreach ($references as $reference) {
                $referencingTable = $reference['table'] ?? null;
                if (is_scalar($referencingTable)) {
                    $referencingTables[] = (string) $referencingTable;
                }
            }

            $this->logger->info(sprintf(
                'Table "%s" is referenced by foreign keys of tables "%s", deleting rows instead of truncating.',
                $tableName,
                implode('", "', array_unique($referencingTables)),
            ));
            $this->connection->exec(
                $this->queryBuilder->deleteAllRowsQueryStatement($this->connection, $tableName),
                1,
            );
            return;
        }

        $this->connection->exec(
            $this->queryBuilder->truncateQueryStatement($this->connection, $tableName),
            1,
        );
    }

    /**
     * @param ItemConfig[] $items
     * @throws PropertyNotSetException
     */
    private function createStage(string $stageTableName, array $items): void
    {
        $stageItems = [];
        foreach ($items as $item) {
            if (strtolower($item->getType()) === 'text') {
                $stageItems[] = ItemConfig::fromArray([
                    'name' => $item->getName(),
                    'dbName' => $item->getDbName(),
                    'type' => 'TEXT',
                    'nullable' => true,
                ]);
            } else {
                $type = strtolower($item->getType());
                $stageType = $this->getStageColumnDataTypeSql($item);
                $size = (str_contains($type, 'char') && $item->hasSize()) ? $item->getSize() : '255';
                $stageItems[] = ItemConfig::fromArray([
                    'name' => $item->getName(),
                    'dbName' => $item->getDbName(),
                    'type' => $stageType,
                    'nullable' => true,
                    'size' => $stageType === 'varchar' ? $size : null,
                ]);
            }
        }

        $this->connection->exec(
            $this->queryBuilder->createQueryStatement(
                $this->connection,
                $stageTableName,
                false,
                $stageItems,
            ),
        );
    }

    private function getStageColumnDataTypeSql(ItemConfig $column): string
    {
        $type = strtolower($column->getType());
        if (in_array($type, ['text', 'json', 'jsonb']) || str_contains($type, '[]')) {
            return 'TEXT';
        } else {
            return 'VARCHAR';
        }
    }

    /**
     * @throws PropertyNotSetException
     * @throws UserException
     */
    private function writeDataToStage(string $stageTableName, ExportConfig $exportConfig): void
    {
        $this->drop($stageTableName);
        $this->createStage($stageTableName, $exportConfig->getItems());

        $copyQuery = $this->queryBuilder->copyQueryStatement(
            $this->connection,
            $exportConfig,
            $stageTableName,
        );

        $psqlCommand = sprintf(
            'psql -h %s -p %s -U %s -d %s -w -c %s;',
            $exportConfig->getDatabaseConfig()->getHost(),
            $exportConfig->getDatabaseConfig()->getPort(),
            $exportConfig->getDatabaseConfig()->getUser(),
            $exportConfig->getDatabaseConfig()->getDatabase(),
            $copyQuery,
        );

        $this->logger->info(sprintf('Uploading data into staging table "%s".', $stageTableName));

        $process = Process::fromShellCommandline(
            $psqlCommand,
            null,
            ['PGPASSWORD' => $exportConfig->getDatabaseConfig()->getPassword()],
        );
        $process->setTimeout(null);

        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info($process->getOutput());
            $this->logger->info(sprintf('Data imported into staging table "%s".', $stageTableName));
        } else {
            throw new UserException('Write process failed: ' . $process->getErrorOutput(), 400);
        }
    }

    private function moveDataFromStageToTable(
        string $stageTableName,
        string $tableName,
        ExportConfig $exportConfig,
        int $maxRetries = Connection::DEFAULT_MAX_RETRIES,
    ): void {
        $this->logger->info('Moving to destination table');

        $query = $this->queryBuilder->writeDataFromStageToTableQueryStatement(
            $this->connection,
            $stageTableName,
            $tableName,
            $exportConfig,
        );
        $this->connection->exec($query, $maxRetries);

        $this->logger->info(sprintf('Data moved into table "%s".', $exportConfig->getDbName()));
    }

    public function validateTable(string $tableName, array $items): void
    {
    }
}
