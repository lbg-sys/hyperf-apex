<?php

declare(strict_types=1);

use Hyperf\Apex\Notify\Channel\WecomRobotChannel;

use function Hyperf\Support\env;

return [
    /*
     * 渠道适配器类列表，按 code() 建索引。
     *
     * 新增渠道 = 新增一个 NotifyChannelInterface 实现 + 在此追加类名，
     * 不需要改动注册表、投递进程或任何调用方。
     */
    'channels' => [
        WecomRobotChannel::class,
    ],

    /* 出站 HTTP 默认值。渠道配置未显式指定时使用。 */
    'http' => [
        'connect_timeout_seconds' => (int) env('NOTIFY_HTTP_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('NOTIFY_HTTP_TIMEOUT_SECONDS', 10),
        'verify_tls' => (bool) env('NOTIFY_HTTP_VERIFY_TLS', true),
    ],

    /*
     * 企业微信群机器人的协议参数（来源：官方「消息推送配置说明」）。
     *
     * 内容长度上限按消息类型区分，不能共用一个值：
     * - text 最长 2048 字节；
     * - markdown 最长 4096 字节。
     *
     * text 是「需要 @ 成员」时走的分支，用 markdown 的上限去发 text，
     * 超过 2048 字节会被企微以 errcode 45002 直接拒掉（官方不会自动截断，只有 news 的 title 会）。
     *
     * 两个值都有 256 字节的硬下限（见 WecomRobotChannel::MIN_CONTENT_BYTES），配更小不生效，
     * 以免把正文截成不可读。上限按字节计算，截断不会切碎多字节字符。
     */
    'wecom' => [
        'max_text_bytes' => (int) env('NOTIFY_WECOM_MAX_TEXT_BYTES', 2048),
        'max_markdown_bytes' => (int) env('NOTIFY_WECOM_MAX_MARKDOWN_BYTES', 4096),

        /*
         * 图片：官方上限是 base64 编码前 2M，格式限 JPG / PNG。
         * 这里只卡大小，格式交给企微判定——格式错误会返回明确的 errcode，
         * 自己再实现一遍图片格式嗅探没有收益。
         */
        'max_image_bytes' => (int) env('NOTIFY_WECOM_MAX_IMAGE_BYTES', 2097152),

        /*
         * 文件：官方上限 20M。这里是**协议上限**，告警侧还有更严的策略上限
         * （见 alert.php 的 attachment.max_file_bytes），因为告警附件要随告警一起落库。
         */
        'max_file_bytes' => (int) env('NOTIFY_WECOM_MAX_FILE_BYTES', 20971520),
    ],
];
