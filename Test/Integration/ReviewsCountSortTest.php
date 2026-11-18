<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingReviewsCount\Test\Integration;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSorting\Api\Search\ShopperSortFieldsInterface;
use Hryvinskyi\CatalogSorting\Model\Index\DocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\RecordingSearchDocumentRefresher;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\ReplacesSearchEngineAndPageCache;
use Hryvinskyi\CatalogSortingOptions\Api\ListingSortOptionsInterface;
use Hryvinskyi\CatalogSortingReviewsCount\Model\ResourceModel\ReviewsCountMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Reviews Count on the real wiring and the real database: the refresh stores, per store view, the `reviews_count` of
 * the review summary of each product, leaving out the all-stores summary, summaries of another entity type,
 * summaries without a value above zero and summaries of products that do not exist; the values reach the product
 * search documents, a `reviews_count` sort is rendered on the document field, and the listing option is offered where
 * the store enables it.
 *
 * The refresh commits its own transaction, so the refresh scenario runs outside the test transaction and removes
 * the products, summaries, stored values and pending rows it created. Recording doubles stand in for the search
 * engine and the page cache, which the run neither needs nor may write to.
 */
#[AppArea('global')]
class ReviewsCountSortTest extends TestCase
{
    use ReplacesSearchEngineAndPageCache;

