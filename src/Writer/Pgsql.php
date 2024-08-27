<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Configuration\ValueObject\PgsqlExportConfig;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\WriteAdapter;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;

class Pgsql extends BaseWriter
{
    /** @var PgsqlConnection $connection */
    protected Connection $connection;

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

        // create destination table if not exists
        if (!$this->adapter->tableExists($exportConfig->getDbName())) {
            if ($exportConfig->useTempTable()) {
                $this->logger->info(sprintf('Creating temporary table "%s"', $exportConfig->getDbName()));
            }
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
        $this->adapter->drop($exportConfig->getDbName());
        if ($exportConfig->useTempTable()) {
            $this->logger->info(sprintf('Creating temporary table "%s"', $exportConfig->getDbName()));
        }
        $this->adapter->create(
            $exportConfig->getDbName(),
            $exportConfig->useTempTable(),
            $exportConfig->getItems(),
            $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
        );
        $this->adapter->writeData($exportConfig->getDbName(), $exportConfig);
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
