<?php

/**
 * This file contains \QUI\Tags\MCP\Group\DeleteGroup
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class DeleteGroup extends AbstractTool
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

                    Handler::delete(self::getProject($project, $lang), $groupId);

                    return [
                        'success' => true,
                        'groupId' => $groupId
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_delete',
            description: 'Deletes a tag group from a QUIQQER project.',
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
