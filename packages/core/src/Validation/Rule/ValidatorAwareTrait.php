<?php

declare(strict_types=1);

namespace Hyperf\Apex\Validation\Rule;

use Hyperf\Validation\Validator;

/**
 * ValidatorAwareRule 的通用实现。
 *
 * Hyperf 准备校验器时回调 setValidator()，规则对象借它拿到 translator 去取语言包文案。
 * 多个规则对象都要写同一段样板，集中在这里，避免逐类复制。
 */
trait ValidatorAwareTrait
{
    private Validator $validator;

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }
}
