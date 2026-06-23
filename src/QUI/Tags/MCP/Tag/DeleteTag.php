<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\DeleteTag
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class DeleteTag extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                string $tag,
                string | null $lang = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    self::getManager($project, $lang)->deleteTag($tag);

                    return [
                        'success' => true,
                        'tag' => $tag
                    ];
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_delete',
            description: 'Deletes a tag from a QUIQQER project.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tag'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tag' => ['type' => 'string', 'description' => 'Tag identifier.']
                ]
            ]
        );
    }
}
