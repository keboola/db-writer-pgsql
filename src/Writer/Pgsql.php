<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Configuration\ValueObject\PgsqlExportConfig;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\WriteAdapter;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;

class Pgsql extends BaseWriter
{
    /** @var PgsqlConnection $connection */
    protected Connection $connection;

    /** @var PgsqlWriteAdapter $adapter */
    protected WriteAdapter $adapter;

    protected function createConnection(DatabaseConfig $databaseConfig): Connection
    {
        return PgsqlConnectionFactory::create($databaseConfig, $this->logger);
    }

    /**
     * @param PgsqlExportConfig $exportConfig
     */
    protected function writeIncremental(ExportConfig $exportConfig): void
    {
        // write to staging table
        $stageTableName = $this->adapter->generateTmpName($exportConfig->getDbName());

        $this->adapter->drop($stageTableName);
        $this->adapter->create(
            $stageTableName,
            true,
            $exportConfig->getItems(),
            $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
        );
        $this->adapter->writeData($stageTableName, $exportConfig);

        if ($exportConfig->preserveTargetTable()) {
            $this->assertTargetTable($exportConfig);
        }

        // create destination table if not exists
        if (!$exportConfig->preserveTargetTable() && !$this->adapter->tableExists($exportConfig->getDbName())) {
            $this->adapter->create(
                $exportConfig->getDbName(),
                $exportConfig->useTempTable(),
                $exportConfig->getItems(),
                $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
            );
        }
        $this->adapter->validateTable($exportConfig->getDbName(), $exportConfig->getItems());

        // upsert from staging to destination table
        $this->adapter->upsert($exportConfig, $stageTableName);
    }

    /**
     * @param PgsqlExportConfig $exportConfig
     */
    protected function writeFull(ExportConfig $exportConfig): void
    {
        if ($exportConfig->preserveTargetTable()) {
            $this->assertTargetTable($exportConfig);

            $this->logger->info(sprintf(
                'Replacing data in existing table "%s", its structure is preserved.',
                $exportConfig->getDbName(),
            ));
            $this->adapter->replaceData($exportConfig->getDbName(), $exportConfig);
            return;
        }

        $this->adapter->drop($exportConfig->getDbName());
        $this->adapter->create(
            $exportConfig->getDbName(),
            $exportConfig->useTempTable(),
            $exportConfig->getItems(),
            $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
        );
        $this->adapter->writeData($exportConfig->getDbName(), $exportConfig);
    }


    /**
     * @throws UserException
     */
    private function assertTargetTable(PgsqlExportConfig $exportConfig): void
    {
        if (!$this->adapter->tableExists($exportConfig->getDbName())) {
            throw new UserException(sprintf(
                'Table "%s" does not exist in schema "%s". '
                . 'Create it in the database first or disable the "preserveTargetTable" option.',
                $exportConfig->getDbName(),
                $exportConfig->getDatabaseConfig()->getSchema(),
            ));
        }

        $this->adapter->validateTableColumns($exportConfig->getDbName(), $exportConfig->getItems());
    }

    protected function createWriteAdapter(): WriteAdapter
    {
        return new PgsqlWriteAdapter(
            $this->connection,
            new PgsqlQueryBuilder(),
            $this->logger,
        );
    }
}
