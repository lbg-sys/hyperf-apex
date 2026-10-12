<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Model;

use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Connection;
use Hyperf\Database\Model\Booted;
use Hyperf\Database\Model\Model;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine;

/**
 * 所有模型的基类。
 *
 * `create()` / `query()` 是框架的魔术转发：基类 `Hyperf\Database\Model\Model` 上没有这两个方法，
 * 它们经 `__call` / `__callStatic` 转发给查询构造器，PHPStan 看不到。这里显式声明，
 * 静态分析才能认出仓储里 `$this->newModel()->create($attributes)` 这类调用。
 *
 * 列属性的类型不用在这里声明：每个模型自己的 `@property` 注解描述了它那张表
 * （由 migrations 推导，见 `@property` 上的中文说明）。
 *
 * @phpstan-consistent-constructor
 *
 * @method static static create(array<string, mixed> $attributes = [])
 */
abstract class AbstractModel extends Model
{
    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /**
     * 软删除列固定叫 `delete_time`。
     *
     * Hyperf 的 SoftDeletes 默认用 `deleted_at`，而本仓库（以及 `schema:model-check` 的校验规则）
     * 约定的是 `delete_time` —— 模型只 `use SoftDeletes` 而不写这个常量时，查询会去碰一个不存在的列，
     * 要等运行期才炸。约定放在基类一处承担，不让每个模型各写一遍。
     */
    public const DELETED_AT = 'delete_time';

    /**
     * 正在执行 boot 的协程 id，按模型类分组.
     *
     * 「标记晚置位」（并发正确性：任何协程都不该看到「已 boot 却没有作用域」）与「标记先置位」
     * （递归重入：boot 没结束前又构造本类模型时直接跳过）是方向相反的两个要求，一个标记满足不了，
     * 所以递归保护单独用这份记录表达：只拦同一协程、同一调用栈的重入，其他协程照常各自 boot。
     *
     * @var array<class-string, array<int, true>>
     */
    private static array $bootingCoroutines = [];

    /**
     * 已解析的事件调度器.
     *
     * 容器在运行期不变，而 retrieved 事件在每次模型水合时都会问一次（连只读的 find() 也算），
     * 值得缓存。**不缓存 null**：worker 启动完成前还取不到调度器，把那一刻的 null 缓存下来
     * 会让此后所有 Model 事件静默失效。
     */
    private static ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * 当前模型对应的逻辑表名（不含连接前缀，前缀由查询语法统一添加）.
     *
     * join、from、where、select 等由查询构造器处理的场景统一通过本方法引用表名；
     * 构造器会自动为表名/限定列名补齐连接前缀，表名调整时只需修改模型内的 $table 声明。
     */
    public static function tableName(): string
    {
        return (new static())->getTable();
    }

    /**
     * 当前模型对应的、已带连接前缀的物理表名.
     *
     * 仅供 whereRaw / orderByRaw / Db::raw 等不经查询构造器、原样输出 SQL 的场景使用；
     * 交给构造器的 from / join / table / column 等位置仍用 tableName()，避免重复加前缀。
     */
    public static function prefixedTableName(): string
    {
        $model = new static();
        /** @var Connection $connection */
        $connection = $model->getConnection();

        return $connection->getTablePrefix() . $model->getTable();
    }

    /**
     * 生成「表名.字段名」的限定列名，用于 join / where / select 等多表查询场景.
     */
    public static function column(string $field): string
    {
        return static::tableName() . '.' . $field;
    }

