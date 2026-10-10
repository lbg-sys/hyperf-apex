<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum HttpStatus: int implements EnumInterface
{
    use EnumTrait;

    #[Message('继续')]
    case CONTINUE = 100;

    #[Message('切换协议')]
    case SWITCHING_PROTOCOLS = 101;

    #[Message('处理中')]
    case PROCESSING = 102;

    #[Message('早期提示')]
    case EARLY_HINTS = 103;

    #[Message('请求成功')]
    case OK = 200;

    #[Message('资源创建成功')]
    case CREATED = 201;

    #[Message('请求已接受')]
    case ACCEPTED = 202;

    #[Message('非权威性信息')]
    case NON_AUTHORITATIVE_INFORMATION = 203;

    #[Message('无响应内容')]
    case NO_CONTENT = 204;

    #[Message('重置内容')]
    case RESET_CONTENT = 205;

    #[Message('部分内容')]
    case PARTIAL_CONTENT = 206;

    #[Message('多状态')]
    case MULTI_STATUS = 207;

    #[Message('已报告')]
    case ALREADY_REPORTED = 208;

    #[Message('已使用')]
    case IM_USED = 226;

    #[Message('多种选择')]
    case MULTIPLE_CHOICES = 300;

    #[Message('永久重定向')]
    case MOVED_PERMANENTLY = 301;

    #[Message('临时重定向')]
    case FOUND = 302;

    #[Message('查看其他位置')]
    case SEE_OTHER = 303;

    #[Message('资源未修改')]
    case NOT_MODIFIED = 304;

    #[Message('临时重定向')]
    case TEMPORARY_REDIRECT = 307;

    #[Message('永久重定向')]
    case PERMANENT_REDIRECT = 308;

    #[Message('错误请求')]
    case BAD_REQUEST = 400;

    #[Message('未认证')]
    case UNAUTHORIZED = 401;

    #[Message('需要付款')]
    case PAYMENT_REQUIRED = 402;

    #[Message('禁止访问')]
    case FORBIDDEN = 403;

    #[Message('未找到')]
    case NOT_FOUND = 404;

    #[Message('请求方法不允许')]
    case METHOD_NOT_ALLOWED = 405;

    #[Message('无法满足请求')]
    case NOT_ACCEPTABLE = 406;

    #[Message('需要代理认证')]
    case PROXY_AUTHENTICATION_REQUIRED = 407;

    #[Message('请求超时')]
    case REQUEST_TIMEOUT = 408;

    #[Message('资源状态冲突')]
    case CONFLICT = 409;

    #[Message('资源已删除')]
    case GONE = 410;

    #[Message('缺少必需长度')]
    case LENGTH_REQUIRED = 411;

    #[Message('前置条件失败')]
    case PRECONDITION_FAILED = 412;

    #[Message('请求内容过大')]
    case CONTENT_TOO_LARGE = 413;

    #[Message('请求 URI 过长')]
    case URI_TOO_LONG = 414;

    #[Message('不支持的媒体类型')]
    case UNSUPPORTED_MEDIA_TYPE = 415;

    #[Message('请求范围无法满足')]
    case RANGE_NOT_SATISFIABLE = 416;

    #[Message('预期条件失败')]
    case EXPECTATION_FAILED = 417;

    #[Message('我是一只茶壶')]
    case IM_A_TEAPOT = 418;

    #[Message('请求针对错误目标')]
    case MISDIRECTED_REQUEST = 421;

    #[Message('请求内容无法处理')]
    case UNPROCESSABLE_ENTITY = 422;

    #[Message('资源已锁定')]
    case LOCKED = 423;

    #[Message('依赖失败')]
    case FAILED_DEPENDENCY = 424;

    #[Message('请求过早')]
    case TOO_EARLY = 425;

    #[Message('需要升级协议')]
    case UPGRADE_REQUIRED = 426;

    #[Message('需要前置条件')]
    case PRECONDITION_REQUIRED = 428;

    #[Message('请求过于频繁')]
    case TOO_MANY_REQUESTS = 429;

    #[Message('请求头字段过大')]
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;

    #[Message('因法律原因不可用')]
    case UNAVAILABLE_FOR_LEGAL_REASONS = 451;

    #[Message('服务器内部错误')]
    case INTERNAL_SERVER_ERROR = 500;

    #[Message('功能未实现')]
    case NOT_IMPLEMENTED = 501;

    #[Message('网关错误')]
    case BAD_GATEWAY = 502;

    #[Message('服务暂不可用')]
    case SERVICE_UNAVAILABLE = 503;

    #[Message('网关超时')]
    case GATEWAY_TIMEOUT = 504;

    #[Message('不支持的 HTTP 版本')]
    case HTTP_VERSION_NOT_SUPPORTED = 505;

    #[Message('变体也协商失败')]
    case VARIANT_ALSO_NEGOTIATES = 506;

    #[Message('存储空间不足')]
    case INSUFFICIENT_STORAGE = 507;

    #[Message('检测到循环')]
    case LOOP_DETECTED = 508;

    #[Message('需要网络认证')]
    case NETWORK_AUTHENTICATION_REQUIRED = 511;

    /**
     * @deprecated use INTERNAL_SERVER_ERROR instead
     */
    public const SERVER_ERROR = self::INTERNAL_SERVER_ERROR;

    /**
     * @deprecated use UNPROCESSABLE_ENTITY instead
     */
    public const VALIDATION_ERROR = self::UNPROCESSABLE_ENTITY;

    /**
     * @deprecated use CONTENT_TOO_LARGE instead
     */
    public const PAYLOAD_TOO_LARGE = self::CONTENT_TOO_LARGE;

    /**
     * @deprecated use URI_TOO_LONG instead
     */
    public const REQUEST_URI_TOO_LONG = self::URI_TOO_LONG;

    public function isInformational(): bool
    {
        return $this->value < 200;
    }

    public function isSuccessful(): bool
    {
        return $this->value >= 200 && $this->value < 300;
    }

    public function isRedirect(): bool
    {
        return $this->value >= 300 && $this->value < 400;
    }

    public function isClientError(): bool
    {
        return $this->value >= 400 && $this->value < 500;
    }

    public function isServerError(): bool
    {
        return $this->value >= 500;
    }
}
