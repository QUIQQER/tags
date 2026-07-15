<?php

declare(strict_types=1);

namespace QUITests\Tags;

use Mcp\Server\Builder;
use QUI\Tags\MCP\AbstractTool;

class McpAbstractToolTestProxy extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
    }

    /**
     * @param array<string, mixed> $tag
     * @return array<string, mixed>
     */
    public static function parseTagData(array $tag): array
    {
        return self::parseTag($tag);
    }

    /**
     * @return list<int>
     */
    public static function parseGroupIds(mixed $value): array
    {
        return self::parseTagGroupIds($value);
    }

    public static function limit(?int $limit): int
    {
        return self::sanitizeLimit($limit);
    }
}
