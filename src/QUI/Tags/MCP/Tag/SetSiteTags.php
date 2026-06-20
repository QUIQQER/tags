<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\SetSiteTags
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class SetSiteTags extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $siteId,
                array $tags,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);
                    $Manager->setSiteTags($siteId, $tags);

                    return [
                        'project' => $project,
                        'siteId' => $siteId,
                        'tags' => $Manager->getSiteTags($siteId)
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_set_site_tags',
            description: 'Overwrites the complete tag assignment of a site with the given tags.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'siteId', 'tags'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'siteId' => ['type' => 'integer', 'description' => 'Site id.'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of tags to assign to the site.'
                    ]
                ]
            ]
        );
    }
}
