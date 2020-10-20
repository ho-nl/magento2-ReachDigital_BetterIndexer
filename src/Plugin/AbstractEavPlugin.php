<?php

namespace ReachDigital\IndexerPerformance\Plugin;

class AbstractEavPlugin
{
    /**
     * @var \Magento\Framework\Indexer\Table\StrategyInterface
     */
    private $tableStrategy;

    public function __construct(\Magento\Framework\Indexer\Table\StrategyInterface $tableStrategy)
    {
        $this->tableStrategy = $tableStrategy;
    }

    public function beforeReindexEntities(
        \Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\AbstractEav $subject,
        $processIds
    ) {
        $this->tableStrategy->setUseIdxTable(true);
        return [$processIds];
    }
}
