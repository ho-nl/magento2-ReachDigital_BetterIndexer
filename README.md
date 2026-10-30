# Reach Digital Improved indexer performance

## Installation
```bash
composer require reach-digital/magento2-betterindexers
bin/magento module:enable ReachDigital_BetterIndexers
```

## Features
* Improves performance of indexers
  * Smarter queries
  * Use temporary index tables
  * Manage memory usage
  * Improve potential for deadlocks
  * Improve full reindex table swaps (where tables are renamed)
    * ANALYZE before swap, so new table has proper statistics
    * This avoid a potential query pile up / deadlock due to poorly optimized query plans
      which incorrectly assume the table has no rows. This can lead to hundreds of millions
      of rows being sequentially scanned.
    * Lower `lock_wait_timeout` during swap (default was *1 year*), with retries
* Recover indexers after crash
* Improved logging of full and partial indexer runs (`var/log/indexer.log`)
