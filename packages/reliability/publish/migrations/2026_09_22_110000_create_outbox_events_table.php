<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->bigIncrements('id')->comment('Outbox 事件 ID');
            $table->string('scope_key', 128)->comment('幂等隔离范围，例如 system 或 tenant:1');
            $table->string('event_type', 128)->comment('稳定的业务事件类型');
            $table->string('idempotency_key', 128)->comment('业务幂等键');
            $table->string('aggregate_type', 128)->nullable()->comment('关联业务资源类型');
            $table->string('aggregate_id', 128)->nullable()->comment('关联业务资源 ID');
            $table->json('payload')->comment('事件载荷快照');
            $table->char('payload_hash', 64)->comment('规范化事件载荷 SHA-256 摘要');
            $table->string('status', 16)->default('pending')->comment('状态：待执行、执行中、成功、永久失败或死信');
            $table->unsignedInteger('attempt_count')->default(0)->comment('已认领执行次数');
            $table->unsignedInteger('max_attempts')->comment('最大执行次数快照');
            $table->dateTime('next_attempt_time')->nullable()->comment('下次允许执行时间，空表示立即执行');
            $table->string('lock_token', 64)->nullable()->comment('Worker 认领令牌');
            $table->dateTime('lock_expire_time')->nullable()->comment('Worker 认领过期时间');
            $table->string('last_failure_type', 16)->nullable()->comment('最近失败类型：可重试或永久失败');
            $table->text('last_error')->nullable()->comment('最后一次错误信息');
            $table->text('result_summary')->nullable()->comment('最后一次执行结果摘要');
            $table->dateTime('success_time')->nullable()->comment('执行成功时间');
            $table->dateTime('fail_time')->nullable()->comment('进入失败终态时间');
            $table->unsignedInteger('replay_count')->default(0)->comment('人工重放次数');
            $table->dateTime('last_replay_time')->nullable()->comment('最近人工重放时间');
            $table->dateTime('create_time')->useCurrent()->comment('创建时间');
            $table->dateTime('update_time')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique(['scope_key', 'event_type', 'idempotency_key'], 'uk_outbox_scope_type_idempotency');
            $table->index(['status', 'next_attempt_time', 'id'], 'idx_outbox_due');
            $table->index(['status', 'lock_expire_time', 'id'], 'idx_outbox_lock_expire');
            $table->index(['event_type', 'create_time'], 'idx_outbox_type_time');
            $table->index(['aggregate_type', 'aggregate_id', 'create_time'], 'idx_outbox_aggregate_time');
            $table->comment('可靠异步事件 Outbox 表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
