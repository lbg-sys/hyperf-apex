<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->bigIncrements('id')->comment('幂等记录 ID');
            $table->char('caller_hash', 64)->comment('调用方标识 SHA-256 摘要，不保存凭证原文');
            $table->string('endpoint', 512)->comment('接口标识，格式为 HTTP 方法加 URL 路径');
            $table->string('request_id', 128)->comment('调用方提供的幂等请求 ID');
            $table->char('request_hash', 64)->comment('请求 query 与 body 的 SHA-256 摘要');
            $table->string('status', 16)->comment('执行状态：processing 处理中、succeeded 成功、failed 失败');
            $table->char('lock_token', 32)->nullable()->comment('本次认领令牌，接管时被覆盖，用于阻止超时前的旧执行者写回结果');
            $table->unsignedSmallInteger('response_status')->nullable()->comment('原始 HTTP 响应状态码');
            $table->string('response_content_type', 255)->nullable()->comment('原始 HTTP 响应内容类型');
            $table->mediumText('response_body')->nullable()->comment('原始 HTTP 响应体');
            $table->dateTime('expire_time')->comment('记录过期时间');
            $table->dateTime('create_time')->useCurrent()->comment('创建时间');
            $table->dateTime('update_time')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique(['caller_hash', 'endpoint', 'request_id'], 'uk_idempotency_caller_endpoint_request');
            $table->index(['expire_time', 'id'], 'idx_idempotency_expire');
            $table->comment('通用接口幂等执行记录表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
