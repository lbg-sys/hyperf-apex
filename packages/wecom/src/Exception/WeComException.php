<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Exception;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Throwable;

/**
 * 企业微信调用失败。
 *
 * 失败分两类，因为它们对调用方的含义完全不同：
 * - 未认证（state 不成立、授权码失效、不是本企业成员）：这次登录不成立，重试无用；
 * - 服务不可用（没配、网络失败、对方报错、响应不可解析）：本次请求失败但稍后可能成功。
 *
 * 把后者混进前者，会让「企微挂了」在客户端和日志里都表现成「登录失败」，排查方向直接跑偏。
 * 因此配置缺失也报 503 而不是 401 —— 报错了方向就等于没有报错。
 *
 * `errmsg` 一律只进日志、不进异常消息：那是对方的技术细节，也可能夹带 corp_id 一类的标识。
 * 原始 errcode 通过 {@see self::getWeComErrorCode()} 与 `getData()['errcode']` 透出，
 * 需要按错误码分支的业务（例如通讯录里「用户不存在」）能拿到它，而不必去解析消息字符串。
 */
final class WeComException extends BusinessException
{
    /**
     * 这些错误码意味着「手上的 access_token 不能用了」，清掉缓存重取一次即可。
     *
     * 被同企业的其它应用顶掉是常态而非异常，因此重取一次就能自愈，不该直接判失败。
     */
    private const ACCESS_TOKEN_INVALID_CODES = [40014, 42001];

    /** 授权码无效或已被使用：这次授权不成立，重试无用。 */
    private const INVALID_CODE_CODES = [40029];

    private function __construct(
        ErrorCode $errorCode,
        string $message,
        private readonly int $weComErrorCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode,
            $message,
            $previous,
            null,
            $weComErrorCode === 0 ? [] : ['errcode' => $weComErrorCode],
        );
    }

    /**
     * 企业没配或没配全（corp_id / secret / agent_id / redirect_uri 缺任意一项都算）。
     */
    public static function notConfigured(string $alias): self
    {
        return new self(ErrorCode::SERVICE_UNAVAILABLE, sprintf('企业微信未配置（%s），请联系管理员', $alias));
    }

    /**
     * 传输失败、响应不可解析，或对方返回了未分类的错误码。
     */
    public static function apiUnavailable(int $weComErrorCode = 0, ?Throwable $previous = null): self
    {
        if (self::isInvalidCode($weComErrorCode)) {
            return new self(ErrorCode::UNAUTHENTICATED, '企业微信授权码已失效，请重新发起登录', $weComErrorCode);
        }

        return new self(ErrorCode::SERVICE_UNAVAILABLE, '企业微信服务暂时不可用，请稍后再试', $weComErrorCode, $previous);
    }

    public static function invalidState(): self
    {
        return new self(ErrorCode::UNAUTHENTICATED, '企业微信授权状态已失效，请重新发起登录');
    }

    /**
     * 调用者是外部联系人（拿到的是 openid 而非 userid），或者不在本应用的可见范围里。
     *
     * 两种情况企微都返回 errcode = 0，只有 userid 为空这一条线索，因此合并成一个错误。
     */
    public static function notAMember(): self
    {
        return new self(ErrorCode::UNAUTHENTICATED, '当前企业微信账号不是本企业成员，或不在应用可见范围内');
    }

    public static function isAccessTokenInvalid(int $weComErrorCode): bool
    {
        return in_array($weComErrorCode, self::ACCESS_TOKEN_INVALID_CODES, true);
    }

    public static function isInvalidCode(int $weComErrorCode): bool
    {
        return in_array($weComErrorCode, self::INVALID_CODE_CODES, true);
    }

    public function getWeComErrorCode(): int
    {
        return $this->weComErrorCode;
    }
}
