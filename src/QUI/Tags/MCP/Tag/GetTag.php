<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\GetTag
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

use function array_map;

class GetTag extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                string $tag,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);
                    $result = self::parseTag($Manager->get($tag));
                    $result['groups'] = array_map(
                        static fn(array $group): array => [
                            'id' => (int)($group['id'] ?? 0),
                            'title' => (string)($group['title'] ?? '')
                        ],
                        $Manager->getGroupsFromTag($tag)
                    );

                    return $result;
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_get',
            description: 'Returns a single tag of a QUIQQER project including its tag groups.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tag'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tag' => ['type' => 'string', 'description' => 'Tag identifier.']
                ]
            ]
        );
    }
}
