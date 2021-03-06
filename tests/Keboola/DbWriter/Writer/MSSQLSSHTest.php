<?php
namespace Keboola\DbWriter\Writer;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Logger;
use Keboola\DbWriter\Test\BaseTest;
use Keboola\DbWriter\WriterFactory;
use Monolog\Handler\TestHandler;

class MSSQLSSHTest extends BaseTest
{
    const DRIVER = 'mssql';

    /** @var MSSQL */
    private $writer;

    private $config;

    /**
     * @var TestHandler
     */
    private $testHandler;

    public function setUp()
    {
        $this->config = $this->getConfig(self::DRIVER);
        $this->config['parameters']['writer_class'] = 'MSSQL';
        $this->config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getEnv('mssql', 'DB_SSH_KEY_PRIVATE'),
                'public' => $this->getEnv('mssql', 'DB_SSH_KEY_PUBLIC')
            ],
            'user' => 'root',
            'sshHost' => 'sshproxy',
            'remoteHost' => 'mssql',
            'remotePort' => '1433',
            'localPort' => '11433',
        ];
        // create test database
        $dbParams = $this->config['parameters']['db'];
        $dsn = sprintf("dblib:host=%s;charset=UTF-8", $dbParams['host']);
        $conn = new \PDO($dsn, $dbParams['user'], $dbParams['#password']);
        $conn->exec("USE master");
        $conn->exec(sprintf("
            IF EXISTS(select * from sys.databases where name='%s') 
            DROP DATABASE %s
        ", $dbParams['database'], $dbParams['database']));
        $conn->exec(sprintf("CREATE DATABASE %s", $dbParams['database']));
        $conn->exec(sprintf("USE %s", $dbParams['database']));

        $this->testHandler = new TestHandler();
        $logger = new Logger(APP_NAME);
        $logger->setHandlers([$this->testHandler]);

        $writerFactory = new WriterFactory($this->config['parameters']);
        $this->writer = $writerFactory->create($logger);
    }

    public function testWriteMssql()
    {
        $tables = $this->config['parameters']['tables'];

        // simple table
        $table = $tables[0];
        $sourceTableId = $table['tableId'];
        $outputTableName = $table['dbName'];
        $sourceFilename = $this->dataDir . "/" . $sourceTableId . ".csv";

        $this->writer->drop($outputTableName);
        $this->writer->write(new CsvFile(realpath($sourceFilename)), $table);

        $conn = $this->writer->getConnection();
        $stmt = $conn->query("SELECT * FROM $outputTableName");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["id","name","glasses"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $this->assertFileEquals($sourceFilename, $resFilename);

        // table with special chars
        $table = $tables[1];
        $sourceTableId = $table['tableId'];
        $outputTableName = $table['dbName'];
        $sourceFilename = $this->dataDir . "/" . $sourceTableId . ".csv";

        $this->writer->drop($outputTableName);
        $this->writer->write(new CsvFile(realpath($sourceFilename)), $table);

        $conn = $this->writer->getConnection();
        $stmt = $conn->query("SELECT * FROM $outputTableName");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp-2');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["col1","col2"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $this->assertFileEquals($sourceFilename, $resFilename);

        // test log messages
        $records = $this->testHandler->getRecords();
        $records = array_filter($records, function ($record) {
            if ($record['level_name'] != 'DEBUG') {
                return true;
            }

            return false;
        });

        $this->assertArrayHasKey('message', $records[0]);
        $this->assertArrayHasKey('level', $records[0]);
        $this->assertArrayHasKey('message', $records[1]);
        $this->assertArrayHasKey('level', $records[1]);

        $this->assertEquals(Logger::INFO, $records[0]['level']);
        $this->assertRegExp('/Creating SSH tunnel/ui', $records[0]['message']);

        $this->assertEquals(Logger::INFO, $records[1]['level']);
        $this->assertRegExp('/Connecting to DSN/ui', $records[1]['message']);
    }
}
