<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Psr\Log\AbstractLogger;
use Stringable;

class SqlQueryCounter extends AbstractLogger
{
    /** @var array<string, int> */
    private array $counts = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $context['sql'], $match)) {
            $operation = strtoupper($match[1]);
            $this->counts[$operation] = ($this->counts[$operation] ?? 0) + 1;
        }
    }

    public function reset(): void
    {
        $this->counts = [];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return array_replace(['SELECT' => 0, 'INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0], $this->counts);
    }
}
