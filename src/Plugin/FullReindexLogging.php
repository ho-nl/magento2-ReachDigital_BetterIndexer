<?php
namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Indexer\Model\Indexer;

class FullReindexLogging
{
    private $indexerLog;
    private $indexerTraceLog;
    /**
     * @var ScopeConfigInterface
     */
    private $storeConfig;

    public function __construct(ScopeConfigInterface $storeConfig)
    {
        $this->storeConfig = $storeConfig;
        $this->indexerLog = (new \Zend_Log())->addWriter(new \Zend_Log_Writer_Stream(BP . '/var/log/indexer.log'));
        $this->indexerTraceLog = (new \Zend_Log())->addWriter(new \Zend_Log_Writer_Stream(BP . '/var/log/indexer-trace.log'));
    }
    public function aroundReindexAll(Indexer $subject, callable $proceed): void
    {
        if ($this->storeConfig->isSetFlag('reachdigital_indexers/logging/stacktrace_logging')) {
            $exception = new \Exception();
            $this->indexerTraceLog->info(sprintf('Starting full reindex for %s. pid: %s trace: %s', $subject->getId(), getmypid(), $exception->getTraceAsString()));
        }
        $this->indexerLog->info(sprintf('[start][%s][%s][full] Starting full reindex for %s. ', str_pad($subject->getId(), 30, ' ', STR_PAD_LEFT), str_pad(getmypid(), 6, ' ', STR_PAD_LEFT), $subject->getId()));
        $proceed();
        $this->indexerLog->info(sprintf('[stop ][%s][%s][full] Full reindex complete for %s. ', str_pad($subject->getId(), 30, ' ', STR_PAD_LEFT), str_pad(getmypid(), 6, ' ', STR_PAD_LEFT), $subject->getId()));
    }
}
