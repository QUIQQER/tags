<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\GetTagSites
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

use function array_map;
use function max;

class GetTagSites extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                array $tags,
                string | null $lang = null,
                int | null $limit = null,
                int | null $offset = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);
                    $limit = self::sanitizeLimit($limit);
                    $offset = (int)max(0, $offset ?? 0);

                    $sites = $Manager->getSitesFromTags($tags, [
                        'limit' => $offset . ',' . $limit
                    ]);

                    return [
                        'project' => $project,
                        'tags' => $tags,
                        'count' => $Manager->getSiteCountFromTags($tags),
                        'sites' => array_map(
                            static fn(SiteInterface $Site): array => [
                                'id' => $Site->getId(),
                                'title' => $Site->getAttribute('title'),
                                'url' => $Site->getUrlRewritten()
                            ],
                            $sites
                        )
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_get_sites',
            description: 'Returns the sites that are assigned to the given tags.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tags'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of tags to look up.'
                    ],
                    'limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                    'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]
                ]
            ]
        );
    }
}
