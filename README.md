# Reach Digital Improved indexer performance

## Installation
```BASH
composer require reach-digital/magento2-betterindexers
php bin/magento module:enable ReachDigital_BetterIndexers
```

## Features
* Improves preformance of indexers
  * Smarter queries
  * Use temporary index tables
  * Manage memory usage
* Safer MSI index table switch: `ANALYZE` replica first, short
  `lock_wait_timeout` (with retries) on the `RENAME`
* Recover indexers after crash
* better logging ('var/log/indexer.log')
