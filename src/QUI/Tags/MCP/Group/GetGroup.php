<?php

/**
 * This file contains \QUI\Tags\MCP\Group\GetGroup
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class GetGroup extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $groupId,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Group = Handler::get($Project, $groupId);

                    $result = self::parseGroup($Group);
                    $result['tagList'] = $Group->getTags();
                    $result['childrenIds'] = $Group->getChildrenIds();

                    return $result;
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_get',
            description: 'Returns a single tag group including its tags and child group ids.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'groupId'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'groupId' => ['type' => 'integer', 'description' => 'Tag group id.']
                ]
            ]
        );
    }
}
