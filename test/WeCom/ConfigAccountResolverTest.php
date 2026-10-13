<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use Hyperf\Apex\WeCom\Config\ConfigAccountResolver;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * 多企业配置的读取。
 *
 * 两条最容易出错的：alias 是外部给的标识，拼接点路径能被「点号」穿到别的配置分支；
 * 以及「配了段落但 corp_id 为空」必须等同于没配 —— 否则会在发请求之后才失败。
 */
final class ConfigAccountResolverTest extends TestCase
{
    public function testResolveReturnsTrimmedAccount(): void
    {
        $account = $this->resolver([
            'default' => [
                'corp_id' => ' ww-default ',
                'agent_id' => ' 100001 ',
                'secret' => ' secret ',
                'token' => ' token ',
                'aes_key' => ' aes ',
                'redirect_uri' => ' https://example.com/cb ',
            ],
        ])->resolve('default');

        $this->assertNotNull($account);
        $this->assertSame('default', $account->alias);
        $this->assertSame('ww-default', $account->corpId);
        $this->assertSame('100001', $account->agentId);
        $this->assertSame('secret', $account->secret);
        $this->assertSame('token', $account->token);
        $this->assertSame('aes', $account->aesKey);
        $this->assertSame('https://example.com/cb', $account->redirectUri);
        $this->assertTrue($account->canCallApi());
    }

    public function testUnknownAliasIsNull(): void
    {
        $this->assertNull($this->resolver(['default' => ['corp_id' => 'ww']])->resolve('sales'));
    }

    public function testBlankCorpIdMeansNotConfigured(): void
    {
        $this->assertNull($this->resolver(['default' => ['corp_id' => '  ']])->resolve('default'));
        $this->assertNull($this->resolver(['default' => ['secret' => 'x']])->resolve('default'));
        $this->assertNull($this->resolver([])->resolve('default'));
    }

    public function testAliasWithDotDoesNotReadNestedConfig(): void
    {
        $resolver = $this->resolver(['default' => ['corp_id' => 'ww-default']]);

        // 拼 'wecom.accounts.{alias}' 的实现会在这里读到 'default' 的 corp_id。
        $this->assertNull($resolver->resolve('default.corp_id'));
        $this->assertNull($resolver->resolve('default.nothing'));
    }

    public function testMissingSecretIsNotCallable(): void
    {
        $account = $this->resolver(['default' => ['corp_id' => 'ww', 'secret' => '']])->resolve('default');

        $this->assertNotNull($account);
        $this->assertFalse($account->canCallApi());
    }

    public function testDefaultAliasFallsBackWhenBlank(): void
    {
        $this->assertSame('sales', $this->resolver([], 'sales')->defaultAlias());
        $this->assertSame('default', $this->resolver([], '  ')->defaultAlias());
        $this->assertSame('default', $this->resolver([])->defaultAlias());
    }

    /**
     * @param array<string, mixed> $accounts
     */
    private function resolver(array $accounts, string $default = 'default'): ConfigAccountResolver
    {
        return new ConfigAccountResolver(new Config([
            'wecom' => [
                'default' => $default,
                'accounts' => $accounts,
            ],
        ]));
    }
}