    /**
     * 从容器解析事件调度器，让 Model 事件真正派发.
     *
     * 基类 Hyperf\Database\Model\Model 的实现读的是从未被赋值的 Register::$dispatcher
     * （框架只注册了连接解析器），因此 Model 事件一直静默不派发；这里按
     * Hyperf\DbConnection\Model\Model 的做法改走容器 —— 这正是框架在
     * Model::getEventDispatcher() 注释里预留的应用级扩展点。
     *
     * has() 守卫是必需的，不是防御性写法：retrieved 在每次模型水合时触发（包括只读的 find()），
     * 而测试容器的 get() 对未知 id 直接抛异常，没有守卫会让现有测试大面积报错。
     * 守卫让测试环境返回 null，fireModelEvent() 按框架原生行为静默返回。
     *
     * 当前仓库没有任何 Model 事件监听器，因此覆写本身不产生任何行为变化。
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if (self::$eventDispatcher !== null) {
            return self::$eventDispatcher;
        }

        if (! ApplicationContext::hasContainer()) {
            return null;
        }

        $container = ApplicationContext::getContainer();
        if (! $container->has(EventDispatcherInterface::class)) {
            return null;
        }

        return self::$eventDispatcher = $container->get(EventDispatcherInterface::class);
    }

    /**
     * 修正框架的 boot 时序：把「已 boot」标记放到 boot() 完成之后.
     *
     * 基类 Hyperf\Database\Model\Model::bootIfNotBooted() 先置位 Booted::$container[static::class]，
     * 再派发 booting 事件、执行 bootTraits()；而模型 traits 的全局作用域（SoftDeletes 对应的
     * SoftDeletingScope 就是其中之一）正是在 bootTraits() 里注册的。置位与注册之间存在一个窗口，
     * 窗口内只要发生协程切换，并发协程拿到的就是「标记已 boot、全局作用域却是空」的模型：构造器
     * 不会再补注册，于是
     * - SoftDeletingScope 注册的 builder 本地宏 withTrashed()/onlyTrashed() 不存在，
     *   Model\Builder::__call() 会把调用转发给 Query\Builder 并抛 BadMethodCallException；
     * - 软删除过滤在那一瞬间一并静默失效，已软删除的行会被当成正常数据返回。
     *
     * 窗口里的让出点是现成的，且不需要任何监听器自己做 IO：Hyperf\Event\EventDispatcher::dispatch()
     * 每命中一个监听器就写一条 DEBUG 日志到 StdoutLogger（dev 下 log_level 含 debug），而 booting 有
     * 两个框架监听器（ModelHookEventListener、ModelEventListener），写 stdout 在 Swoole 的输出/文件
     * hook 下会让出协程（实测：纯 CPU 循环期间对照协程被调度 0 次，200 次 stdout 写期间被调度 20 次）。
     * 即「置位 → booting 派发 → bootTraits 注册作用域」之间至少有两次切走的机会。
     *
     * 置位后移以后，「已 boot」不再隐含「boot 已经结束」，两个后果都要显式处理：
     * 1. 递归重入失去保护：旧实现里 booting 钩子（或观察者）再构造本类模型时会看到标记为 true
     *    直接跳过，现在标记还是 false，会再进一次 boot() 并无限展开 —— 由 $bootingCoroutines
     *    按协程 id 补回这个保护，只拦同协程重入，不同协程不受影响；
     * 2. 并发窗口内 boot() 会被多个协程各执行一次，重复次数与同时构造该类模型的协程数同阶，
     *    不是「最多一次」，因此 boot 逻辑必须是幂等且并发安全的。已核对当前模型链
     *    （含各模型自己 use 的 trait，如 AuditsModelChanges）：
     *    - bootXxx() 只有 SoftDeletes::bootSoftDeletes()：其中的 addGlobalScope() 按
     *      get_class($scope) 覆盖同一个 key，重复或并发执行只会把等价实例覆盖写一次，既不产生
     *      重复作用域也不残留中间态；bootTraits() 里其余的 class_uses_recursive()、method_exists()
     *      都是纯函数，不共享可变状态；
     *    - 链上没有任何 initializeXxx()，所以 bootTraits() 开头对
     *      TraitInitializers::$container[$class] 的重置与重新收集目前无可观测影响；一旦将来引入
     *      initializeXxx()，并发构造可能读到重置后的半成品列表（那个实例会漏掉初始化），
     *      必须在引入时自行加互斥或一次性保护；
     *    - booting / booted 事件会按「并发构造次数」重复派发（旧实现只派发一次）：当前没有模型
     *      实现 booting/booted 钩子、也没有注册模型观察者，因此无副作用；新增此类钩子/观察者时
     *      必须写成幂等的。
     *
     * 换来的保证是：再没有任何协程能看到「已 boot 却没有作用域」的中间态。
     */
    protected function bootIfNotBooted(): void
    {
        if (Booted::$container[static::class] ?? false) {
            return;
        }

        $coroutineId = (int) Coroutine::getCid();
        if (isset(self::$bootingCoroutines[static::class][$coroutineId])) {
            // 同一协程的递归重入：本次 boot 还在调用栈上，直接跳过，否则会无限递归。
            return;
        }

        self::$bootingCoroutines[static::class][$coroutineId] = true;

        try {
            $this->fireModelEvent('booting');

            $this->boot();

            Booted::$container[static::class] = true;

            $this->fireModelEvent('booted');
        } finally {
            unset(self::$bootingCoroutines[static::class][$coroutineId]);
            if ((self::$bootingCoroutines[static::class] ?? []) === []) {
                unset(self::$bootingCoroutines[static::class]);
            }
        }
    }
}
