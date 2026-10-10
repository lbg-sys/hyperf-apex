<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Channel;

use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Apex\Enum\NotifyChannelCode;
use Hyperf\Apex\Notify\Contract\NotifyChannelInterface;
use Hyperf\Apex\Notify\DTO\NotifyAttachmentDTO;
use Hyperf\Apex\Notify\DTO\NotifyMentionDTO;
use Hyperf\Apex\Notify\DTO\NotifyMessageDTO;
use Hyperf\Apex\Notify\DTO\NotifyResultDTO;
use Hyperf\Apex\Notify\DTO\NotifyTargetDTO;
use Hyperf\Apex\Notify\Enum\NotifyMessageFormat;
use Hyperf\Apex\Notify\Enum\NotifyOutcome;
use Hyperf\Apex\Notify\Enum\NotifySeverity;
use Hyperf\Apex\Outbound\OutboundRecorder;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use JsonException;

use function mb_strcut;
use function mb_substr;

/**
 * 企业微信群机器人渠道。
 *
 * 支持六种消息类型：text / markdown / markdown_v2 / image / news / file
 * （官方另有 voice 与 template_card，与告警用途无关，不实现）。
 *
 * 这些参数全部来自官方文档「消息推送配置说明」与「全局错误码」：
 *
 * | 项 | 官方值 |
 * | --- | --- |
 * | 频率限制 | 每个消息推送（webhook）20 条/分钟 |
 * | `text` 正文 | 最长 2048 字节 |
 * | `markdown` / `markdown_v2` 正文 | 最长 4096 字节 |
 * | 图片 | base64 编码前 ≤ 2M，格式 JPG / PNG |
 * | 图文 | 1~8 条，`title` 128 字节与 `description` 512 字节由企微**自动截断** |
 * | 文件 | 素材上传换 `media_id`，上限 20M；`media_id` 三天有效且只能被对应 webhook 使用 |
 * | `@` 能力 | `text` 有 `mentioned_list` 字段；`text`/`markdown` 支持 content 内联 `<@userid>`；**`markdown_v2` 两者都不支持** |
 *
 * 三条最容易做错的地方：
 * 1. **HTTP 200 不等于发送成功**，错误都在响应体的 `errcode` 里；
 * 2. **限流（45009）不是失败**，归一成 THROTTLED 由调用方推后重发，不消耗重试次数；
 * 3. **超长不会自动截断**（只有 news 的 title/description 会），text / markdown 超长直接返回 45002。
 */
final class WecomRobotChannel implements NotifyChannelInterface
{
    /** 请求成功 */
    private const ERRCODE_SUCCESS = 0;

    /** 接口调用超过限制；官方说明「分钟级限制在触发后 1 分钟自动解除」 */
    private const ERRCODE_FREQ_OUT_OF_LIMIT = 45009;

    /** 官方限制：每个 webhook 每分钟最多 20 条 */
    private const MAX_MESSAGES_PER_MINUTE = 20;

    /** 官方限制：text 正文最长 2048 字节 */
    private const MAX_TEXT_BYTES = 2048;

    /** 官方限制：markdown / markdown_v2 正文最长 4096 字节 */
    private const MAX_MARKDOWN_BYTES = 4096;

    /** 官方限制：图片 base64 编码前不超过 2M */
    private const MAX_IMAGE_BYTES = 2097152;

    /** 官方限制：普通文件不超过 20M */
    private const MAX_FILE_BYTES = 20971520;

    /** 官方限制：一条图文消息支持 1~8 条图文 */
    private const MAX_ARTICLES = 8;

    /** 配置下限保护：低于此值正文会被截成不可读 */
    private const MIN_CONTENT_BYTES = 256;

    /** 响应体留痕长度；超出只截断存储，不影响成功判定 */
    private const RESPONSE_SUMMARY_BYTES = 4000;

    /** 正文超长时的截断提示 */
    private const TRUNCATED_NOTICE = "\n\n…（内容过长已截断）";

