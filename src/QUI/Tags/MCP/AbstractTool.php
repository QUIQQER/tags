<?php

/**
 * This file contains \QUI\Tags\MCP\AbstractTool
 */

namespace QUI\Tags\MCP;

use QUI;
use QUI\AI\MCP\Server;
use QUI\MCP\ToolInterface;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Tags\Groups\Group;
use QUI\Tags\Manager;

use function array_unique;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function trim;

abstract class AbstractTool implements ToolInterface
{
    public const TAGS_MCP_PERMISSION = 'tags.mcp';

    protected static function checkTagsPermission(): void
    {
        Permission::checkPermission(
            self::TAGS_MCP_PERMISSION,
            Server::getRequestUser()
        );
    }

    protected static function getProject(string $project, ?string $lang = null): Project
    {
        if (empty($lang)) {
            return QUI::getProject($project);
        }

        return QUI::getProject($project, $lang);
    }

    protected static function getManager(string $project, ?string $lang = null): Manager
    {
        return new Manager(self::getProject($project, $lang));
    }

    /**
     * @param array<string, mixed> $tag
     * @return array<string, mixed>
     */
    protected static function parseTag(array $tag): array
    {
        return [
            'tag' => (string)($tag['tag'] ?? ''),
            'title' => (string)($tag['title'] ?? ''),
            'description' => (string)($tag['desc'] ?? ''),
            'image' => (string)($tag['image'] ?? ''),
            'url' => (string)($tag['url'] ?? ''),
            'generated' => (bool)($tag['generated'] ?? false),
            'generator' => is_string($tag['generator'] ?? null) ? $tag['generator'] : '',
            'count' => (int)($tag['count'] ?? 0)
        ];
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    protected static function parseGroup(Group $Group): array
    {
        return $Group->toArray();
    }

    /**
     * Normalizes the stored "quiqqer.tags.tagGroups" site attribute into a list of ids.
     *
     * @return list<int>
     */
    protected static function parseTagGroupIds(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        if (!is_string($value)) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part !== '' && is_numeric($part)) {
                $ids[] = (int)$part;
            }
        }

        return array_values(array_unique($ids));
    }

    protected static function sanitizeLimit(?int $limit): int
    {
        if (empty($limit)) {
            return 50;
        }

        return (int)min(100, max(1, $limit));
    }
}
