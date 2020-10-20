<?php

namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\Mview\ActionFactory;
use Magento\Framework\Mview\ActionInterface;
use Magento\Framework\Mview\View;

class ViewPlugin
{
    /**
     * Default batch size for partial reindex
     */
    const DEFAULT_BATCH_SIZE = 1000;

    /**
     * Max versions to load from database at a time
     */
    private static $maxVersionQueryBatch = 100000;

    /**
     * @var string
     */
    protected $_idFieldName = 'view_id';

    /**
     * @var ActionFactory
     */
    protected $actionFactory;

    /**
     * @var array
     */
    private $changelogBatchSize;

    private $indexerLog;

    /**
     * @param ActionFactory $actionFactory
     * @param array $data
     * @param array $changelogBatchSize
     */
    public function __construct(ActionFactory $actionFactory, array $changelogBatchSize = [])
    {
        $this->actionFactory = $actionFactory;
        $this->changelogBatchSize = $changelogBatchSize;
        (new \Magento\Framework\Filesystem\Io\File())->mkdir(BP . '/var/log');
        $this->indexerLog = (new \Zend\Log\Logger())->addWriter(
            new \Zend\Log\Writer\Stream(BP . '/var/log/indexer.log')
        );
    }

    /**
     * Materialize view by IDs in changelog
     *
     * @return void
     * @throws Exception
     */
    public function aroundUpdate(\Magento\Framework\Mview\View $subject, callable $proceed)
    {
        if ($subject->getState()->getStatus() !== View\StateInterface::STATUS_IDLE) {
            return;
        }

        pcntl_signal(SIGINT, $this->stop($subject));
        pcntl_signal(SIGQUIT, $this->stop($subject));
        pcntl_signal(SIGHUP, $this->stop($subject));
        register_shutdown_function($this->stop($subject));

        try {
            $currentVersionId = $subject->getChangelog()->getVersion();
        } catch (ChangelogTableNotExistsException $e) {
            return;
        }

        $lastVersionId = (int) $subject->getState()->getVersionId();
        $action = $this->actionFactory->get($subject->getActionClass());

        $this->indexerLog->info(
            sprintf(
                'Starting indexer for %s. Processing versions between %s and %s.',
                $subject->getId(),
                $lastVersionId,
                $currentVersionId
            )
        );
        try {
            $subject
                ->getState()
                ->setStatus(View\StateInterface::STATUS_WORKING)
                ->save();

            $this->aroundExecuteAction($subject, $proceed, $action, $lastVersionId, $currentVersionId);

            $this->indexerLog->info(sprintf('Succesfully ran indexer %s.', $subject->getId()));
            $subject->getState()->loadByView($subject->getId());
            $statusToRestore =
                $subject->getState()->getStatus() === View\StateInterface::STATUS_SUSPENDED
                    ? View\StateInterface::STATUS_SUSPENDED
                    : View\StateInterface::STATUS_IDLE;
            $subject
                ->getState()
                ->setVersionId($currentVersionId)
                ->setStatus($statusToRestore)
                ->save();
        } finally {
            $this->stop($subject)();
        }
    }

    /**
     * Execute action from last version to current version, by batches
     *
     * @param ActionInterface $action
     * @param int $lastVersionId
     * @param int $currentVersionId
     * @return void
     * @throws \Exception
     */
    private function aroundExecuteAction(
        \Magento\Framework\Mview\View $subject,
        callable $proceed,
        ActionInterface $action,
        int $lastVersionId,
        int $currentVersionId
    ) {
        /* Get the memory limit in bytes. */
        $memoryLimit = $this->getMemoryLimit();

        /* Max memory usage: 90% of the memory_limit. */
        $memoryPercentageLimit = 90;

        $versionBatchSize = self::$maxVersionQueryBatch;
        $batchSize = isset($this->changelogBatchSize[$subject->getChangelog()->getViewId()])
            ? $this->changelogBatchSize[$subject->getChangelog()->getViewId()]
            : self::DEFAULT_BATCH_SIZE;

        for ($vsFrom = $lastVersionId; $vsFrom < $currentVersionId; $vsFrom += $versionBatchSize) {
            // Don't go past the current version for atomicity.
            $versionTo = min($currentVersionId, $vsFrom + $versionBatchSize);
            $ids = $subject->getChangelog()->getList($vsFrom, $versionTo);

            $this->indexerLog->info(
                sprintf(
                    'Starting batch for indexer %s. Batch contains versions between %s and %s. Found %s items to update.',
                    $subject->getId(),
                    $vsFrom,
                    $vsFrom + $versionBatchSize,
                    count($ids)
                )
            );
            // We run the actual indexer in batches.
            // Chunked AFTER loading to avoid duplicates in separate chunks.
            $chunks = array_chunk($ids, $batchSize);
            foreach ($chunks as $ids) {
                $action->execute($ids);
                /* Memory check. */
                if ($memoryLimit > 0) {
                    $memoryUsage = memory_get_usage(true);
                    $percentageUsage = ($memoryUsage * 100) / $memoryLimit;
                    if ($percentageUsage >= $memoryPercentageLimit) {
                        throw new \RuntimeException('Memory usage too high while running index.');
                    }
                }
            }
            $subject->getState()->loadByView($subject->getId());
            $subject->getState()->setVersionId($versionTo);
            $subject->getState()->save();
        }
    }

    /**
     * Parse the memory_limit variable from the php.ini file.
     */
    private function getMemoryLimit()
    {
        $limitString = ini_get('memory_limit');
        $unit = strtolower(mb_substr($limitString, -1));
        $bytes = intval(mb_substr($limitString, 0, -1), 10);
        switch ($unit) {
            case 'k':
                $bytes *= 1024;
                break;
            case 'm':
                $bytes *= 1048576;
                break;
            case 'g':
                $bytes *= 1073741824;
                break;
            default:
                break;
        }
        return $bytes;
    }

    private function stop(\Magento\Framework\Mview\View $subject)
    {
        return function () use ($subject) {
            $subject->getState()->loadByView($subject->getId());
            $statusToRestore =
                $subject->getState()->getStatus() == View\StateInterface::STATUS_SUSPENDED
                    ? View\StateInterface::STATUS_SUSPENDED
                    : View\StateInterface::STATUS_IDLE;
            $subject
                ->getState()
                ->setStatus($statusToRestore)
                ->save();
        };
    }
}
