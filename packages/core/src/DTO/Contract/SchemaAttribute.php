<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

use Hyperf\Apex\DTO\Schema\SchemaFragment;

interface SchemaAttribute
{
    /**
     * 产出该 Attribute 对字段 Schema 的贡献。
     *
     * 实现约定：片段必须在本方法体内构建，不要在构造函数里预先算好。
     * 原因是 DTORuleFactory 在 HTTP 请求热路径上会对每个 Attribute 执行 newInstance()，
     * 包括纯文档用途的 Attribute —— 构造函数变重会直接拖慢生产请求。
     * 同理，参数合法性校验也应留在本方法内，让错误停在文档构建期而非请求期。
     */
    public function fragment(): SchemaFragment;
}
