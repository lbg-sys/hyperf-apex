<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Notify;

use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Notify\Contract\NotifyChannelInterface;
use Hyperf\Apex\Notify\DTO\NotifyMessageDTO;
use Hyperf\Apex\Notify\DTO\NotifyResultDTO;
use Hyperf\Apex\Notify\DTO\NotifyTargetDTO;
use Hyperf\Apex\Notify\Registry\NotifyChannelRegistry;
use Hyperf\Config\Config;
use Hyperf\Contract\ContainerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * 通知渠道注册表。
 *
 * 三条判据：编码没注册就抛业务异常（而不是静默返回 null，让「渠道没注册」变成空指针）；
 * 配置里写错一个类名要在取用时报一条能看懂的错（而不是调用 code() 时炸 undefined method）；
 * options() 是给管理端下拉的，按 code + 中文名输出。
 */
final class NotifyChannelRegistryTest extends TestCase
{
    public function testGetReturnsTheRegisteredChannel(): void
    {
        $registry = $this->registry([FakeNotifyChannel::class]);

        $this->assertInstanceOf(FakeNotifyChannel::class, $registry->get('fake'));
    }

    public function testGetThrowsForUnregisteredCode(): void
    {
        $registry = $this->registry([FakeNotifyChannel::class]);

        try {
            $registry->get('unknown');
            $this->fail('未注册的渠道编码应当抛业务异常。');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('通知渠道 unknown 未注册', $exception->getMessage());
        }
    }

    public function testHasReportsRegistration(): void
    {
        $registry = $this->registry([FakeNotifyChannel::class]);

        $this->assertTrue($registry->has('fake'));
        $this->assertFalse($registry->has('unknown'));
    }

    public function testOptionsListsCodeAndName(): void
    {
        $registry = $this->registry([FakeNotifyChannel::class]);

        $options = $registry->options();
        $this->assertCount(1, $options);
        $this->assertSame('fake', $options[0]->code);
        $this->assertSame('假渠道', $options[0]->name);
    }

    public function testNonChannelClassIsRejected(): void
    {
        // 配置里写错一个类名，要在取用时报一条能看懂的错，
        // 而不是调用 code() 时炸 Call to undefined method。
        $registry = $this->registry([stdClass::class]);

        try {
            $registry->get('whatever');
            $this->fail('配置里写了非渠道类，应当报错。');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('必须实现', $exception->getMessage());
            $this->assertStringContainsString(NotifyChannelInterface::class, $exception->getMessage());
        }
    }

    public function testNonStringEntriesAreIgnored(): void
    {
        // 非字符串项静默过滤，不打断解析；合法的渠道照常注册。
        $registry = $this->registry([42, FakeNotifyChannel::class, 0]);

        $this->assertTrue($registry->has('fake'));
    }

    /** @param list<int|string> $channels */
    private function registry(array $channels): NotifyChannelRegistry
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return match ($id) {
                    FakeNotifyChannel::class => new FakeNotifyChannel(),
                    stdClass::class => new stdClass(),
                    default => throw new RuntimeException('容器里没有 ' . $id),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [FakeNotifyChannel::class, stdClass::class], true);
            }

            public function make(string $name, array $parameters = []): mixed
            {
                throw new RuntimeException('registry 测试不需要 make');
            }

            public function set(string $name, mixed $entry): void
            {
            }

            public function unbind(string $name): void
            {
            }

            public function define(string $name, mixed $definition): void
            {
            }
        };

        return new NotifyChannelRegistry($container, new Config(['notify' => ['channels' => $channels]]));
    }
}

final class FakeNotifyChannel implements NotifyChannelInterface
{
    public function code(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return '假渠道';
    }

    public function maxMessagesPerMinute(): int
    {
        return 10;
    }

    public function send(NotifyTargetDTO $target, NotifyMessageDTO $message): NotifyResultDTO
    {
        return NotifyResultDTO::success();
    }
}
