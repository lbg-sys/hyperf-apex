<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Repository;

use Hyperf\Apex\Database\Model\AbstractModel;
use Hyperf\Apex\Support\LikePattern;
use Hyperf\Apex\Support\Time;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model;

/**
 * 仓储基类。
 *
 * `newModel()` 的返回类型写 `AbstractModel` 而不是 `Model`：模型上的 `create()` 等魔术方法
 * 声明在 `AbstractModel` 上，写基类会让静态分析看不到它们。具体模型还需要
 * `@extends AbstractRepository<ConcreteModel>` 才能让 TModel 收敛到实际类型。
 *
 * @template TModel of AbstractModel
 */
abstract class AbstractRepository
{
    /** 单条 INSERT 的行数上限，避免一次拼出过长的语句。 */
    private const INSERT_CHUNK_SIZE = 500;

    /** @var class-string<TModel> */
    protected string $modelClass;

    /** @return TModel */
    protected function newModel(): AbstractModel
    {
        return new $this->modelClass();
    }

    /** @return Builder<TModel> */
    protected function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /**
     * 按主键查询单条记录。
     *
     * 经由 query() 取得 Builder，因此始终受 Model 全局 Scope 约束。
     * 保持 protected：是否对外提供按主键查询由各仓储在自身接口中显式声明。
     *
     * @return null|TModel
     */
    protected function findByPrimaryKey(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * 按主键批量查询。
     *
     * 空 ID 直接返回空数组：whereIn 传空数组会退化成不过滤该条件，可能扫出全表。
     * 结果统一用 array_values 归一成 list，调用方按顺序取值时不必再关心键。
     * 保持 protected：是否对外提供批量查询由各仓储在自身接口中显式声明。
     *
     * @param list<int|string> $ids
     * @return list<TModel>
     */
    protected function findModelsByIds(array $ids): array
    {
        return $this->findModelsByColumn('id', $ids);
    }

    /**
     * 按指定列批量查询。
     *
     * 空值列表直接返回空数组：whereIn 传空数组会退化成不过滤该条件，可能扫出全表。
     *
     * @param list<int|string> $values
     * @return list<TModel>
     */
    protected function findModelsByColumn(string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return array_values($this->query()->whereIn($column, $values)->get()->all());
    }

    /**
     * 关键字模糊筛选。
     *
     * 对给定的若干列做 OR 模糊匹配，关键字为 null 或全空白时整体跳过 ——
     * 空关键字不能落成 `like '%%'`，那会匹配所有行，等于把筛选条件悄悄取消。
     *
     * 关键字里的 `%`、`_`、`\` 交给 LikePattern 转义成字面量：用户搜 `50%`（想匹配
     * 带百分号的文本）时未转义会生成 `like '%50%%'`，尾部多出的通配符让任何含 `50`
     * 的行都命中；只输入 `%` 更会退化成 `like '%%'` 扫出全表。
     *
     * 单列时不套闭包，生成的 SQL 与直接 where 完全一致。
     *
     * @param Builder<TModel> $query
     * @param list<string> $columns
     */
    protected function applyKeywordFilter(Builder $query, ?string $keyword, array $columns): void
    {
        $keyword = $keyword === null ? '' : trim($keyword);
        if ($keyword === '') {
            return;
        }

        $pattern = LikePattern::contains($keyword);
        if (count($columns) === 1) {
            $query->where($columns[0], 'like', $pattern);

            return;
        }

        $query->where(static function (Builder $builder) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, 'like', $pattern);

                    continue;
                }

                $builder->orWhere($column, 'like', $pattern);
            }
        });
    }

    /**
     * 批量插入并忽略唯一键冲突，返回累计受影响行数。
     *
     * 分块避免单条语句过长。两个时间列都由应用侧补：走原始 INSERT 不经过模型的时间戳逻辑，
     * 而报表与排序会读它们 —— 交给列的 `CURRENT_TIMESTAMP` 默认值会让同一张表里出现两个时钟
     * （`create_time` 来自数据库、`update_time` 来自应用服务器），AGENTS 也明确要求时间值一律
     * 由应用侧传入。调用方显式给出的值优先（PHP 的数组并集是左操作数优先）。
     *
     * 返回受影响行数是必要的：`INSERT IGNORE` 会把数据类错误（非法值、超长截断、
     * NOT NULL 违规）也降级成警告并丢行，不只是唯一键冲突 —— 调用方据此才能发现「少插了行」。
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function insertRowsOrIgnore(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = Time::now()->toDateTimeString();
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $inserted += (int) $this->query()->insertOrIgnore(array_map(
                static fn (array $row): array => $row + ['create_time' => $now, 'update_time' => $now],
                $chunk,
            ));
        }

        return $inserted;
    }
}
