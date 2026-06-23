<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\GetTagGroups
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class GetTagGroups extends AbstractTool
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

                    $Project = self::getProject($project, $lang);
                    $groups = [];

                    foreach (Handler::getGroupIdsByTag($Project, $tag) as $groupId) {
                        $groups[] = self::parseGroup(Handler::get($Project, (int)$groupId));
                    }

                    return [
                        'project' => $project,
                        'tag' => $tag,
                        'groups' => $groups
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_get_tag_groups',
            description: 'Returns the tag groups a given tag belongs to.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tag'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tag' => ['type' => 'string', 'description' => 'Tag name.']
                ]
            ]
        );
    }
}
