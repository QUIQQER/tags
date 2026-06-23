<?php

/**
 * This file contains \QUI\Tags\MCP\Group\ModifyGroupTags
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class ModifyGroupTags extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $groupId,
                array $tags,
                string $operation,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Group = Handler::get($Project, $groupId);

                    switch ($operation) {
                        case 'set':
                            $Group->setTags($tags);
                            break;

                        case 'add':
                            $Group->addTags($tags);
                            break;

                        case 'remove':
                            foreach ($tags as $tag) {
                                $Group->removeTag($tag);
                            }
                            break;

                        default:
                            throw new \InvalidArgumentException(
                                'Invalid operation "' . $operation . '". '
                                . 'Allowed: set, add, remove.'
                            );
                    }

                    $Group->save();

                    $result = self::parseGroup($Group);
                    $result['operation'] = $operation;
                    $result['tagList'] = $Group->getTags();

                    return $result;
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_modify_tags',
            description: 'Modifies the tags of a tag group. The operation parameter controls '
                . 'the behaviour: "add" adds the given tags while keeping existing ones, '
                . '"remove" removes the given tags and keeps the rest, "set" replaces the '
                . 'COMPLETE tag list with the given tags (destructive - all other tags are '
                . 'removed). Use "add" unless you explicitly want to replace everything.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'groupId', 'tags', 'operation'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'groupId' => ['type' => 'integer', 'description' => 'Tag group id.'],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['add', 'remove', 'set'],
                        'description' => 'add = keep existing tags and add the given ones; '
                            . 'remove = remove the given tags; '
                            . 'set = replace all tags with the given ones (destructive).'
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of tags the operation is applied with.'
                    ]
                ]
            ]
        );
    }
}
