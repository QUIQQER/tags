<?php

/**
 * This file contains \QUI\Tags\MCP\Group\ListGroups
 */

namespace QUI\Tags\MCP\Group;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Group;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

use function array_map;
use function max;

class ListGroups extends AbstractTool
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

                    $Project = self::getProject($project, $lang);
                    $limit = self::sanitizeLimit($limit);
                    $offset = (int)max(0, $offset ?? 0);
                    $limitString = $offset . ',' . $limit;

                    if (!empty($search)) {
                        $groups = array_map(
                            static fn(array $group): array => $group,
                            Handler::search($Project, $search, ['limit' => $limitString])
                        );
                    } else {
                        $groups = array_map(
                            static fn(Group $Group): array => self::parseGroup($Group),
                            Handler::getGroups($Project, ['limit' => $limitString])
                        );
                    }

                    return [
                        'project' => $project,
                        'lang' => $Project->getLang(),
                        'groups' => $groups
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_taggroups_list',
            description: 'Lists tag groups of a QUIQQER project, optionally filtered by a search term.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'search' => ['type' => 'string', 'description' => 'Optional search term (group title).'],
                    'limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                    'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]
                ]
            ]
        );
    }
}
