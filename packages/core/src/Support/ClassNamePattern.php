<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * 类名通配匹配，语义与 api_doc.excluded / websocket.excluded 一致。
 *
 * 抽出来是为了让「哪些类不进对外文档」这件事只有一份实现：所有对外文档出口
 * 必须按同一份清单过滤，否则两边会漂。
 */
final class ClassNamePattern
{
    /**
     * @param list<string> $patterns 支持 * 通配，匹配完整类名
     */
    public static function matches(array $patterns, string $class): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $class, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
