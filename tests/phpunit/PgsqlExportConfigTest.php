<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\DbWriter\Configuration\ValueObject\PgsqlExportConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use PHPUnit\Framework\TestCase;

class PgsqlExportConfigTest extends TestCase
{
    public function testPropagateDescriptionsDefaultsToTrue(): void
    {
        $exportConfig = PgsqlExportConfig::fromArray($this->getConfig(), $this->getInputMapping());

        self::assertTrue($exportConfig->shouldPropagateDescriptions());
    }

    public function testPropagateDescriptionsFalseIsPassedThrough(): void
    {
        $config = $this->getConfig();
        $config['propagateDescriptions'] = false;

        $exportConfig = PgsqlExportConfig::fromArray($config, $this->getInputMapping());

        self::assertFalse($exportConfig->shouldPropagateDescriptions());
    }

    public function testUseTempTableStillWorks(): void
    {
        $config = $this->getConfig();
        $config['useTempTable'] = true;
        $config['propagateDescriptions'] = false;

        $exportConfig = PgsqlExportConfig::fromArray($config, $this->getInputMapping());

        self::assertTrue($exportConfig->useTempTable());
        self::assertFalse($exportConfig->shouldPropagateDescriptions());
    }

    public function testConstructorWithoutPropagateDescriptionsDefaultsToTrue(): void
    {
        $exportConfig = new PgsqlExportConfig(
            '/data',
            'Pgsql',
            DatabaseConfig::fromArray($this->getConfig()['db']),
            'in.c-main.table',
            'table',
            false,
            true,
            null,
            [],
            '/data/in/tables/table.csv',
            true,
        );

        self::assertTrue($exportConfig->useTempTable());
        self::assertTrue($exportConfig->shouldPropagateDescriptions());
    }

    /**
     * @return array{
     *     data_dir: string,
     *     writer_class: string,
     *     db: array<string, string>,
     *     tableId: string,
     *     dbName: string,
     *     items: array<int, array<string, mixed>>,
     *     useTempTable?: bool,
     *     propagateDescriptions?: bool,
     * }
     */
    private function getConfig(): array
    {
        return [
            'data_dir' => '/data',
            'writer_class' => 'Pgsql',
            'db' => [
                'host' => 'host',
                'database' => 'database',
                'user' => 'user',
                '#password' => 'password',
                'schema' => 'schema',
            ],
            'tableId' => 'in.c-main.table',
            'dbName' => 'table',
            'items' => [
                ['name' => 'id', 'dbName' => 'id', 'type' => 'integer', 'size' => '', 'nullable' => false],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getInputMapping(): array
    {
        return [['source' => 'in.c-main.table', 'destination' => 'table.csv', 'columns' => ['id']]];
    }
}
