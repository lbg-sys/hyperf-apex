<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromBody;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\Http\ArgumentResolver\FromBodyArgumentResolver;
use Hyperf\Apex\Http\ArgumentResolver\FromQueryArgumentResolver;
use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Translation\FileLoader;
use Hyperf\Translation\Translator;
use Hyperf\Validation\ValidatorFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * 标量参数的两个取值来源：表单式（`#[FromQuery]` / `#[FromBody]`）。
 *
 * 钉住两条容易与 DTO 路径分叉的口径：
 * - 可空参数拿到空值（`?page=`）应当落成 null，而不是 400；
 * - `name` 对 DTO 参数同样生效（不写 name 时整个 query / body 才是来源）。
 */
final class ArgumentResolverTest extends TestCase
{
    public function testNullableQueryParamAcceptsEmptyValue(): void
    {
        $value = $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'nullableQuery'),
            (new Request('GET', '/fixture'))->withQueryParams(['page' => '']),
            [],
        );

        $this->assertNull($value);
    }

    public function testNonNullableQueryParamStillRejectsEmptyValue(): void
    {
        // 不可空参数拿到空值仍然是参数错误：这里不能「悄悄放过」，否则会把非法输入带进业务。
        $this->expectException(BusinessException::class);

        $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'requiredQuery'),
            (new Request('GET', '/fixture'))->withQueryParams(['page' => '']),
            [],
        );
    }

    public function testNullableQueryParamWithoutDefaultStillBecomesNull(): void
    {
        $value = $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'nullableWithoutDefault'),
            (new Request('GET', '/fixture'))->withQueryParams(['page' => '']),
            [],
        );

        $this->assertNull($value);
    }

    public function testDtoQueryParamReadsTheNamedSubKey(): void
    {
        $value = $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'namedDtoQuery'),
            (new Request('GET', '/fixture'))->withQueryParams([
                'filter' => ['keyword' => 'abc'],
                'keyword' => 'ignored',
            ]),
            [],
        );

        $this->assertInstanceOf(QueryFilterDTO::class, $value);
        $this->assertSame('abc', $value->keyword);
    }

    public function testDtoQueryParamWithoutNameUsesTheWholeQuery(): void
    {
        $value = $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'wholeQueryDto'),
            (new Request('GET', '/fixture'))->withQueryParams(['keyword' => 'abc']),
            [],
        );

        $this->assertInstanceOf(QueryFilterDTO::class, $value);
        $this->assertSame('abc', $value->keyword);
    }

    public function testNullableBodyParamAcceptsEmptyValue(): void
    {
        $value = $this->bodyResolver()->resolveArgument(
            $this->parameter(FromBodyFixtureController::class, 'nullableBody'),
            (new Request('POST', '/fixture'))->withParsedBody(['page' => '']),
            [],
        );

        $this->assertNull($value);
    }

    public function testDtoBodyParamReadsTheNamedSubKey(): void
    {
        $value = $this->bodyResolver()->resolveArgument(
            $this->parameter(FromBodyFixtureController::class, 'namedDtoBody'),
            (new Request('POST', '/fixture'))->withParsedBody(['payload' => ['keyword' => 'abc']]),
            [],
        );

        $this->assertInstanceOf(QueryFilterDTO::class, $value);
        $this->assertSame('abc', $value->keyword);
    }

    public function testScalarQueryParamIsCastToDeclaredType(): void
    {
        $value = $this->queryResolver()->resolveArgument(
            $this->parameter(FromQueryFixtureController::class, 'requiredQuery'),
            (new Request('GET', '/fixture'))->withQueryParams(['page' => '7']),
            [],
        );

        $this->assertSame(7, $value);
    }

    private function queryResolver(): FromQueryArgumentResolver
    {
        return new FromQueryArgumentResolver($this->hydrator());
    }

    private function bodyResolver(): FromBodyArgumentResolver
    {
        return new FromBodyArgumentResolver($this->hydrator());
    }

    private function hydrator(): RequestDataHydrator
    {
        $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/runtime/lang'), 'zh_CN');

        return new RequestDataHydrator(new ValidatorFactory($translator));
    }

    private function parameter(string $class, string $method): ReflectionParameter
    {
        return (new ReflectionMethod($class, $method))->getParameters()[0];
    }
}

final class FromQueryFixtureController
{
    public function nullableQuery(#[FromQuery] ?int $page = null): void
    {
    }

    public function nullableWithoutDefault(#[FromQuery] ?int $page): void
    {
    }

    public function requiredQuery(#[FromQuery] int $page): void
    {
    }

    public function namedDtoQuery(#[FromQuery('filter')] QueryFilterDTO $filter): void
    {
    }

    public function wholeQueryDto(#[FromQuery] QueryFilterDTO $payload): void
    {
    }
}

final class FromBodyFixtureController
{
    public function nullableBody(#[FromBody] ?int $page = null): void
    {
    }

    public function namedDtoBody(#[FromBody('payload')] QueryFilterDTO $payload): void
    {
    }
}

final readonly class QueryFilterDTO extends AbstractDTO
{
    public function __construct(public string $keyword = '')
    {
    }
}
