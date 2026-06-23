<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\ModifySiteTags
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class ModifySiteTags extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                int $siteId,
                array $tags,
                string $operation,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);

                    switch ($operation) {
                        case 'set':
                            $Manager->setSiteTags($siteId, $tags);
                            break;

                        case 'add':
                            foreach ($tags as $tag) {
                                $Manager->addTagToSite($siteId, $tag);
                            }
                            break;

                        case 'remove':
                            foreach ($tags as $tag) {
                                $Manager->removeTagFromSite($siteId, $tag);
                            }
                            break;

                        default:
                            throw new \InvalidArgumentException(
                                'Invalid operation "' . $operation . '". '
                                . 'Allowed: set, add, remove.'
                            );
                    }

                    return [
                        'project' => $project,
                        'siteId' => $siteId,
                        'operation' => $operation,
                        'tags' => $Manager->getSiteTags($siteId)
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_modify_site_tags',
            description: 'Modifies the tags assigned to a site. The operation parameter '
                . 'controls the behaviour: "add" adds the given tags while keeping existing '
                . 'ones, "remove" removes the given tags and keeps the rest, "set" replaces '
                . 'the COMPLETE tag assignment with the given tags (destructive - all other '
                . 'tags are removed). Use "add" unless you explicitly want to replace everything.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'siteId', 'tags', 'operation'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'siteId' => ['type' => 'integer', 'description' => 'Site id.'],
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
