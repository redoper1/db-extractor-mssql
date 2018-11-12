<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DbExtractor\Exception\UserException;

class IncrementalFetchingTest extends AbstractMSSQLTest
{
    public function testIncrementalFetchingByTimestamp(): void
    {
        $config = $this->getIncrementalFetchingConfig();
        $config['parameters']['incrementalFetchingColumn'] = 'timestamp';
        $result = ($this->createApplication($config))->run();
        $outputFile = $this->dataDir . '/out/tables/' . $result['imported']['outputTable'] . '.csv';
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(
            [
                'outputTable' => 'in.c-main.auto-increment-timestamp',
                'rows' => 6,
            ],
            $result['imported']
        );
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('lastFetchedRow', $result['state']);
        $this->assertNotEmpty($result['state']['lastFetchedRow']);
        @unlink($outputFile);
        sleep(2);
        // the next fetch should be empty
        $emptyResult = ($this->createApplication($config, $result['state']))->run();
        $this->assertEquals(0, $emptyResult['imported']['rows']);
        // assert that the state is unchanged
        $this->assertEquals($result['state'], $emptyResult['state']);
        sleep(2);
        //now add a couple rows and run it again.
        $this->pdo->exec('INSERT INTO [auto Increment Timestamp] ([Weir%d Na-me]) VALUES (\'charles\'), (\'william\')');
        $newResult = ($this->createApplication($config, $result['state']))->run();
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $newResult);
        $this->assertArrayHasKey('lastFetchedRow', $newResult['state']);
        $this->assertGreaterThan(
            $result['state']['lastFetchedRow'],
            $newResult['state']['lastFetchedRow']
        );
        $this->assertEquals(2, $newResult['imported']['rows']);
    }
    public function testIncrementalFetchingByAutoIncrement(): void
    {
        $config = $this->getIncrementalFetchingConfig();
        $config['parameters']['incrementalFetchingColumn'] = '_Weir%d I-D';
        $result = ($this->createApplication($config))->run();
        $outputFile = $this->dataDir . '/out/tables/' . $result['imported']['outputTable'] . '.csv';
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(
            [
                'outputTable' => 'in.c-main.auto-increment-timestamp',
                'rows' => 6,
            ],
            $result['imported']
        );
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('lastFetchedRow', $result['state']);
        $this->assertEquals(6, $result['state']['lastFetchedRow']);
        unlink($outputFile);
        sleep(2);
        // the next fetch should be empty
        $emptyResult = ($this->createApplication($config, $result['state']))->run();
        $this->assertEquals(0, $emptyResult['imported']['rows']);
        // assert that the state is unchanged
        $this->assertEquals($result['state'], $emptyResult['state']);
        sleep(2);
        //now add a couple rows and run it again.
        $this->pdo->exec('INSERT INTO [auto Increment Timestamp] ([Weir%d Na-me]) VALUES (\'charles\'), (\'william\')');
        $newResult = ($this->createApplication($config, $result['state']))->run();
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $newResult);
        $this->assertArrayHasKey('lastFetchedRow', $newResult['state']);
        $this->assertEquals(8, $newResult['state']['lastFetchedRow']);
        $this->assertEquals(2, $newResult['imported']['rows']);
    }
    public function testIncrementalFetchingLimit(): void
    {
        $config = $this->getIncrementalFetchingConfig();
        $config['parameters']['incrementalFetchingLimit'] = 1;
        $result = ($this->createApplication($config))->run();
        $this->assertEquals('success', $result['status']);
        $this->assertEquals(
            [
                'outputTable' => 'in.c-main.auto-increment-timestamp',
                'rows' => 1,
            ],
            $result['imported']
        );
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('lastFetchedRow', $result['state']);
        $this->assertEquals(1, $result['state']['lastFetchedRow']);
        sleep(2);
        // the next fetch should contain the second row
        $result = ($this->createApplication($config, $result['state']))->run();
        $this->assertEquals(
            [
                'outputTable' => 'in.c-main.auto-increment-timestamp',
                'rows' => 1,
            ],
            $result['imported']
        );
        //check that output state contains expected information
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('lastFetchedRow', $result['state']);
        $this->assertEquals(2, $result['state']['lastFetchedRow']);
    }
    /**
     * @dataProvider invalidColumnProvider
     */
    public function testIncrementalFetchingInvalidColumns(string $column, string $expectedExceptionMessage): void
    {
        $config = $this->getIncrementalFetchingConfig();
        $config['parameters']['incrementalFetchingColumn'] = $column;

        $this->setExpectedException(UserException::class, $expectedExceptionMessage);

        $result = ($this->createApplication($config))->run();
    }

    public function invalidColumnProvider(): array
    {
        return [
            'column does not exist' => [
                "fakeCol",
                "Column [fakeCol] specified for incremental fetching was not found in the table",
            ],
            'column exists but is not auto-increment nor updating timestamp so should fail' => [
                "Weir%d Na-me",
                "Column [Weir%d Na-me] specified for incremental fetching is not an identity column or a datetime",
            ],
        ];
    }

    public function testIncrementalFetchingInvalidConfig(): void
    {
        $config = $this->getIncrementalFetchingConfig();
        $config['parameters']['query'] = 'SELECT * FROM auto_increment_timestamp';
        unset($config['parameters']['table']);
        try {
            $result = ($this->createApplication($config))->run();
            $this->fail('cannot use incremental fetching with advanced query, should fail.');
        } catch (UserException $e) {
            $this->assertStringStartsWith("Invalid Configuration", $e->getMessage());
        }
    }

    protected function getIncrementalFetchingConfig(): array
    {
        $config = $this->getConfigRow(self::DRIVER);
        unset($config['parameters']['query']);
        $config['parameters']['table'] = [
            'tableName' => 'auto Increment Timestamp',
            'schema' => 'dbo',
        ];
        $config['parameters']['incremental'] = true;
        $config['parameters']['name'] = 'auto-increment-timestamp';
        $config['parameters']['outputTable'] = 'in.c-main.auto-increment-timestamp';
        $config['parameters']['primaryKey'] = ['_Weir%d I-D'];
        $config['parameters']['incrementalFetchingColumn'] = '_Weir%d I-D';
        return $config;
    }
}