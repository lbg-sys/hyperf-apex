<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Apex\WebSocket\Session\WsRequestContext;
use PHPUnit\Framework\TestCase;

/**
 * `#[WsRoute]` 的启动期声明校验。
 *
 * 这一层是协议契约的守门人：事件名格式、内置前缀、参数与返回类型推断。写错声明如果放到运行期，
 * 现象是「某个事件永远收不到响应」或者「文档与实现不一致」，而这里能在启动时一次性列清全部问题 ——
 * 用例断言的就是「一次报完，而不是报一个改一个」。
 */
final class WsHandlerRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testValidDeclarationIsRegisteredWithInferredSignature(): void
    {
        WsAnnotationRegistrar::route(ValidHandler::class, 'paid', 'order.paid');

        $registry = new WsHandlerRegistry();
        $definition = $registry->find('order.paid');

        $this->assertNotNull($definition);
        $this->assertSame(RegistryPayloadDTO::class, $definition->payloadClass);
        $this->assertSame(RegistryResultDTO::class, $definition->responseClass);
        $this->assertSame(0, $definition->payloadPosition);
        $this->assertNull($definition->contextPosition);
        $this->assertSame(ValidHandler::class . '::paid', $definition->handlerId());

        $this->assertTrue($registry->has('order.paid'));
        $this->assertSame(['order.paid'], $registry->events());
        $this->assertNull($registry->find('order.unknown'));
    }

    public function testContextParameterIsDetectedAndVoidResponseIsAllowed(): void
    {
        WsAnnotationRegistrar::route(ContextHandler::class, 'track', 'order.track');

        $definition = (new WsHandlerRegistry())->find('order.track');

        $this->assertNotNull($definition);
        $this->assertSame(0, $definition->contextPosition);
        $this->assertSame(1, $definition->payloadPosition);
        // 无响应体是合法声明：不是所有事件都需要回数据。
        $this->assertNull($definition->responseClass);
    }

    public function testEventNameMustUseDomainActionFormat(): void
    {
        WsAnnotationRegistrar::route(ScalarParamHandler::class, 'handle', 'OrderPaid');

        $this->assertDeclarationError('不符合 {domain}.{action} 格式');
    }

    public function testBuiltinPrefixIsReserved(): void
    {
        WsAnnotationRegistrar::route(ScalarParamHandler::class, 'handle', 'sys.paid');

        $this->assertDeclarationError('占用了内置前缀');
    }

    public function testScalarParameterIsRejected(): void
    {
        WsAnnotationRegistrar::route(ScalarParamHandler::class, 'handle', 'order.scalar');

        $this->assertDeclarationError('类型必须是 payload DTO');
    }

    public function testMissingPayloadIsRejected(): void
    {
        WsAnnotationRegistrar::route(NoPayloadHandler::class, 'handle', 'order.none');

        $this->assertDeclarationError('至少需要一个 AbstractDTO 子类参数作为 payload');
    }

    public function testMultiplePayloadParametersAreRejected(): void
    {
        WsAnnotationRegistrar::route(TwoPayloadHandler::class, 'handle', 'order.two');

        $this->assertDeclarationError('有多个 AbstractDTO 参数');
    }

    public function testMultipleContextParametersAreRejected(): void
    {
        WsAnnotationRegistrar::route(TwoContextHandler::class, 'handle', 'order.ctx');

        $this->assertDeclarationError('有多个 WsRequestContext 参数');
    }

    public function testReturnTypeMustBeDtoOrVoid(): void
    {
        WsAnnotationRegistrar::route(BadReturnHandler::class, 'handle', 'order.bad_return');

        $this->assertDeclarationError('返回类型必须是 void、null 或 AbstractDTO 子类');
    }

    public function testDuplicateEventIsRejected(): void
    {
        WsAnnotationRegistrar::route(DuplicateAHandler::class, 'handle', 'order.dup');
        WsAnnotationRegistrar::route(DuplicateBHandler::class, 'handle', 'order.dup');

        $this->assertDeclarationError('重复注册');
    }

    public function testAllDeclarationErrorsAreReportedAtOnce(): void
    {
        // 三处独立问题分布在三个声明上：事件名格式、标量参数、返回类型。
        // 一次列清才能一次改完，否则改一个重启一次。
        WsAnnotationRegistrar::route(NoPayloadHandler::class, 'handle', 'Bad');
        WsAnnotationRegistrar::route(ScalarParamHandler::class, 'handle', 'order.scalar');
        WsAnnotationRegistrar::route(BadReturnHandler::class, 'handle', 'order.bad_return');

        $errors = $this->declarationErrors();

        $this->assertGreaterThanOrEqual(3, count($errors));
        $this->assertStringContainsString('不符合 {domain}.{action} 格式', implode("\n", $errors));
        $this->assertStringContainsString('类型必须是 payload DTO', implode("\n", $errors));
        $this->assertStringContainsString('返回类型必须是 void、null 或 AbstractDTO 子类', implode("\n", $errors));
    }

    private function assertDeclarationError(string $expected): void
    {
        $errors = $this->declarationErrors();

        $this->assertNotSame([], $errors, '声明校验应当失败，但没有抛 WsDeclarationException。');
        $this->assertStringContainsString($expected, implode("\n", $errors), implode("\n", $errors));
    }

    /** @return list<string> */
    private function declarationErrors(): array
    {
        try {
            (new WsHandlerRegistry())->all();
        } catch (WsDeclarationException $exception) {
            return $exception->errors();
        }

        $this->fail('声明校验应当失败，但没有抛 WsDeclarationException。');
    }
}

final readonly class RegistryPayloadDTO extends AbstractDTO
{
    public function __construct(public string $orderId = '')
    {
    }
}

final readonly class RegistryResultDTO extends AbstractDTO
{
    public function __construct(public string $orderId = '')
    {
    }
}

final class ValidHandler
{
    public function paid(RegistryPayloadDTO $payload): RegistryResultDTO
    {
        return new RegistryResultDTO($payload->orderId);
    }
}

final class ContextHandler
{
    public function track(WsRequestContext $context, RegistryPayloadDTO $payload): void
    {
    }
}

final class ScalarParamHandler
{
    public function handle(int $id): void
    {
    }
}

final class NoPayloadHandler
{
    public function handle(): void
    {
    }
}

final class TwoPayloadHandler
{
    public function handle(RegistryPayloadDTO $first, RegistryResultDTO $second): void
    {
    }
}

final class TwoContextHandler
{
    public function handle(WsRequestContext $first, WsRequestContext $second, RegistryPayloadDTO $payload): void
    {
    }
}

final class BadReturnHandler
{
    public function handle(RegistryPayloadDTO $payload): string
    {
        return $payload->orderId;
    }
}

final class DuplicateAHandler
{
    public function handle(RegistryPayloadDTO $payload): void
    {
    }
}

final class DuplicateBHandler
{
    public function handle(RegistryPayloadDTO $payload): void
    {
    }
}
