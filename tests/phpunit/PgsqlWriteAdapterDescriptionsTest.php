<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\DbWriter\Writer\PgsqlConnection;
use Keboola\DbWriter\Writer\PgsqlQueryBuilder;
use Keboola\DbWriter\Writer\PgsqlWriteAdapter;
use Keboola\DbWriterAdapter\ValueObject\TableDescriptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class PgsqlWriteAdapterDescriptionsTest extends TestCase
{
    /** @var string[] */
    private array $executed = [];

    private PgsqlWriteAdapter $adapter;

    protected function setUp(): void
    {
        $this->executed = [];
        $logger = new TestLogger();
        // Real connection so that quote() uses the server's escaping; only exec() is recorded.
        $connection = $this->getMockBuilder(PgsqlConnection::class)
            ->setConstructorArgs([
                $logger,
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) getenv('DB_HOST'),
                    (string) getenv('DB_PORT'),
                    (string) getenv('DB_DATABASE'),
                ),
                (string) getenv('DB_USER'),
                (string) getenv('DB_PASSWORD'),
                [],
            ])
            ->onlyMethods(['exec'])
            ->getMock();
        $connection->method('exec')->willReturnCallback(function (string $sql): void {
            $this->executed[] = $sql;
        });

        $this->adapter = new PgsqlWriteAdapter($connection, new PgsqlQueryBuilder(), $logger);
    }

    public function testTableAndColumns(): void
    {
        $this->adapter->applyDescriptions('simple', new TableDescriptions(
            'Table description',
            ['id' => 'Identifier', 'name' => 'Full name'],
        ));

        self::assertSame([
            'COMMENT ON TABLE "simple" IS \'Table description\'',
            'COMMENT ON COLUMN "simple"."id" IS \'Identifier\'',
            'COMMENT ON COLUMN "simple"."name" IS \'Full name\'',
        ], $this->executed);
    }

    public function testColumnsOnly(): void
    {
        $this->adapter->applyDescriptions('simple', new TableDescriptions(null, ['id' => 'Identifier']));

        self::assertSame([
            'COMMENT ON COLUMN "simple"."id" IS \'Identifier\'',
        ], $this->executed);
    }

    public function testEmptyTableDescriptionIsSkipped(): void
    {
        $this->adapter->applyDescriptions('simple', new TableDescriptions('', []));

        self::assertSame([], $this->executed);
    }

    public function testQuoting(): void
    {
        $this->adapter->applyDescriptions('my "table"', new TableDescriptions(
            'It\'s a "table" with \\ backslash',
            ['col "x"' => 'Bob\'s column'],
        ));

        self::assertSame([
            'COMMENT ON TABLE "my ""table""" IS \'It\'\'s a "table" with \\ backslash\'',
            'COMMENT ON COLUMN "my ""table"""."col ""x""" IS \'Bob\'\'s column\'',
        ], $this->executed);
    }

    public function testLongDescriptionIsNotTruncated(): void
    {
        $long = str_repeat('a', 100000);
        $this->adapter->applyDescriptions('simple', new TableDescriptions($long, []));

        self::assertSame([sprintf('COMMENT ON TABLE "simple" IS \'%s\'', $long)], $this->executed);
    }

    public function testNumericColumnName(): void
    {
        // PHP casts numeric string keys to int at runtime.
        /** @var array<string, string> $columns */
        $columns = ['123' => 'Numeric name'];
        $this->adapter->applyDescriptions('simple', new TableDescriptions(null, $columns));

        self::assertSame([
            'COMMENT ON COLUMN "simple"."123" IS \'Numeric name\'',
        ], $this->executed);
    }
}
