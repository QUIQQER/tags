<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\ListTags
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

use function array_map;
use function max;

class ListTags extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                string | null $lang = null,
                string | null $search = null,
                int | null $limit = null,
                int | null $offset = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);
                    $limit = self::sanitizeLimit($limit);
                    $offset = (int)max(0, $offset ?? 0);
                    $limitString = $offset . ',' . $limit;

                    if (!empty($search)) {
                        $tags = $Manager->searchTags($search, ['limit' => $limitString]);
                    } else {
                        $tags = $Manager->getList(['limit' => $limitString]);
                    }

                    return [
                        'project' => $project,
                        'lang' => self::getProject($project, $lang)->getLang(),
                        'tags' => array_map(
                            static fn(array $tag): array => self::parseTag($tag),
                            $tags
                        )
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_list',
            description: 'Lists tags of a QUIQQER project, optionally filtered by a search term.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'search' => ['type' => 'string', 'description' => 'Optional search term (tag or title).'],
                    'limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                    'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]
                ]
            ]
        );
    }
}
