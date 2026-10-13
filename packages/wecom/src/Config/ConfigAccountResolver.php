<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Config;

use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;
use Hyperf\Contract\ConfigInterface;

/**
 * 从 `config/autoload/wecom.php` 读企业配置。
 *
 * 取值一律走 `wecom.accounts` 整体取回再按键索引，而不是拼 `wecom.accounts.{alias}` 这样的点路径：
 * alias 是外部（配置、后台）给的标识，一旦它带点号就会读到别的配置分支上去。
 */
final class ConfigAccountResolver implements AccountResolverInterface
{
    /** alias 未配置时的兜底，与配置模板里的默认企业键名保持一致。 */
    private const DEFAULT_ALIAS = 'default';

    public function __construct(
        private readonly ConfigInterface $config,
    ) {
    }

    public function resolve(string $alias): ?WeComAccountDTO
    {
        $accounts = $this->config->get('wecom.accounts', []);
        $raw = is_array($accounts) ? ($accounts[$alias] ?? null) : null;
        if (! is_array($raw)) {
            return null;
        }

        $corpId = trim((string) ($raw['corp_id'] ?? ''));
        if ($corpId === '') {
            // 配了段落但没填 corp_id：等同于没配这个企业。
            return null;
        }

        /* @var array<string, mixed> $raw */
        return new WeComAccountDTO(
            alias: $alias,
            corpId: $corpId,
            agentId: trim((string) ($raw['agent_id'] ?? '')),
            secret: trim((string) ($raw['secret'] ?? '')),
            token: trim((string) ($raw['token'] ?? '')),
            aesKey: trim((string) ($raw['aes_key'] ?? '')),
            redirectUri: trim((string) ($raw['redirect_uri'] ?? '')),
        );
    }

    public function defaultAlias(): string
    {
        $alias = trim((string) $this->config->get('wecom.default', ''));

        return $alias === '' ? self::DEFAULT_ALIAS : $alias;
    }
}
