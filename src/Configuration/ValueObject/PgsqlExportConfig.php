<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Configuration\ValueObject;

use Keboola\DbWriter\Writer\SshTunnel;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ItemConfig;
use Keboola\DbWriterConfig\Exception\UserException;

readonly class PgsqlExportConfig extends ExportConfig
{
    private bool $useTempTable;

    public function __construct(
        private string $dataDir,
        private string $writerClass,
        private DatabaseConfig $databaseConfig,
        private string $tableId,
        private string $dbName,
        private bool $incremental,
        private bool $export,
        private ?array $primaryKey,
        private array $items,
        private string $tableFilePath,
        bool $useTempTable,
    ) {
        $this->useTempTable = $useTempTable;
        parent::__construct(
            $this->dataDir,
            $this->writerClass,
            $this->databaseConfig,
            $this->tableId,
            $this->dbName,
            $this->incremental,
            $this->export,
            $this->primaryKey,
            $this->items,
            $this->tableFilePath,
        );
    }

    public static function fromArray(array $config, array $inputMapping, ?DatabaseConfig $databaseConfig = null): self
    {
        $filteredInputMapping = array_filter($inputMapping, fn($v) => $v['source'] === $config['tableId']);
        if (count($filteredInputMapping) === 0) {
            throw new UserException(
                sprintf('Table "%s" in storage input mapping cannot be found.', $config['tableId']),
            );
        }
        $tableFilePath = sprintf(
            '%s/in/tables/%s',
            $config['data_dir'],
            current($filteredInputMapping)['destination'],
        );

        return new self(
            $config['data_dir'],
            $config['writer_class'],
            $databaseConfig ?? DatabaseConfig::fromArray($config['db']),
            $config['tableId'],
            $config['dbName'],
            $config['incremental'] ?? false,
            $config['export'] ?? true,
            !empty($config['primaryKey']) ? $config['primaryKey'] : null,
            array_map(fn($v) => ItemConfig::fromArray($v), $config['items']),
            $tableFilePath,
            $config['useTempTable'] ?? false,
        );
    }

    public function useTempTable(): bool
    {
        return $this->useTempTable;
    }
}
