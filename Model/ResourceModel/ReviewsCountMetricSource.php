<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingReviewsCount\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Review\Model\Review;

/**
 * Reads the review count of every product per store from the review summaries, as one select
 *
 * A product's value in a store is the `reviews_count` its review summary row holds for that store. Only summaries of
 * the product entity type count: the entity type is found by its code inside the select, so no other query is
 * needed. Summaries of the all-stores row (store id zero), of products that no longer exist, and without a value
 * above zero are left out.
 *
 * The product entity code is quoted by the database adapter; the select carries no bind of its own.
 */
class ReviewsCountMetricSource implements MetricSourceInterface
{
    private const SUMMARY_TABLE = 'review_entity_summary';
    private const ENTITY_TABLE = 'review_entity';
    private const PRODUCT_TABLE = 'catalog_product_entity';

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table names
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function select(): Select
    {
        $select = $this->resourceConnection->getConnection()->select();
        $select->from(['res' => $this->table(self::SUMMARY_TABLE)], []);
        $select->join(['re' => $this->table(self::ENTITY_TABLE)], 're.entity_id = res.entity_type', []);
        $select->join(['p' => $this->table(self::PRODUCT_TABLE)], 'p.entity_id = res.entity_pk_value', []);
        $select->columns([
            'product_id' => 'res.entity_pk_value',
            'store_id' => 'res.store_id',
            'value' => 'res.reviews_count',
        ]);
        $select->where('re.entity_code = ?', Review::ENTITY_PRODUCT_CODE);
        $select->where('res.store_id > 0');
        $select->where('res.reviews_count > 0');

        return $select;
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name without prefix
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
