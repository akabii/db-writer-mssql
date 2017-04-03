<?php
/**
 * Author: miro@keboola.com
 * Date: 03/04/2017
 */
namespace Keboola\DbWriter\MSSQL;

use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;

class Application extends \Keboola\DbWriter\Application
{
    public function runAction()
    {
        $uploaded = [];
        $tables = array_filter($this['parameters']['tables'], function ($table) {
            return ($table['export']);
        });

        $writer = $this['writer'];
        foreach ($tables as $table) {
            if (!$writer->isTableValid($table)) {
                continue;
            }

            $csv = $this->getInputCsv($table['tableId']);

            $targetTableName = $table['dbName'];

            if ($table['incremental']) {
                $table['dbName'] = $writer->generateTmpName($table);
            }

            $table['items'] = $this->reorderColumns($csv, $table['items']);

            if (empty($table['items'])) {
                continue;
            }

            try {
                $writer->drop($table['dbName']);
                $writer->create($table);
                $writer->write($csv, $table);

                if ($table['incremental']) {
                    // create target table if not exists
                    if (!$writer->tableExists($targetTableName)) {
                        $destinationTable = $table;
                        $destinationTable['dbName'] = $targetTableName;
                        $destinationTable['incremental'] = false;
                        $writer->create($destinationTable);
                    }
                    $writer->upsert($table, $targetTableName);
                }
            } catch (UserException $e) {
                throw $e;
            } catch (\PDOException $e) {
                throw new UserException($e->getMessage(), 400, $e);
            } catch (\Exception $e) {
                throw new ApplicationException($e->getMessage(), 500, $e);
            }

            $uploaded[] = $table['tableId'];
        }

        return [
            'status' => 'success',
            'uploaded' => $uploaded
        ];
    }
}