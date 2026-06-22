<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\ModifySiteTagGroups
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\Server;
use QUI\AI\MCP\ToolHelper;
use QUI\Projects\Site\Edit;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

use function implode;
use function in_array;

class ModifySiteTagGroups extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $siteId,
                array $groupIds,
                string $operation,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Site = new Edit($Project, $siteId);

                    $current = self::parseTagGroupIds(
                        $Site->getAttribute('quiqqer.tags.tagGroups')
                    );

                    $inputIds = [];

                    foreach ($groupIds as $groupId) {
                        $inputIds[] = (int)$groupId;
                    }

                    switch ($operation) {
                        case 'set':
                            $newIds = [];

                            foreach ($inputIds as $id) {
                                if (Handler::exists($Project, $id) && !in_array($id, $newIds, true)) {
                                    $newIds[] = $id;
                                }
                            }
                            break;

                        case 'add':
                            $newIds = $current;

                            foreach ($inputIds as $id) {
                                if (Handler::exists($Project, $id) && !in_array($id, $newIds, true)) {
                                    $newIds[] = $id;
                                }
                            }
                            break;

                        case 'remove':
                            $newIds = [];

                            foreach ($current as $id) {
                                if (!in_array($id, $inputIds, true)) {
                                    $newIds[] = $id;
                                }
                            }
                            break;

                        default:
                            throw new \InvalidArgumentException(
                                'Invalid operation "' . $operation . '". '
                                . 'Allowed: set, add, remove.'
                            );
                    }

                    $Site->setAttribute('quiqqer.tags.tagGroups', implode(',', $newIds));
                    $Site->save(Server::getRequestUser());

                    $groups = [];

                    foreach ($newIds as $id) {
                        if (Handler::exists($Project, $id)) {
                            $groups[] = self::parseGroup(Handler::get($Project, $id));
                        }
                    }

                    return [
                        'project' => $project,
                        'siteId' => $siteId,
                        'operation' => $operation,
                        'groups' => $groups
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_modify_site_tag_groups',
            description: 'Modifies the tag groups assigned to a site (independent from the '
                . 'tags assigned to a site). The operation parameter controls the behaviour: '
                . '"add" adds the given group ids while keeping existing ones, "remove" '
                . 'removes the given group ids and keeps the rest, "set" replaces the COMPLETE '
                . 'group assignment with the given ids (destructive - all other groups are '
                . 'removed). Use "add" unless you explicitly want to replace everything.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'siteId', 'groupIds', 'operation'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'siteId' => ['type' => 'integer', 'description' => 'Site id.'],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['add', 'remove', 'set'],
                        'description' => 'add = keep existing groups and add the given ones; '
                            . 'remove = remove the given groups; '
                            . 'set = replace all groups with the given ones (destructive).'
                    ],
                    'groupIds' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'List of tag group ids the operation is applied with.'
                    ]
                ]
            ]
        );
    }
}
