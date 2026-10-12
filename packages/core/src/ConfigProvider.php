<?php

declare(strict_types=1);

namespace Hyperf\Apex;

use Hyperf\Apex\Database\Command\SchemaModelCheckCommand;
use Hyperf\Apex\Listener\DbQueryExecutedListener;
use Hyperf\Apex\Listener\EnvironmentConfigCheckListener;
use Hyperf\Apex\Listener\ResumeExitCoordinatorListener;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Apex\Security\Listener\PermissionDeclarationCheckListener;
use Hyperf\Apex\Security\PrincipalContext;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                PrincipalContextInterface::class => PrincipalContext::class,
            ],
            'commands' => [
                SchemaModelCheckCommand::class,
            ],
            'listeners' => [
                DbQueryExecutedListener::class,
                EnvironmentConfigCheckListener::class,
                PermissionDeclarationCheckListener::class,
                ResumeExitCoordinatorListener::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The core configuration files for apex foundation.',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
