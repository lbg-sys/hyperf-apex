<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Controller\ApiDocController;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Response as PsrResponse;
use Hyperf\HttpServer\Response;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * 文档导出端点的契约。
 *
 * 两个端点都是 `#[PublicAccess]` 且只读产物目录（`api_doc.output_path`，默认 `runtime/api-doc`）下的文件：
 * - 文件没生成时回 404 + 指向正确命令的提示（openapi → `api-doc:generate`，
 *   apifox → `api-doc:generate-apifox`），而不是空 404 让人猜；
 * - 生成了就原样回出，带上 `application/json` 的 content type。
 *
 * 测试直接读写 `BASE_PATH/runtime/api-doc`，并通过 config 把出口指到同一个目录（路径由
 * ApiDocPaths 统一解析，生成命令与出口共用一份，改配置不会两边不一致）。
 * 用例把可能已存在的文件先备份、测完恢复，不留下副作用。
 */
final class ApiDocControllerTest extends TestCase
{
    private const DIRECTORY = BASE_PATH . '/runtime/api-doc';

    private ?string $openApiBackup = null;

    private ?string $apifoxBackup = null;

    protected function setUp(): void
    {
        if (is_file(self::DIRECTORY . '/openapi.json')) {
            $this->openApiBackup = (string) file_get_contents(self::DIRECTORY . '/openapi.json');
        }
        if (is_file(self::DIRECTORY . '/apifox.json')) {
            $this->apifoxBackup = (string) file_get_contents(self::DIRECTORY . '/apifox.json');
        }

        @unlink(self::DIRECTORY . '/openapi.json');
        @unlink(self::DIRECTORY . '/apifox.json');
        @mkdir(self::DIRECTORY, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink(self::DIRECTORY . '/openapi.json');
        @unlink(self::DIRECTORY . '/apifox.json');
        @rmdir(self::DIRECTORY);
        @rmdir(BASE_PATH . '/runtime');

        if ($this->openApiBackup !== null) {
            @mkdir(self::DIRECTORY, 0775, true);
            file_put_contents(self::DIRECTORY . '/openapi.json', $this->openApiBackup);
        }
        if ($this->apifoxBackup !== null) {
            @mkdir(self::DIRECTORY, 0775, true);
            file_put_contents(self::DIRECTORY . '/apifox.json', $this->apifoxBackup);
        }
    }

    public function testOpenApiReturns404PointingAtTheGenerateCommandWhenMissing(): void
    {
        $response = $this->controller()->openApi();

        $this->assertSame(404, $response->getStatusCode());

        $body = $this->json($response);
        $this->assertSame(404, $body['code']);
        $this->assertStringContainsString('api-doc:generate', $body['message']);
    }

    public function testApifoxReturns404PointingAtTheApifoxCommandWhenMissing(): void
    {
        $response = $this->controller()->apifox();

        $this->assertSame(404, $response->getStatusCode());
        // 两个命令不同：提示必须指对，否则照着敲是错的。
        $this->assertStringContainsString('api-doc:generate-apifox', $this->json($response)['message']);
    }

    public function testOpenApiServesTheGeneratedFile(): void
    {
        file_put_contents(self::DIRECTORY . '/openapi.json', '{"openapi":"3.1.1"}' . PHP_EOL);

        $response = $this->controller()->openApi();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('content-type'));
        $this->assertSame(['openapi' => '3.1.1'], $this->json($response));
    }

    public function testApifoxServesTheGeneratedFile(): void
    {
        file_put_contents(self::DIRECTORY . '/apifox.json', '{"apifoxProject":"1.0.0"}' . PHP_EOL);

        $response = $this->controller()->apifox();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('content-type'));
        $this->assertSame(['apifoxProject' => '1.0.0'], $this->json($response));
    }

    public function testOutputDirectoryIsResolvedFromConfig(): void
    {
        // 产物确实在默认目录里，但配置指到了别处：必须按配置找。写死路径时，改了 output_path 的
        // 工程会一直收到「文档尚未生成」，而生成命令明明报成功 —— 两者之间没有任何线索。
        file_put_contents(self::DIRECTORY . '/openapi.json', '{"openapi":"3.1.1"}' . PHP_EOL);

        $controller = new ApiDocController(new Response(new PsrResponse()), new Config([
            'api_doc' => ['output_path' => self::DIRECTORY . '/elsewhere'],
        ]));

        $this->assertSame(404, $controller->openApi()->getStatusCode());
    }

    private function controller(): ApiDocController
    {
        return new ApiDocController(new Response(new PsrResponse()), new Config([
            'api_doc' => ['output_path' => self::DIRECTORY],
        ]));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail('响应不是合法 JSON：' . $exception->getMessage());
        }

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
