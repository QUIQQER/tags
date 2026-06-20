<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\GetSiteTags
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class GetSiteTags extends AbstractTool
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

                    return [
                        'project' => $project,
                        'siteId' => $siteId,
                        'tags' => self::getManager($project, $lang)->getSiteTags($siteId)
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_get_site_tags',
            description: 'Returns the tags assigned to a site.',
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
