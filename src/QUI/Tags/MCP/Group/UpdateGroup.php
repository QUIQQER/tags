<?php

/**
 * This file contains \QUI\Tags\MCP\Group\UpdateGroup
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class UpdateGroup extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $groupId,
                string | null $lang = null,
                string | null $title = null,
                string | null $workingTitle = null,
                string | null $description = null,
                int | null $priority = null,
                int | null $parentId = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Group = Handler::get($Project, $groupId);

                    if ($title !== null) {
                        $Group->setTitle($title);
                    }

                    if ($workingTitle !== null) {
                        $Group->setWorkingTitle($workingTitle);
                    }

                    if ($description !== null) {
                        $Group->setDescription($description);
                    }

                    if ($priority !== null) {
                        $Group->setPriority($priority);
                    }

                    if ($parentId !== null) {
                        $Group->setParentGroup($parentId);
                    }

                    $Group->save();

                    return self::parseGroup($Group);
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_update',
            description: 'Updates the metadata of an existing tag group (title, description, '
                . 'priority, parent). To change the tags of a group, use '
                . 'quiqqer_taggroups_modify_tags.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'groupId'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'groupId' => ['type' => 'integer', 'description' => 'Tag group id.'],
                    'title' => ['type' => 'string', 'description' => 'Group title.'],
                    'workingTitle' => ['type' => 'string', 'description' => 'Internal working title.'],
                    'description' => ['type' => 'string', 'description' => 'Description.'],
                    'priority' => ['type' => 'integer', 'description' => 'Priority.'],
                    'parentId' => ['type' => 'integer', 'description' => 'Parent group id.']
                ]
            ]
        );
    }
}
