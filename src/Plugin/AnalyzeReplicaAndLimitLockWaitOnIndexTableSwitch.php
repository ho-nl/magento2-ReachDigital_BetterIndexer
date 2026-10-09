<?php

declare(strict_types=1);

namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexName;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexNameResolverInterface;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexTableSwitcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the MSI index table switch (RENAME TABLE of the replica onto the live
 * table) safe for live traffic:
 *
 * - ANALYZE the replica before the switch, so the new live table does not start
 *   out with empty optimizer statistics (which can cause very slow query plans
 *   for queries joining it, e.g. the configurable price indexer).
 * - Use a short lock_wait_timeout for the RENAME and retry. A pending RENAME
 *   waiting for its exclusive metadata lock makes all new queries on the table
 *   queue up behind it, so it must not wait (indefinitely) on a long-running
 *   query.
 */
class AnalyzeReplicaAndLimitLockWaitOnIndexTableSwitch
{
    /** @var ResourceConnection */
    private $resourceConnection;

    /** @var IndexNameResolverInterface */
    private $indexNameResolver;

    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    private $lockWaitTimeout;

    /** @var int */
    private $maxAttempts;

    /** @var int */
    private $retryDelay;

    public function __construct(
        ResourceConnection $resourceConnection,
        IndexNameResolverInterface $indexNameResolver,
        LoggerInterface $logger,
        int $lockWaitTimeout = 5,
        int $maxAttempts = 5,
        int $retryDelay = 10
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->indexNameResolver = $indexNameResolver;
        $this->logger = $logger;
        $this->lockWaitTimeout = $lockWaitTimeout;
        $this->maxAttempts = $maxAttempts;
        $this->retryDelay = $retryDelay;
    }

    /**
     * @throws LockWaitException when the switch still can't get its metadata lock after all attempts
     */
    public function aroundSwitch(
        IndexTableSwitcherInterface $subject,
        callable $proceed,
        IndexName $indexName,
        string $connectionName
    ): void {
        $connection = $this->resourceConnection->getConnection($connectionName);

        // Same replica table naming as \Magento\InventoryMultiDimensionalIndexerApi\Model\IndexTableSwitcher
        $tableName = $this->indexNameResolver->resolveName($indexName);
        $connection->query('ANALYZE TABLE ' . $connection->quoteIdentifier($tableName . '_replica'))->fetchAll();

        $originalLockWaitTimeout = (int) $connection->fetchOne('SELECT @@SESSION.lock_wait_timeout');
        $connection->query(sprintf('SET SESSION lock_wait_timeout = %d', $this->lockWaitTimeout));
        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $proceed($indexName, $connectionName);
                    return;
                } catch (LockWaitException $e) {
                    if ($attempt >= $this->maxAttempts) {
                        throw $e;
                    }
                    $this->logger->warning(sprintf(
                        'Index table switch for %s timed out waiting for metadata lock (attempt %d/%d), retrying in %ds',
                        $tableName,
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
}