    private const CODE = 'reviews_count';
    private const FIELD = 'sort_reviews_count';
    private const DEFAULT_STORE = 'default';
    private const SECOND_STORE = 'hryvinskyi_reviews_count';
    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';
    private const PENDING_TABLE = 'hryvinskyi_catalog_sorting_metric_pending';
    private const SUMMARY_TABLE = 'review_entity_summary';
    private const SKU_PREFIX = 'hryvinskyi-reviews_count-test-';
    private const MISSING_PRODUCT_OFFSET = 1000000;

    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @var list<int>
     */
    private array $summaryProductIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $connection = $this->connection();
        $connection->delete($this->table(self::METRIC_TABLE), ['metric_code = ?' => self::CODE]);
        if ($this->summaryProductIds !== []) {
            $connection->delete(
                $this->table(self::SUMMARY_TABLE),
                ['entity_pk_value IN (?)' => $this->summaryProductIds]
            );
        }
        if ($this->productIds !== []) {
            $connection->delete($this->table(self::PENDING_TABLE), ['product_id IN (?)' => $this->productIds]);
            $connection->delete($this->table('catalog_product_entity'), ['entity_id IN (?)' => $this->productIds]);
        }
        $this->summaryProductIds = [];
        $this->productIds = [];
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        DbIsolation(false),
        DataFixture(StoreFixture::class, ['code' => self::SECOND_STORE], 'second_store'),
        Config('catalog_sorting/reviews_count/enabled', '1', 'store', self::DEFAULT_STORE),
        Config('catalog_sorting/reviews_count/enabled', '1', 'store', self::SECOND_STORE)
    ]
    public function testTheRefreshStoresTheSummaryValuePerStoreViewAsTheRuleSays(): void
    {
        $this->replaceTheSearchEngineAndThePageCache();
        $store = $this->storeId(self::DEFAULT_STORE);
        $secondStore = $this->storeId(self::SECOND_STORE);
        $rated = $this->createProduct('rated', 'simple');
        $other = $this->createProduct('other', 'simple');
        $empty = $this->createProduct('empty', 'simple');
        $customerEntity = $this->createProduct('customer-entity', 'simple');
        $missing = $this->missingProductId();
        $productType = $this->entityTypeId('product');
        $customerType = $this->entityTypeId('customer');

        $this->addSummary($rated, $productType, $store, 12);
        $this->addSummary($rated, $productType, $secondStore, 3);
        $this->addSummary($rated, $productType, 0, 50);
        $this->addSummary($other, $productType, $store, 5);
        $this->addSummary($empty, $productType, $store, 0);
        $this->addSummary($customerEntity, $customerType, $store, 20);
        $this->addSummary($missing, $productType, $store, 9);

        $changed = $this->refresh();

        // The rated and the other product: one product changed in two stores counts once, and a zero summary, a
        // summary of another entity type and a summary of a missing product store no value
        self::assertSame([self::CODE => 2], $changed);
        self::assertSame([$rated => 12.0, $other => 5.0], $this->storedValues($store));
        self::assertSame([$rated => 3.0], $this->storedValues($secondStore));
        self::assertSame([], array_diff([$rated, $other], $this->refresher()->takeProductIds()));
        self::assertSame([], $this->pendingIds());

        $fields = $this->service(DocumentFields::class)->getFields([$rated, $other, $empty], $store);
        self::assertSame(12.0, $fields[$rated][self::FIELD] ?? null);
        self::assertSame(5.0, $fields[$other][self::FIELD] ?? null);
        self::assertArrayNotHasKey(self::FIELD, $fields[$empty] ?? []);

        self::assertSame([self::CODE => 0], $this->refresh());
        self::assertSame([], $this->refresher()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testTheMetricIsRegisteredOnTheDocumentField(): void
    {
        $metric = $this->service(MetricRegistryInterface::class)->get(self::CODE);

        self::assertSame(self::FIELD, $metric->getDocumentField());
        self::assertSame(FieldType::Long, $metric->getFieldType());
        self::assertInstanceOf(ReviewsCountMetricSource::class, $metric->getSource());
        self::assertFalse($metric->isEnabledIn($this->storeId(self::DEFAULT_STORE)));
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testAReviewsCountSortIsRenderedOnTheDocumentFieldWithTheShoppersDirection(): void
    {
        $sorts = $this->service(ShopperSortFieldsInterface::class)->apply([[self::CODE => ['order' => 'desc']]]);

        self::assertSame(
            [[self::FIELD => ['order' => 'desc', 'missing' => 0, 'unmapped_type' => 'long']]],
            $sorts
        );
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        Config('catalog_sorting/reviews_count/enabled', '1', 'store', self::DEFAULT_STORE)
    ]
    public function testTheListingOptionIsOfferedDescendingWhereTheStoreEnablesIt(): void
    {
        $options = $this->service(ListingSortOptionsInterface::class);

        $offered = $options->offeredIn($this->storeId(self::DEFAULT_STORE));

        self::assertSame('Reviews Count', $offered[self::CODE] ?? null);
        self::assertSame('desc', $options->defaultDirectionOf(self::CODE)?->value);
    }

    /**
     * Run the Reviews Count refresh through the real container
     *
     * @return array<string, int>
     */
    private function refresh(): array
    {
        return $this->service(MetricRefreshInterface::class)->refresh([self::CODE]);
    }

    /**
     * Create a bare product row
     *
     * @param string $name Name that makes the SKU unique
     * @param string $type Product type
     * @return int Product id
     */
    private function createProduct(string $name, string $type): int
    {
        $connection = $this->connection();
        $attributeSetId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('eav_entity_type'), ['default_attribute_set_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        $sku = uniqid(self::SKU_PREFIX . $name . '-');
        $connection->insert($this->table('catalog_product_entity'), [
            'attribute_set_id' => is_numeric($attributeSetId) ? (int)$attributeSetId : 4,
            'type_id' => $type,
            'sku' => $sku,
        ]);
        $productId = $this->id('catalog_product_entity', 'entity_id', 'sku = ?', $sku);
        $this->productIds[] = $productId;

        return $productId;
    }

    /**
     * Read the id of a row just inserted
     *
     * @param string $table Table name without prefix
     * @param string $idColumn Id column, or an expression over it
     * @param string $condition Condition that finds the row, with one `?` placeholder
     * @param int|string $value Value the condition is quoted with
     * @return int
     */
    private function id(string $table, string $idColumn, string $condition, int|string $value): int
    {
        $select = $this->connection()->select();
        $select->from($this->table($table), [$idColumn]);
        $select->where($condition, $value);
        $id = $this->connection()->fetchOne($select);
        self::assertIsNumeric($id);

        return (int)$id;
    }

    /**
     * Get the stored Reviews Count values of the test products in a store
     *
     * @param int $storeId Store id
     * @return array<int, float> Product id => value, in the order the products were created
     */
    private function storedValues(int $storeId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(self::METRIC_TABLE), ['product_id', 'value'])
                ->where('metric_code = ?', self::CODE)
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', $this->productIds)
                ->order('product_id ASC')
        );

        $values = [];
        foreach ($rows as $productId => $value) {
            $values[(int)$productId] = is_numeric($value) ? (float)$value : 0.0;
        }

        return $values;
    }

    /**
     * Get the pending product ids among the test products
     *
     * @return list<int>
     */
    private function pendingIds(): array
    {
        $connection = $this->connection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::PENDING_TABLE), ['product_id'])
                ->where('product_id IN (?)', $this->productIds)
        );

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids));
    }

    /**
     * Get the shared recording document refresher the refresh holds
     *
     * @return RecordingSearchDocumentRefresher
     */
    private function refresher(): RecordingSearchDocumentRefresher
    {
        return $this->service(RecordingSearchDocumentRefresher::class);
    }

    /**
     * Get the id of a store view by code
     *
     * @param string $code Store code
     * @return int
     */
    private function storeId(string $code): int
    {
        return (int)$this->service(StoreManagerInterface::class)->getStore($code)->getId();
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->service(ResourceConnection::class)->getTableName($name);
    }

    /**
     * Get the default database connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->service(ResourceConnection::class)->getConnection();
    }

    /**
     * Get a service from the test application's container
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function service(string $type): object
    {
        $service = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    /**
     * Insert a review summary row
     *
     * @param int $productId Product id the summary belongs to
     * @param int $entityTypeId Review entity type id of the summary
     * @param int $storeId Store the summary is for; zero is the all-stores row
     * @param int $value Value of `reviews_count`; the other summary column is set to a different value
     * @return void
     */
    private function addSummary(int $productId, int $entityTypeId, int $storeId, int $value): void
    {
        $this->connection()->insert($this->table(self::SUMMARY_TABLE), [
            'entity_pk_value' => $productId,
            'entity_type' => $entityTypeId,
            'store_id' => $storeId,
            'reviews_count' => $value,
            'rating_summary' => 7,
        ]);
        $this->summaryProductIds[] = $productId;
    }

    /**
     * Get the id of a review entity type by its code
     *
     * @param string $code Review entity code
     * @return int
     */
    private function entityTypeId(string $code): int
    {
        return $this->id('review_entity', 'entity_id', 'entity_code = ?', $code);
    }

    /**
     * Get a product id that no product has
     *
     * @return int
     */
    private function missingProductId(): int
    {
        return max([0, ...$this->productIds]) + self::MISSING_PRODUCT_OFFSET;
    }
}
