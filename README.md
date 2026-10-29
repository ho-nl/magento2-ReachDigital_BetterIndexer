# Reach Digital Improved indexer performance

## Installation
```bash
composer require reach-digital/magento2-betterindexers
php bin/magento module:enable ReachDigital_BetterIndexers
```

## Features
* Improves performance of indexers
  * Smarter queries
  * Use temporary index tables
  * Manage memory usage
  * Improve potential for deadlocks
  * Improve full reindex table swaps (all `RENAME TABLE`s, e.g. MSI, price,
    catalog rule, flat)
    * ANALYZE before swap, so new table has proper statistics
    * This avoid a potential query pile up / deadlock due to poorly optimized query plans
    * Lower `lock_wait_timeout` during swap (default was *1 year*), with retries
* Recover indexers after crash
* better logging ('var/log/indexer.log')
