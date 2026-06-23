<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\GetSiteTagGroups
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\Groups\Handler;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class GetSiteTagGroups extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $siteId,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Project = self::getProject($project, $lang);
                    $Site = $Project->get($siteId);

                    $groupIds = self::parseTagGroupIds(
                        $Site->getAttribute('quiqqer.tags.tagGroups')
                    );

                    $groups = [];

                    foreach ($groupIds as $groupId) {
                        if (Handler::exists($Project, $groupId)) {
                            $groups[] = self::parseGroup(Handler::get($Project, $groupId));
                        }
                    }

                    return [
                        'project' => $project,
                        'siteId' => $siteId,
                        'groups' => $groups
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_get_site_tag_groups',
            description: 'Returns the tag groups assigned to a site. This is independent from '
                . 'the tags assigned to a site (see quiqqer_tags_get_site_tags).',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'siteId'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'siteId' => ['type' => 'integer', 'description' => 'Site id.']
                ]
            ]
        );
    }
}
