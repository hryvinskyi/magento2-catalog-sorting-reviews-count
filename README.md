# Magento 2 Catalog Sorting: Reviews Count

Adds **Reviews Count** to the storefront Sort By list: the products with the most customer reviews first, per store view.

## What it does for a store

- **A Reviews Count sort order** (`product_list_order=reviews_count`), offered in the store views where it is enabled. A listing sorted by it opens most-reviewed first when the shopper chose no direction.
- **The platform's review count, per store.** A product's value in a store is the `reviews_count` its review summary row holds for that store. The module counts no review of its own.
- **Copied once a day, not per request.** The value is read with one select over the review summaries by the popularity data refresh of `hryvinskyi/magento2-catalog-sorting-metrics`. Only products whose value changed get their search documents rebuilt.
- **Sorted in the search engine** on the numeric document field `sort_reviews_count`; no join and no computation per query. A product without reviews sorts as 0.

## Requirements

- PHP 8.1 to 8.5.
- `hryvinskyi/magento2-catalog-sorting`, `hryvinskyi/magento2-catalog-sorting-options` and `hryvinskyi/magento2-catalog-sorting-metrics`, ^1.0 each.
- The platform's Review, Catalog, Store, Config and Backend modules (what `composer.json` and `etc/module.xml` declare).

## Installation

```bash
composer require hryvinskyi/magento2-catalog-sorting-reviews-count
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento indexer:reindex catalogsearch_fulltext
```

The module adds the field `sort_reviews_count` to the product search documents. One full `catalogsearch_fulltext` reindex puts it into the index mapping; listings keep working before it. After enabling the option in a store view, run `bin/magento catalog-sorting:metrics:refresh --metric=reviews_count`, or wait for the next scheduled refresh.

## Configuration

**Stores › Configuration › Catalog › Catalog Sorting › Reviews Count**

| Path | Label | Default | Scope |
|---|---|---|---|
| `catalog_sorting/reviews_count/enabled` | Enabled | No | default, website, store view |

The label, the place and the visibility of the option in Sort By are set in **Catalog Sorting › Sort Options**. The refresh schedule is in **Catalog Sorting › Popularity Data Refresh**.

## Registrations

| Pool | Item |
|---|---|
| `metrics` of `Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry` | metric `reviews_count`, field `sort_reviews_count` (`long`) |
| `sortFields` of `Hryvinskyi\CatalogSorting\Model\Search\ShopperSortFields` | code `reviews_count` on `sort_reviews_count` |
| `options` of `Hryvinskyi\CatalogSortingOptions\Model\ListingSortOptions` | option `reviews_count`, "Reviews Count", descending, `sortOrder` 140 |
