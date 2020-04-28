<?php

namespace ReachDigital\IndexerPerformance\Plugin;

use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Magento\Framework\Phrase;

class ChangelogPlugin
{
    protected $resource;
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @throws ConnectionException
     */
    public function __construct(\Magento\Framework\App\ResourceConnection $resource)
    {
        $this->connection = $resource->getConnection();
        $this->resource = $resource;
        $this->checkConnection();
    }

    public function aroundGetList(\Magento\Framework\Mview\View\Changelog $subject, callable $proceed, $fromVersionId, $toVersionId)
    {
        $changelogTableName = $this->resource->getTableName($subject->getName());
        if (!$this->connection->isTableExists($changelogTableName)) {
            throw new ChangelogTableNotExistsException(new Phrase("Table %1 does not exist", [$changelogTableName]));
        }

        $select = $this->connection->select()
            ->from(
                $changelogTableName,
                [$subject->getColumnName()]
            )->group($subject->getColumnName())
            ->having(
                'MAX(version_id) > ?',
                (int)$fromVersionId
            )->having(
                'MAX(version_id) <= ?',
                (int)$toVersionId
            );

        return array_map('intval', $this->connection->fetchCol($select));
    }

    /**
     * Check DB connection
     *
     * @return void
     * @throws ConnectionException
     */
    protected function checkConnection()
    {
        if (!$this->connection) {
            throw new ConnectionException(
                new Phrase("The write connection to the database isn't available. Please try again later.")
            );
        }
    }
}