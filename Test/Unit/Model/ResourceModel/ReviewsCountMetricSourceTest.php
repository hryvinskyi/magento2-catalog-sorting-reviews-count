<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingReviewsCount\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingReviewsCount\Model\ResourceModel\ReviewsCountMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The select reads the review count from the review summaries of the product entity type only, per store view, and
 * leaves out the all-stores row and summaries without a value above zero. The product entity code is quoted into
 * its condition, so the select carries no placeholder and no bind for the refresh to carry over.
 *
 * What the whole query computes is checked against real review summaries by the integration test.
 */
class ReviewsCountMetricSourceTest extends TestCase
{
    /**
     * @var ReviewsCountMetricSource
     */
    private ReviewsCountMetricSource $source;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $connection = $this->createMock(Mysql::class);
        $renderer = $this->createMock(SelectRenderer::class);
        $connection->method('select')
            ->willReturnCallback(static fn (): Select => new Select($connection, $renderer));
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', self::quoted($value), $text)
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->source = new ReviewsCountMetricSource($resource);
    }

    /**
     * @return void
     */
    public function testOnlySummariesOfTheProductEntityTypeAreRead(): void
    {
        $select = $this->source->select();

        self::assertStringContainsString("re.entity_code = 'product'", $this->conditions($select));
        $tables = $select->getPart(Select::FROM);
        self::assertIsArray($tables);
        $join = $tables['re'] ?? null;
        self::assertIsArray($join);
        self::assertSame('review_entity', $join['tableName']);
        self::assertSame('re.entity_id = res.entity_type', $join['joinCondition']);
    }

    /**
     * @return void
     */
    public function testTheAllStoresRowAndEmptySummariesAreLeftOut(): void
    {
        $conditions = $this->conditions($this->source->select());

        self::assertStringContainsString('res.store_id > 0', $conditions);
        self::assertStringContainsString('res.reviews_count > 0', $conditions);
    }

    /**
     * @return void
     */
    public function testTheValueIsTheSummaryColumnOfTheProductInTheStore(): void
    {
        self::assertSame(
            ['product_id' => 'res.entity_pk_value', 'store_id' => 'res.store_id', 'value' => 'res.reviews_count'],
            $this->columnsOf($this->source->select())
        );
    }

    /**
     * The refresh wraps the select in a statement with a named bind of its own, so no positional placeholder may
     * be left in it
     *
     * @return void
     */
    public function testEveryValueIsQuotedIntoItsCondition(): void
    {
        $select = $this->source->select();

        self::assertStringNotContainsString('?', $this->conditions($select));
        self::assertSame([], $select->getBind());
    }

    /**
     * Get the select's columns as alias => `table.column`
     *
     * @param Select $select The metric select
     * @return array<string, string>
     */
    private function columnsOf(Select $select): array
    {
        $columns = [];
        $parts = $select->getPart(Select::COLUMNS);
        self::assertIsArray($parts);
        foreach ($parts as $part) {
            self::assertIsArray($part);
            [$table, $name, $alias] = $part + [null, null, null];
            if (!is_string($table) || !is_string($alias) || !(is_string($name) || $name instanceof \Stringable)) {
                self::fail('A column of the select has an unexpected shape.');
            }
            $columns[$alias] = $table . '.' . $name;
        }

        return $columns;
    }

    /**
     * Get the select's WHERE conditions as one string
     *
     * @param Select $select The metric select
     * @return string
     */
    private function conditions(Select $select): string
    {
        $where = $select->getPart(Select::WHERE);
        self::assertIsArray($where);

        return implode(' ', array_map(static fn (mixed $part): string => is_string($part) ? $part : '', $where));
    }

    /**
     * Render the value a `?` placeholder is bound to the way the database adapter quotes strings
     *
     * @param mixed $value One value
     * @return string
     */
    private static function quoted(mixed $value): string
    {
        self::assertIsString($value);

        return "'" . $value . "'";
    }
}
