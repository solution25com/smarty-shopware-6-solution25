<?php

declare(strict_types=1);

namespace SmartyIntegration\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class SmartyConfig
{
    private const CONFIG_DOMAIN = 'SmartyIntegration.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getAuthId(): ?string
    {
        return $this->getString('authId');
    }

    public function getAuthToken(): ?string
    {
        return $this->getString('authToken');
    }

    public function getEnvironment(): string
    {
        return $this->getString('environment') ?? 'test';
    }

    private function getString(string $key): ?string
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . $key);

        return $value !== null ? (string)$value : null;
    }
}
