<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

use FilesystemIterator;
use Hyperf\Apex\Database\Model\AbstractModel;
use Hyperf\Database\Model\Relations\Relation;
use Hyperf\Database\Model\SoftDeletes;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

use function Hyperf\Support\class_uses_recursive;

/**
 * 扫描 app/ 下的全部 Model，提取与数据库结构做一致性比对所需的元数据。
 *
 * 发现模型不靠 `get_declared_classes()`（PSR-4 无法枚举），也不全量 autoload app/：
 * 先按「路径含 Model/」或「文件内引用 AbstractModel」粗筛，把候选收敛到几十个文件，
 * 再交给反射确认是否为 AbstractModel 子类。这样既覆盖继承自中间基类的模型，
 * 又不会为了找模型把整个应用加载一遍。
 */
final class ModelMetadataReader
{
    /**
     * 关系方法常省略返回类型声明（例如 `User::roles()`），只能从 `@return` 注解识别。
     */
    private const RELATION_TYPES = [
        'HasMany', 'HasOne', 'BelongsTo', 'BelongsToMany',
        'MorphMany', 'MorphOne', 'MorphTo', 'MorphToMany',
        'HasManyThrough', 'HasOneThrough',
    ];

    /**
     * @return list<ModelMetadata>
     */
    public function read(): array
    {
        $models = [];

        foreach ($this->candidateFiles() as $path) {
            $class = $this->className($path);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $this->isModel($reflection)) {
                continue;
            }

            $models[] = $this->metadata($reflection);
        }

        usort(
            $models,
            static fn (ModelMetadata $left, ModelMetadata $right): int => strcmp($left->modelClass, $right->modelClass)
        );

        return $models;
    }

    /**
     * @return iterable<string>
     */
    private function candidateFiles(): iterable
    {
        $root = BASE_PATH . '/app';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if ($this->looksLikeModelFile($path)) {
                yield $path;
            }
        }
    }

    private function looksLikeModelFile(string $path): bool
    {
        if (str_contains($path, '/Model/')) {
            return true;
        }

        return str_contains((string) file_get_contents($path), 'AbstractModel');
    }

    private function className(string $path): ?string
    {
        $root = str_replace('\\', '/', BASE_PATH) . '/app/';
        if (! str_starts_with($path, $root)) {
            return null;
        }

        $relative = substr($path, strlen($root), -4);
        if ($relative === '') {
            return null;
        }

        return 'App\\' . str_replace('/', '\\', $relative);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function isModel(ReflectionClass $reflection): bool
    {
        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum() || $reflection->isTrait()) {
            return false;
        }

        return $reflection->isSubclassOf(AbstractModel::class);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function metadata(ReflectionClass $reflection): ModelMetadata
    {
        $instance = $reflection->newInstance();
        if (! $instance instanceof AbstractModel) {
            throw new LogicException(sprintf('%s 不是 AbstractModel 子类', $reflection->getName()));
        }

        $table = $instance->getTable();
        $connection = (string) $instance->getConnectionName();
        if ($connection === '') {
            $connection = 'default';
        }

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($reflection->getName()), true);

        return new ModelMetadata(
            $reflection->getName(),
            $connection,
            $table,
            $this->properties($reflection),
            $this->relationProperties($reflection),
            $this->casts($reflection, $instance),
            $instance->getFillable(),
            $usesSoftDeletes,
            $usesSoftDeletes ? $this->deletedAtColumn($reflection) : null,
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function properties(ReflectionClass $reflection): array
    {
        $docComment = $reflection->getDocComment();
        if ($docComment === false) {
            return [];
        }

        $matched = preg_match_all(
            '/@property(?:-read|-write)?\s+([^\$]+?)\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
            $docComment,
            $matches,
            PREG_SET_ORDER
        );
        if ($matched === false) {
            return [];
        }

        $properties = [];
        foreach ($matches as $match) {
            $properties[$match[2]] = trim($match[1]);
        }

        return $properties;
    }

    /**
     * 关系属性由模型上的关系方法表征，方法名即属性名；据此把关系属性从列属性里摘出来。
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, true>
     */
    private function relationProperties(ReflectionClass $reflection): array
    {
        $relations = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->returnsRelation($method)) {
                $relations[$method->getName()] = true;
            }
        }

        return $relations;
    }

    private function returnsRelation(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType && is_a($returnType->getName(), Relation::class, true)) {
            return true;
        }

        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return false;
        }

        if (preg_match('/@return\s+\\\?([A-Za-z_][A-Za-z0-9_\\\]*)/', $docComment, $matches) !== 1) {
            return false;
        }

        return in_array($matches[1], self::RELATION_TYPES, true);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, string>
     */
    private function casts(ReflectionClass $reflection, AbstractModel $instance): array
    {
        $property = $reflection->getProperty('casts');
        $property->setAccessible(true);

        /* @var array<string, string> $casts */
        return $property->getValue($instance);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function deletedAtColumn(ReflectionClass $reflection): string
    {
        return $reflection->hasConstant('DELETED_AT')
            ? (string) $reflection->getConstant('DELETED_AT')
            : 'deleted_at';
    }
}
