<?php

declare(strict_types=1);

namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Psr\Log\LoggerInterface;

/**
 * Makes table renames safe for live traffic. Full reindexes build a separate
 * table and then RENAME it onto the live table (MSI IndexTableSwitcher, catalog
 * ActiveTableSwitcher, catalog rule IndexerTableSwapper, flat indexers, third
 * party indexers). These all end up in renameTablesBatch() or renameTable(), so
 * this covers them all:
 *
 * - ANALYZE the tables being renamed first, so the new live table does not
 *   start out with empty optimizer statistics (which can cause very slow query
 *   plans for queries joining it, e.g. the configurable price indexer joining
 *   inventory_stock_N).
 * - Use a short lock_wait_timeout for the RENAME and retry. A pending RENAME
 *   waiting for its exclusive metadata lock makes all new queries on the table
 *   queue up behind it, so it must not wait (by default up to a year) on a
 *   long-running query.
 *
 * Renames inside a transaction are left alone: ANALYZE would implicitly commit
 * it, and renameTable() then runs on a separate connection.
 */
class AnalyzeAndLimitLockWaitOnTableRename
{
    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    private $lockWaitTimeout;

    /** @var int */
    private $maxAttempts;

    /** @var int */
    private $retryDelay;

    public function __construct(
        LoggerInterface $logger,
        int $lockWaitTimeout = 5,
        int $maxAttempts = 5,
        int $retryDelay = 10
    ) {
        $this->logger = $logger;
        $this->lockWaitTimeout = $lockWaitTimeout;
        $this->maxAttempts = $maxAttempts;
        $this->retryDelay = $retryDelay;
    }

    /**
     * @throws LockWaitException when the rename still can't get its metadata lock after all attempts
     */
    public function aroundRenameTablesBatch(Mysql $subject, callable $proceed, array $tablePairs): bool
    {
        if (!$tablePairs || $subject->getTransactionLevel() > 0) {
            return $proceed($tablePairs);
        }

        // Only tables that exist before the rename, not intermediate names that
        // are created within the same batch (e.g. *_outdated in a 3-way swap).
        $tables = [];
        $createdInBatch = [];
        foreach ($tablePairs as $pair) {
            if (!isset($createdInBatch[$pair['oldName']])) {
                $tables[$pair['oldName']] = $pair['oldName'];
            }
            $createdInBatch[$pair['newName']] = true;
        }

        return $this->renameSafely($subject, array_values($tables), function () use ($proceed, $tablePairs) {
            return $proceed($tablePairs);
        });
    }

    /**
     * @throws LockWaitException when the rename still can't get its metadata lock after all attempts
     */
    public function aroundRenameTable(
        Mysql $subject,
        callable $proceed,
        $oldTableName,
        $newTableName,
        $schemaName = null
    ): bool {
        if ($subject->getTransactionLevel() > 0) {
            return $proceed($oldTableName, $newTableName, $schemaName);
        }

        $table = $schemaName ? $schemaName . '.' . $oldTableName : $oldTableName;

        return $this->renameSafely(
            $subject,
            [$table],
            function () use ($proceed, $oldTableName, $newTableName, $schemaName) {
                return $proceed($oldTableName, $newTableName, $schemaName);
            }
        );
    }

    /**
     * @param string[] $tables tables to ANALYZE before renaming
     * @throws LockWaitException when the rename still can't get its metadata lock after all attempts
     */
    public function renameSafely(Mysql $connection, array $tables, callable $rename): bool
    {
        $originalLockWaitTimeout = (int) $connection->fetchOne('SELECT @@SESSION.lock_wait_timeout');
        $connection->query(sprintf('SET SESSION lock_wait_timeout = %d', $this->lockWaitTimeout));
        try {
            $this->analyzeTables($connection, $tables);

            for ($attempt = 1; ; $attempt++) {
                try {
                    return $rename();
                } catch (LockWaitException $e) {
                    if ($attempt >= $this->maxAttempts) {
                        throw $e;
                    }
                    $this->logger->warning(sprintf(
                        'Rename of %s timed out waiting for metadata lock (attempt %d/%d), retrying in %ds',
                        implode(', ', $tables),
                        $attempt,
                        $this->maxAttempts,
                        $this->retryDelay
                    ));
                    sleep($this->retryDelay);
                }
            }
        } finally {
            $connection->query(sprintf('SET SESSION lock_wait_timeout = %d', $originalLockWaitTimeout));
        }
    }

    /**
     * ANALYZE problems are logged, not thrown: the rename itself should still
     * go ahead.
     *
     * @param string[] $tables
     */
    public function analyzeTables(Mysql $connection, array $tables): void
    {
        $quotedTables = array_map(function (string $table) use ($connection): string {
            return $connection->quoteIdentifier($table);
        }, $tables);

        try {
            $rows = $connection->query('ANALYZE TABLE ' . implode(', ', $quotedTables))->fetchAll();
        } catch (LockWaitException $e) {
            $this->logger->warning(sprintf(
                'ANALYZE TABLE %s before rename timed out waiting for metadata lock',
                implode(', ', $tables)
            ));
            return;
        }

        // ANALYZE reports problems as result rows instead of errors
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['Msg_type'] ?? ''), 'status') !== 0) {
                $this->logger->warning(sprintf(
                    'ANALYZE TABLE %s before rename: %s: %s',
                    $row['Table'] ?? '',
                    $row['Msg_type'] ?? '',
                    $row['Msg_text'] ?? ''
                ));
            }
        }
    }
}
