<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests;

use Keboola\DbExtractor\MSSQLApplication;
use Keboola\DbExtractor\Test\ExtractorTest;
use Keboola\Csv\CsvFile;
use Keboola\DbExtractorLogger\Logger;

abstract class AbstractMSSQLTest extends ExtractorTest
{
    public const DRIVER = 'mssql';

    /** @var \PDO */
    protected $pdo;

    /** @var string  */
    protected $dataDir = __DIR__ . '/../../data';

    public function setUp(): void
    {
        if (!($this->pdo instanceof \PDO)) {
            $this->makeConnection();
        }
        $this->setupTables();
        $this->cleanupStateInDataDir();
    }

    private function makeConnection(): void
    {
        $config = $this->getConfig(self::DRIVER);
        $params = $config['parameters']['db'];

        if (isset($params['#password'])) {
            $params['password'] = $params['#password'];
        }

        // create test database
        $this->pdo = new \PDO(
            sprintf('sqlsrv:Server=%s', $params['host']),
            $params['user'],
            $params['password']
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('USE master');
        $this->pdo->exec(sprintf("
            IF NOT EXISTS(select * from sys.databases where name='%s') 
            CREATE DATABASE %s
        ", $params['database'], $params['database']));
        $this->pdo->exec(sprintf('USE %s', $params['database']));
    }

    private function cleanupStateInDataDir(): void
    {
        @unlink($this->dataDir . '/in/state.json');
    }

    private function setupTables(): void
    {
        $csv1 = new CsvFile($this->dataDir . '/mssql/sales.csv');
        $specialCsv = new CsvFile($this->dataDir . '/mssql/special.csv');

        $this->dropTable('Empty Test');
        $this->dropTable('sales2');
        $this->dropTable('sales');
        $this->dropTable('special');

        $this->createTextTable($csv1, ['createdat'], 'sales');
        $this->createTextTable($csv1, null, 'sales2');
        $this->createTextTable($specialCsv, null, 'special');
        // drop the t1 demo table if it exists
        $this->dropTable('t1');

        // set up a foreign key relationship
        $this->pdo->exec('ALTER TABLE sales2 ALTER COLUMN createdat varchar(64) NOT NULL');
        $this->pdo->exec('ALTER TABLE sales2 ADD CONSTRAINT FK_sales_sales2 FOREIGN KEY (createdat) REFERENCES sales(createdat)');

        // create another table with an auto_increment ID
        $this->dropTable('auto Increment Timestamp');

        $this->pdo->exec(
            "CREATE TABLE [auto Increment Timestamp] (
            \"_Weir%d I-D\" INT IDENTITY(1,1) NOT NULL, 
            \"Weir%d Na-me\" VARCHAR(55) NOT NULL DEFAULT 'mario',
            \"someInteger\" INT,
            \"someDecimal\" DECIMAL(10,2),
            \"type\" VARCHAR(55) NULL,
            \"smalldatetime\" SMALLDATETIME DEFAULT NULL,
            \"datetime\" DATETIME NOT NULL DEFAULT GETDATE(),
            \"timestamp\" TIMESTAMP
            )"
        );
        $this->pdo->exec('ALTER TABLE [auto Increment Timestamp] ADD CONSTRAINT PK_AUTOINC PRIMARY KEY ("_Weir%d I-D")');
        $this->pdo->exec('ALTER TABLE [auto Increment Timestamp] ADD CONSTRAINT CHK_ID_CONTSTRAINT CHECK ("_Weir%d I-D" > 0 AND "_Weir%d I-D" < 20)');
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('mario', 'plumber', 1, 1.1, '2012-01-10 10:00')");
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('luigi', 'plumber', 2, 2.2, '2012-01-10 10:05')");
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('toad', 'mushroom', 3, 3.3, '2012-01-10 10:10')");
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('princess', 'royalty', 4, 4.4, '2012-01-10 10:15')");
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('wario', 'badguy', 5, 5.5, '2012-01-10 10:25')");
        sleep(1); // stagger the timestamps
        $this->pdo->exec("INSERT INTO [auto Increment Timestamp] (\"Weir%d Na-me\", Type, someInteger, someDecimal, smalldatetime) VALUES ('yoshi', 'horse?', 6, 6.6, '2012-01-10 10:25')");
        // add unique key
        $this->pdo->exec('ALTER TABLE [auto Increment Timestamp] ADD CONSTRAINT UNI_KEY_1 UNIQUE ("Weir%d Na-me", Type)');
    }

    protected function dropTable(string $tableName, ?string $schema = 'dbo'): void
    {
        $this->pdo->exec(
            sprintf(
                "IF OBJECT_ID('[%s].[%s]', 'U') IS NOT NULL DROP TABLE [%s].[%s]",
                $schema,
                $tableName,
                $schema,
                $tableName
            )
        );
    }

    public function getConfig(string $driver = self::DRIVER): array
    {
        $config = parent::getConfig($driver);
        $config['parameters']['extractor_class'] = 'MSSQL';
        return $config;
    }

    protected function generateTableName(CsvFile $file): string
    {
        $tableName = sprintf(
            '%s',
            $file->getBasename('.' . $file->getExtension())
        );

        return 'dbo.' . $tableName;
    }

    protected function createTextTable(CsvFile $file, ?array $primaryKey = null, ?string $overrideTableName = null): void
    {
        if (!$overrideTableName) {
            $tableName = $this->generateTableName($file);
        } else {
            $tableName = $overrideTableName;
        }

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $tableName,
            implode(
                ', ',
                array_map(
                    function ($column) {
                        return $column . ' text NULL';
                    },
                    $file->getHeader()
                )
            )
        );
        $this->pdo->exec($sql);
        // create the primary key if supplied
        if ($primaryKey && is_array($primaryKey) && !empty($primaryKey)) {
            foreach ($primaryKey as $pk) {
                $sql = sprintf('ALTER TABLE %s ALTER COLUMN %s varchar(64) NOT NULL', $tableName, $pk);
                $this->pdo->exec($sql);
            }

            $sql = sprintf(
                'ALTER TABLE %s ADD CONSTRAINT PK_%s PRIMARY KEY (%s)',
                $tableName,
                $tableName,
                implode(',', $primaryKey)
            );
            $this->pdo->exec($sql);
        }

        $file->next();

        $this->pdo->beginTransaction();

        $columnsCount = count($file->current());
        $rowsPerInsert = intval((1000 / $columnsCount) - 1);

        while ($file->current() !== false) {
            $sqlInserts = '';

            for ($i=0; $i<$rowsPerInsert && $file->current() !== false; $i++) {
                $sqlInserts = '';

                $sqlInserts .= sprintf(
                    '(%s),',
                    implode(
                        ',',
                        array_map(
                            function ($data) {
                                if ($data === '') {
                                    return 'null';
                                }
                                if (is_numeric($data)) {
                                    return "'" . $data . "'";
                                }

                                $nonDisplayables = array(
                                '/%0[0-8bcef]/',            // url encoded 00-08, 11, 12, 14, 15
                                '/%1[0-9a-f]/',             // url encoded 16-31
                                '/[\x00-\x08]/',            // 00-08
                                '/\x0b/',                   // 11
                                '/\x0c/',                   // 12
                                '/[\x0e-\x1f]/'             // 14-31
                                );
                                foreach ($nonDisplayables as $regex) {
                                    $data = (string) preg_replace($regex, '', $data);
                                }

                                $data = (string) str_replace("'", "''", $data);

                                return "'" . $data . "'";
                            },
                            $file->current()
                        )
                    )
                );
                $file->next();

                $sql = sprintf(
                    'INSERT INTO %s VALUES %s',
                    $tableName,
                    substr($sqlInserts, 0, -1)
                );

                $this->pdo->exec($sql);
            }
        }

        $this->pdo->commit();

        $count = $this->pdo->query(sprintf('SELECT COUNT(*) AS itemsCount FROM %s', $tableName))->fetchColumn();
        $this->assertEquals($this->countTable($file), (int) $count);
    }

    /**
     * Count records in CSV (with headers)
     *
     * @param  CsvFile $file
     * @return int
     */
    protected function countTable(CsvFile $file): int
    {
        $linesCount = 0;
        foreach ($file as $i => $line) {
            // skip header
            if (!$i) {
                continue;
            }
            $linesCount++;
        }
        return $linesCount;
    }

    public function createApplication(array $config, array $state = []): MSSQLApplication
    {
        $logger = new Logger('ex-db-mssql-tests');
        $app = new MSSQLApplication($config, $logger, $state, $this->dataDir);
        return $app;
    }

    public function tableExists(string $tableName): bool
    {
        $res = $this->pdo->query(
            sprintf(
                'SELECT * FROM information_schema.tables WHERE TABLE_NAME = %s',
                $this->pdo->quote($tableName)
            )
        );
        return !($res->rowCount() === 0);
    }

    public function configProvider(): array
    {
        $this->dataDir = __DIR__ . '/../../data';
        return [
            [
                $this->getConfig(self::DRIVER),
            ],
            [
                $this->getConfigRow(self::DRIVER),
            ],
        ];
    }

    public function getPrivateKey(): string
    {
        return (string) file_get_contents('/root/.ssh/id_rsa');
    }

    public function getPublicKey(): string
    {
        return (string) file_get_contents('/root/.ssh/id_rsa.pub');
    }
}
