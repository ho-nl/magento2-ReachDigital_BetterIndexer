<?php
namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Indexer\Model\Indexer;

class FullReindexLogging
{
    /**
     * @var DirectoryList
     */
    private $directoryList;

    private $indexerLog;
    private $indexerTraceLog;
    /**
     * @var ScopeConfigInterface
     */
    private $storeConfig;

    public function __construct(ScopeConfigInterface $storeConfig, DirectoryList $directoryList)
    {
        $this->storeConfig = $storeConfig;
        $this->directoryList = $directoryList;
    }
    public function aroundReindexAll(Indexer $subject, callable $proceed): void
    {
        $this->initializeLoggers();

        if ($this->storeConfig->isSetFlag('reachdigital_indexers/logging/stacktrace_logging')) {
            $exception = new \Exception();
            $this->indexerTraceLog->info(sprintf('Starting full reindex for %s. pid: %s trace: %s', $subject->getId(), getmypid(), $exception->getTraceAsString()));
        }
        $this->indexerLog->info(sprintf('[start][%s][%s][full] Starting full reindex for %s. ', str_pad($subject->getId(), 30, ' ', STR_PAD_LEFT), str_pad(getmypid(), 6, ' ', STR_PAD_LEFT), $subject->getId()));
        $proceed();
        $this->indexerLog->info(sprintf('[stop ][%s][%s][full] Full reindex complete for %s. ', str_pad($subject->getId(), 30, ' ', STR_PAD_LEFT), str_pad(getmypid(), 6, ' ', STR_PAD_LEFT), $subject->getId()));
    }

    private function initializeLoggers(): void
    {
        if ($this->indexerLog !== null) {
            return;
        }

        $logDirectory = $this->directoryList->getRoot() . '/var/log';
        $this->indexerLog = (new \Zend_Log())->addWriter(new \Zend_Log_Writer_Stream($logDirectory . '/indexer.log'));
        $this->indexerTraceLog = (new \Zend_Log())->addWriter(
            new \Zend_Log_Writer_Stream($logDirectory . '/indexer-trace.log')
        );
    }
}
