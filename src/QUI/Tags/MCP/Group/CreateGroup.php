<?php

/**
 * This file contains \QUI\Tags\MCP\Group\CreateGroup
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class CreateGroup extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                string $title,
                string | null $lang = null,
                string | null $description = null,
                int | null $priority = null,
                int | null $parentId = null,
                array | null $tags = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Group = Handler::create($Project, $title);

                    if ($description !== null) {
                        $Group->setDescription($description);
                    }

                    if ($priority !== null) {
                        $Group->setPriority($priority);
                    }

                    if ($parentId !== null) {
                        $Group->setParentGroup($parentId);
                    }

                    if ($tags !== null) {
                        $Group->setTags($tags);
                    }

                    $Group->save();

                    return self::parseGroup($Group);
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_create',
            description: 'Creates a new tag group for a QUIQQER project.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'title'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'title' => ['type' => 'string', 'description' => 'Group title.'],
                    'description' => ['type' => 'string', 'description' => 'Optional description.'],
                    'priority' => ['type' => 'integer', 'description' => 'Optional priority.'],
                    'parentId' => ['type' => 'integer', 'description' => 'Optional parent group id.'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional list of tags for the group.'
                    ]
                ]
            ]
        );
    }
}
