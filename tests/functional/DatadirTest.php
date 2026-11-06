<?php

declare(strict_types=1);

namespace Keboola\DbWriter\FunctionalTests;

use Keboola\Csv\CsvWriter;
use Keboola\Csv\Exception;
use Keboola\Csv\InvalidArgumentException;
use Keboola\DatadirTests\AbstractDatadirTestCase;
use Keboola\DatadirTests\DatadirTestSpecificationInterface;
use Keboola\DbWriter\TraitTests\CloseSshTunnelsTrait;
use Keboola\DbWriter\Writer\PgsqlConnection;
use Keboola\DbWriter\Writer\PgsqlConnectionFactory;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Psr\Log\Test\TestLogger;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class DatadirTest extends AbstractDatadirTestCase
{
    use CloseSshTunnelsTrait;

    public PgsqlConnection $connection;

    protected string $testProjectDir;

    public bool $dumpComments = false;

    public function __construct(
        ?string $name = null,
        array $data = [],
        string $dataName = '',
    ) {
        putenv('SSH_PRIVATE_KEY=' . file_get_contents('/root/.ssh/id_rsa'));
        putenv('SSH_PUBLIC_KEY=' . file_get_contents('/root/.ssh/id_rsa.pub'));
        parent::__construct($name, $data, $dataName);
        $this->connection = PgsqlConnectionFactory::create($this->getDatabaseConfig(), new TestLogger());
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->closeSshTunnels();
        $this->dropTables();
        $this->dumpComments = false;

        $this->testProjectDir = $this->getTestFileDir() . '/' . $this->dataName();

        // Load setUp.php file - used to init database state
        $setUpPhpFile = $this->testProjectDir . '/setUp.php';
        if (file_exists($setUpPhpFile)) {
            // Get callback from file and check it
            $initCallback = require $setUpPhpFile;
            if (!is_callable($initCallback)) {
                throw new RuntimeException(sprintf('File "%s" must return callback!', $setUpPhpFile));
            }

            // Invoke callback
            $initCallback($this);
        }
    }

    /**
     * @dataProvider provideDatadirSpecifications
     */
    public function testDatadir(DatadirTestSpecificationInterface $specification): void
    {
        $tempDatadir = $this->getTempDatadir($specification);

        $process = $this->runScript($tempDatadir->getTmpFolder());

        $this->dumpTables($tempDatadir->getTmpFolder());
        if ($this->dumpComments) {
            $this->dumpComments($tempDatadir->getTmpFolder());
        }

        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());
    }

    private function dropTables(): void
    {
        foreach ($this->getTableNames() as $tableName) {
            $this->connection->exec(sprintf(
                'DROP TABLE IF EXISTS %s',
                $this->connection->quoteIdentifier($tableName),
            ));
        }
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    private function dumpTables(string $tmpFolder): void
    {
        $dumpDir = $tmpFolder . '/out/db-dump';
        $fs = new Filesystem();
        $fs->mkdir($dumpDir);

        foreach ($this->getTableNames() as $tableName) {
            $this->dumpTableData($tableName, $dumpDir);
        }
    }

    private function dumpComments(string $tmpFolder): void
    {
        $dumpDir = $tmpFolder . '/out/db-comments';
        $fs = new Filesystem();
        $fs->mkdir($dumpDir);

        $schema = $this->connection->quote((string) getenv('DB_SCHEMA'));
        foreach ($this->getTableNames() as $tableName) {
            $relation = sprintf(
                'SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE n.nspname = %s AND c.relname = %s',
                $schema,
                $this->connection->quote($tableName),
            );
            /** @var array{comment: ?string}[] $table */
            $table = $this->connection->fetchAll(sprintf(
                'SELECT obj_description((%s), \'pg_class\') AS comment',
                $relation,
            ));
            /** @var array{column_name: string, comment: ?string}[] $columns */
            $columns = $this->connection->fetchAll(sprintf(
                'SELECT a.attname AS column_name, col_description(a.attrelid, a.attnum) AS comment '
                . 'FROM pg_attribute a WHERE a.attrelid = (%s) AND a.attnum > 0 AND NOT a.attisdropped '
                . 'ORDER BY a.attnum',
                $relation,
            ));

            $comments = [
                'table' => $table[0]['comment'] ?? null,
                'columns' => array_combine(
                    array_column($columns, 'column_name'),
                    array_column($columns, 'comment'),
                ),
            ];
            file_put_contents(
                sprintf('%s/%s.json', $dumpDir, $tableName),
                json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }
    }

    private function getTableNames(): array
    {
        $sql = 'SELECT table_name FROM information_schema.tables WHERE table_catalog=\'%s\' AND table_schema = \'%s\';';
        $tables = $this->connection->fetchAll(
            sprintf(
                $sql,
                getenv('DB_DATABASE'),
                getenv('DB_SCHEMA'),
            ),
        );

        return array_map(function ($item) {
            return $item['table_name'];
        }, $tables);
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    private function dumpTableData(string $tableName, string $tmpFolder): void
    {
        $csvDumpFile = new CsvWriter(sprintf('%s/%s.csv', $tmpFolder, $tableName));

        $rows = $this->connection->fetchAll(sprintf(
            'SELECT * FROM %s',
            $this->connection->quoteIdentifier($tableName),
        ));
        if ($rows) {
            $csvDumpFile->writeRow(array_keys(current($rows)));
            foreach ($rows as $row) {
                $csvDumpFile->writeRow($row);
            }
        }
    }

    private function getDatabaseConfig(): DatabaseConfig
    {
        $config = [
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'),
            'schema' => getenv('DB_SCHEMA'),
            'user' => getenv('DB_USER'),
            '#password' => getenv('DB_PASSWORD'),
        ];

        return DatabaseConfig::fromArray($config);
    }
}