    /** 企微素材上传接口在 form-data 里的文件标识名，写错文件永远发不出去 */
    private const UPLOAD_FIELD_NAME = 'media';

    /**
     * 错误码中文说明（官方「全局错误码」）。
     *
     * 只用于把错误信息写得更可读——分类上它们全部属于 PERMANENT，
     * 因为原因都在渠道配置或消息内容里，重试不会改变结果。
     */
    private const ERRCODE_MESSAGES = [
        45002 => '消息内容大小超过限制',
        93000 => 'webhook 地址不合法，或机器人已被移出群',
        93001 => '当前群聊不允许推送消息',
        93004 => '消息推送已被停用',
        93006 => '不合法的群 ID',
        93008 => '机器人不在群里',
        93017 => '发消息的请求内容不能为空',
        93018 => '图片大小超过限制',
        93019 => '不合法的机器人 ID',
        93050 => 'response_type 字段不合法',
    ];

    private readonly int $maxTextBytes;

    private readonly int $maxMarkdownBytes;

    private readonly int $maxImageBytes;

    private readonly int $maxFileBytes;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        ConfigInterface $config,
    ) {
        $this->maxTextBytes = self::clamp((int) $config->get('notify.wecom.max_text_bytes', self::MAX_TEXT_BYTES));
        $this->maxMarkdownBytes = self::clamp((int) $config->get('notify.wecom.max_markdown_bytes', self::MAX_MARKDOWN_BYTES));
        $this->maxImageBytes = max(1, (int) $config->get('notify.wecom.max_image_bytes', self::MAX_IMAGE_BYTES));
        $this->maxFileBytes = max(1, (int) $config->get('notify.wecom.max_file_bytes', self::MAX_FILE_BYTES));
    }

    public function code(): string
    {
        return NotifyChannelCode::WECOM_ROBOT->value;
    }

    public function name(): string
    {
        return NotifyChannelCode::WECOM_ROBOT->getMessage();
    }

    public function maxMessagesPerMinute(): int
    {
        return self::MAX_MESSAGES_PER_MINUTE;
    }

    public function send(NotifyTargetDTO $target, NotifyMessageDTO $message): NotifyResultDTO
    {
        $invalidTarget = $this->assertTarget($target);
        if ($invalidTarget instanceof NotifyResultDTO) {
            return $invalidTarget;
        }

        // 文件要先换 media_id，多一条请求
        if ($this->effectiveFormat($message) === NotifyMessageFormat::FILE) {
            return $this->sendFile($target, $message);
        }

        $payload = $this->buildPayload($message);
        if ($payload instanceof NotifyResultDTO) {
            return $payload;
        }

        return $this->dispatch($target, $target->webhookUrl, ['json' => $payload])['result'];
    }

    /**
     * 校验目标地址确实指向企业微信、且带上了 key。
     *
     * 渠道编码声明了「这条报文是按企微协议拼的」，但如果地址可以被配置成任意域名，
     * 报文就会连同告警内容一起发到那个域名去——把企微 URL 换成飞书 URL 粘贴错，
     * 表现是一段看不懂的错误，而不是「地址填错了」。
     *
     * 返回 null 表示校验通过。
     */
    private function assertTarget(NotifyTargetDTO $target): ?NotifyResultDTO
    {
        $scheme = strtolower((string) parse_url($target->webhookUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($target->webhookUrl, PHP_URL_HOST));

        if ($scheme !== 'https') {
            return NotifyResultDTO::permanent('企业微信 webhook 必须使用 https');
        }

        if ($host !== 'weixin.qq.com' && ! str_ends_with($host, '.weixin.qq.com')) {
            return NotifyResultDTO::permanent(sprintf('webhook 域名 %s 不是企业微信域名', $host));
        }

        if ($this->webhookKey($target) === '') {
            return NotifyResultDTO::permanent('webhook 地址缺少 key 参数');
        }

        return null;
    }

    /** @return array<string, mixed>|NotifyResultDTO 返回 NotifyResultDTO 表示参数不合法 */
    private function buildPayload(NotifyMessageDTO $message): array|NotifyResultDTO
    {
        return match ($this->effectiveFormat($message)) {
            NotifyMessageFormat::TEXT => $this->textPayload($message),
            NotifyMessageFormat::MARKDOWN => $this->markdownPayload($message, 'markdown'),
            NotifyMessageFormat::MARKDOWN_V2 => $this->markdownPayload($message, 'markdown_v2'),
            NotifyMessageFormat::IMAGE => $this->imagePayload($message),
            NotifyMessageFormat::NEWS => $this->newsPayload($message),
            NotifyMessageFormat::FILE => NotifyResultDTO::permanent('文件类型需要先上传素材，不会走到报文构建'),
        };
    }

    /**
     * 解析 `@` 需求，必要时降级消息类型。
     *
     * 官方的三种 `@` 途径能力不同，所以「要 @ 人」在不同类型下的代价不同：
     *
     * | 类型 | 可用途径 |
     * | --- | --- |
     * | `text` | `mentioned_list` / `mentioned_mobile_list` 字段 |
     * | `markdown` | content 内联 `<@userid>`（没有字段） |
     * | `markdown_v2` | **两者都不支持** |
     *
     * 因此：
     * - markdown 只在「全部能表达成 userid」时保留原类型，内联注入即可，排版不丢；
     * - markdown 带手机号或 `@all`、以及 markdown_v2 带任何 @ 时，只能降级为 text——
     *   这些形式只有 text 的字段能表达。降级在渠道内部完成，不把能力差异暴露给调用方。
     */
    private function effectiveFormat(NotifyMessageDTO $message): NotifyMessageFormat
    {
        if (! $message->hasMentions() || ! $message->format->isTextLike()) {
            return $message->format;
        }

        $mentions = $message->mentions;
        if (! $mentions instanceof NotifyMentionDTO) {
            return $message->format;
        }

        $onlyUserIds = ! $mentions->all && $mentions->mobiles === [] && $mentions->userIds !== [];

        return match ($message->format) {
            NotifyMessageFormat::TEXT => NotifyMessageFormat::TEXT,
            NotifyMessageFormat::MARKDOWN => $onlyUserIds ? NotifyMessageFormat::MARKDOWN : NotifyMessageFormat::TEXT,
            // v2 明确不支持 `<@userid>`，带 @ 只能换成 text
            NotifyMessageFormat::MARKDOWN_V2 => NotifyMessageFormat::TEXT,
            default => $message->format,
        };
    }

    /** @return array<string, mixed> */
    private function textPayload(NotifyMessageDTO $message): array
    {
        $text = ['content' => $this->truncate($this->composeLines($message, false), $this->maxTextBytes)];

        $mentions = $message->mentions;
        if ($mentions === null || $mentions->isEmpty()) {
            return ['msgtype' => 'text', 'text' => $text];
        }

        // @all 优先于具体成员：两者同时给出时以「全体」为准
        if ($mentions->all) {
            $text['mentioned_list'] = ['@all'];
        } elseif ($mentions->userIds !== []) {
            $text['mentioned_list'] = $mentions->userIds;
        }
        if ($mentions->mobiles !== []) {
            $text['mentioned_mobile_list'] = $mentions->mobiles;
        }

        return ['msgtype' => 'text', 'text' => $text];
    }

    /** @return array<string, mixed> */
    private function markdownPayload(NotifyMessageDTO $message, string $msgtype): array
    {
        // markdown 没有 mentioned_list 字段，@ 只能内联进 content
        $suffix = $msgtype === 'markdown' && $message->mentions instanceof NotifyMentionDTO
            ? $this->inlineMentionSuffix($message->mentions)
            : '';

        return [
            'msgtype' => $msgtype,
            $msgtype => [
                'content' => $this->truncate($this->composeLines($message, true, $suffix), $this->maxMarkdownBytes),
            ],
        ];
    }

    /** @return array<string, mixed>|NotifyResultDTO */
    private function imagePayload(NotifyMessageDTO $message): array|NotifyResultDTO
    {
        $attachment = $message->attachment;
        if (! $attachment instanceof NotifyAttachmentDTO) {
            return NotifyResultDTO::permanent('图片类型缺少附件内容');
        }

        if ($attachment->size() > $this->maxImageBytes) {
            return NotifyResultDTO::permanent(sprintf(
                '图片大小 %d 字节超过上限 %d 字节',
                $attachment->size(),
                $this->maxImageBytes
            ));
        }

        return [
            'msgtype' => 'image',
            'image' => [
                'base64' => base64_encode($attachment->content),
                // md5 取的是 base64 编码**前**的原始内容
                'md5' => md5($attachment->content),
            ],
        ];
    }

    /** @return array<string, mixed>|NotifyResultDTO */
    private function newsPayload(NotifyMessageDTO $message): array|NotifyResultDTO
    {
        $count = count($message->articles);
        if ($count < 1 || $count > self::MAX_ARTICLES) {
            return NotifyResultDTO::permanent(sprintf('图文消息需要 1~%d 条图文，当前 %d 条', self::MAX_ARTICLES, $count));
        }

        $articles = [];
        foreach ($message->articles as $article) {
            if (trim($article->url) === '') {
                return NotifyResultDTO::permanent('图文消息的跳转链接不能为空');
            }

            // title / description 超长由企微自动截断，这里不做处理
            $item = ['title' => $article->title, 'url' => $article->url];
            if ($article->description !== '') {
                $item['description'] = $article->description;
            }
            if ($article->picUrl !== null && $article->picUrl !== '') {
                $item['picurl'] = $article->picUrl;
            }

            $articles[] = $item;
        }

        return ['msgtype' => 'news', 'news' => ['articles' => $articles]];
    }

    /**
     * 文件类型：先上传素材换 media_id，再用 media_id 发消息。
     *
     * 每次投递都重新上传，不做 media_id 缓存：它三天有效、且**只能被对应 webhook 使用**，
     * 缓存它要额外维护「渠道 × 文件」的状态；告警是每分钟几条的量级，不值得。
     */
    private function sendFile(NotifyTargetDTO $target, NotifyMessageDTO $message): NotifyResultDTO
    {
        $attachment = $message->attachment;
        if (! $attachment instanceof NotifyAttachmentDTO) {
            return NotifyResultDTO::permanent('文件类型缺少附件内容');
        }
        if ($attachment->name === '') {
            return NotifyResultDTO::permanent('文件类型缺少文件名（企微用它控制消息里展示的文件名）');
        }
        if ($attachment->size() > $this->maxFileBytes) {
            return NotifyResultDTO::permanent(sprintf(
                '文件大小 %d 字节超过上限 %d 字节',
                $attachment->size(),
                $this->maxFileBytes
            ));
        }

        $uploaded = $this->dispatch($target, $this->uploadUrl($target), [
            'multipart' => [[
                'name' => self::UPLOAD_FIELD_NAME,
                'contents' => $attachment->content,
                'filename' => $attachment->name,
                'headers' => ['Content-Type' => $attachment->mimeType === '' ? 'application/octet-stream' : $attachment->mimeType],
            ]],
        ]);
        if (! $uploaded['result']->isSuccess()) {
            return $uploaded['result'];
        }

        $mediaId = (string) ($uploaded['decoded']['media_id'] ?? '');
        if ($mediaId === '') {
            return NotifyResultDTO::permanent('企微素材上传成功但未返回 media_id');
        }

        return $this->dispatch($target, $target->webhookUrl, ['json' => [
            'msgtype' => 'file',
            'file' => ['media_id' => $mediaId],
        ]])['result'];
    }

    /**
     * 文本类正文：标题行 + 正文 + 链接。
     *
     * text 与 markdown 只在两处不同——标题是否加粗、链接是否写成 markdown 语法，
     * 因此共用一份拼装逻辑。`$titleSuffix` 供 markdown 追加内联 `<@userid>`。
     */
    private function composeLines(NotifyMessageDTO $message, bool $markdown, string $titleSuffix = ''): string
    {
        $title = sprintf('%s %s', $this->severityPrefix($message->severity), $message->title);
        $lines = [$markdown ? sprintf('**%s**%s', $title, $titleSuffix) : $title . $titleSuffix];
        if (trim($message->content) !== '') {
            $lines[] = $message->content;
        }
        if ($message->linkUrl !== null && $message->linkUrl !== '') {
            // text 报文不解析 markdown，链接必须写成裸文本
            $lines[] = $markdown ? sprintf('[查看详情](%s)', $message->linkUrl) : sprintf('查看详情：%s', $message->linkUrl);
        }

        return implode("\n\n", $lines);
    }

    private function inlineMentionSuffix(NotifyMentionDTO $mentions): string
    {
        $parts = [];
        foreach ($mentions->userIds as $userId) {
            $parts[] = sprintf('<@%s>', $userId);
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    private function severityPrefix(NotifySeverity $severity): string
    {
        return match ($severity) {
            NotifySeverity::INFO => 'ℹ️',
            NotifySeverity::WARNING => '⚠️',
            NotifySeverity::CRITICAL => '🔴',
        };
    }

    /**
     * 记录出站指标后把结果交给调用方。
     *
     * 埋点包在归一化方法的唯一入口上，而不是在每个 return 分支里各记一次 ——
     * 下面那个方法有六个出口，逐个埋点迟早会漏掉新增的那一个。
     *
     * @param array<string, mixed> $options
     *
     * @return array{result: NotifyResultDTO, decoded: array<string, mixed>}
     */
    private function dispatch(NotifyTargetDTO $target, string $url, array $options): array
    {
        $startedAt = hrtime(true);
        $outcome = $this->attempt($target, $url, $options);

        OutboundRecorder::record(
            OutboundRecorder::TARGET_WECOM_ROBOT,
            self::metricResult($outcome['result']),
            OutboundRecorder::secondsSince($startedAt),
        );

        return $outcome;
    }

    /**
     * 把渠道结果折成出站指标的三档口径。
     *
     * 企微本身能区分「可重试」与「不可重试」，这里刻意不沿用：跨出口可比才是这套指标的价值。
     * 只有限流单独成档 —— 它意味着对端在限流，而不是链路坏了。
     */
    private static function metricResult(NotifyResultDTO $result): string
    {
        return match ($result->outcome) {
            NotifyOutcome::SUCCESS => OutboundRecorder::RESULT_SUCCESS,
            NotifyOutcome::THROTTLED => OutboundRecorder::RESULT_THROTTLED,
            NotifyOutcome::RETRYABLE, NotifyOutcome::PERMANENT => OutboundRecorder::RESULT_FAILURE,
        };
    }

    /**
     * 发一次请求并把响应归一成结果。
     *
     * 素材上传与发消息共用同一套状态码与 `errcode` 判定，因此收敛到一个方法里；
     * 上传还要读响应体的 `media_id`，所以一并把解析后的响应体返回给调用方。
     *
     * @param array<string, mixed> $options
     *
     * @return array{result: NotifyResultDTO, decoded: array<string, mixed>}
     */
    private function attempt(NotifyTargetDTO $target, string $url, array $options): array
    {
        try {
            $response = $this->clientFactory->create()->post($url, array_merge([
                // 4xx / 5xx 不抛异常，交给下面按状态码分类
                'http_errors' => false,
                // 禁止跟随跳转：请求目的地来自渠道配置，不应被第三方重定向带走
                'allow_redirects' => false,
                // 置空代理：避免进程级 http_proxy 环境变量改写请求目的地
                'proxy' => '',
                'verify' => $target->verifyTls,
                'timeout' => max(1, $target->timeoutSeconds),
                'connect_timeout' => max(1, $target->connectTimeoutSeconds),
            ], $options));
        } catch (GuzzleException $exception) {
            // 超时、连接失败、DNS 失败：都值得重试
            return ['result' => NotifyResultDTO::retryable($exception->getMessage()), 'decoded' => []];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $summary = mb_substr($body, 0, self::RESPONSE_SUMMARY_BYTES);

        if ($status >= 500 || $status === 429) {
            return ['result' => NotifyResultDTO::retryable(sprintf('企微返回 HTTP %d', $status), $summary), 'decoded' => []];
        }
        if ($status < 200 || $status >= 300) {
            // 4xx：地址写错或被网关拒绝，重试一万次也是同样的结果
            return ['result' => NotifyResultDTO::permanent(sprintf('企微返回 HTTP %d', $status), $summary), 'decoded' => []];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'result' => NotifyResultDTO::retryable('企微响应不是合法 JSON：' . $exception->getMessage(), $summary),
                'decoded' => [],
            ];
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $errcode = (int) ($decoded['errcode'] ?? -1);
        $errmsg = (string) ($decoded['errmsg'] ?? '');

        if ($errcode === self::ERRCODE_SUCCESS) {
            return ['result' => NotifyResultDTO::success(null, $summary), 'decoded' => $decoded];
        }

        if ($errcode === self::ERRCODE_FREQ_OUT_OF_LIMIT) {
            // 企微的限流响应不带等待时长，返回 null 由调用方套用默认等待策略（官方：1 分钟后自动解除）
            return [
                'result' => NotifyResultDTO::throttled(null, sprintf('企微限流：%s', $errmsg), $summary),
                'decoded' => $decoded,
            ];
        }

        /*
         * 其余错误码一律不可重试，不做白名单。
         *
         * 机器人相关的错误码（93000 webhook 失效、93004 推送被停用、93017 内容为空……）
         * 原因都在配置或内容里，重试多少次都是同样的结果；
         * 而漏判一个可重试的错误，代价是无限重试一个必然失败的请求——
         * 两种错法的代价不对称，未知情况取保守的一支。
         */
        $known = self::ERRCODE_MESSAGES[$errcode] ?? null;

        return [
            'result' => NotifyResultDTO::permanent(
                $known === null
                    ? sprintf('企微返回 errcode %d：%s', $errcode, $errmsg)
                    : sprintf('企微返回 errcode %d（%s）：%s', $errcode, $known, $errmsg),
                $summary
            ),
            'decoded' => $decoded,
        ];
    }

    /**
     * 素材上传地址：与发消息同源同域，只换路径并带上 webhook 的 key。
     *
     * 域名取自 webhook 地址（已由 assertTarget 校验过是企微域名），不硬编码，
     * 避免协议里再出现一处需要同步维护的主机名。
     */
    private function uploadUrl(NotifyTargetDTO $target): string
    {
        $parsed = parse_url($target->webhookUrl);
        $scheme = (string) ($parsed['scheme'] ?? 'https');
        $host = (string) ($parsed['host'] ?? '');

        return sprintf(
            '%s://%s/cgi-bin/webhook/upload_media?type=file&key=%s',
            $scheme,
            $host,
            rawurlencode($this->webhookKey($target))
        );
    }

    private function webhookKey(NotifyTargetDTO $target): string
    {
        parse_str((string) parse_url($target->webhookUrl, PHP_URL_QUERY), $query);

        return trim((string) ($query['key'] ?? ''));
    }

    /**
     * 按字节截断正文。
     *
     * 用 mb_strcut 而不是 substr：后者会把多字节字符切成半个，
     * 产生乱码甚至让企微判定内容非法。截断时预留提示语的空间，保证结果不越过上限。
     */
    private function truncate(string $content, int $limit): string
    {
        if (strlen($content) <= $limit) {
            return $content;
        }

        $available = max(1, $limit - strlen(self::TRUNCATED_NOTICE));

        return mb_strcut($content, 0, $available, 'UTF-8') . self::TRUNCATED_NOTICE;
    }

    private static function clamp(int $bytes): int
    {
        return max(self::MIN_CONTENT_BYTES, $bytes);
    }
}
