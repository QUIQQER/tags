<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\UpdateTag
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class UpdateTag extends AbstractTool
{
    public function register(Builder $serverBuilder): void
    {
        $serverBuilder->addTool(
            function (
                string $project,
                string $tag,
                string | null $lang = null,
                string | null $title = null,
                string | null $description = null,
                string | null $image = null,
                string | null $url = null,
                string | null $generator = null,
                array | null $tagGroupIds = null
            ): CallToolResult | array {
                try {
                    self::checkTagsPermission();

                    $Manager = self::getManager($project, $lang);
                    $params = [];

                    if ($title !== null) {
                        $params['title'] = $title;
                    }

                    if ($description !== null) {
                        $params['desc'] = $description;
                    }

                    if ($image !== null) {
                        $params['image'] = $image;
                    }

                    if ($url !== null) {
                        $params['url'] = $url;
                    }

                    if ($generator !== null) {
                        $params['generator'] = $generator;
                    }

                    if ($tagGroupIds !== null) {
                        $params['tagGroupIds'] = $tagGroupIds;
                    }

                    $Manager->edit($tag, $params);

                    return self::parseTag($Manager->get($tag));
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_update',
            description: 'Updates an existing tag of a QUIQQER project.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tag'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tag' => ['type' => 'string', 'description' => 'Tag identifier.'],
                    'title' => ['type' => 'string', 'description' => 'Display title.'],
                    'description' => ['type' => 'string', 'description' => 'Description.'],
                    'image' => ['type' => 'string', 'description' => 'Image URL.'],
                    'url' => ['type' => 'string', 'description' => 'URL.'],
                    'generator' => ['type' => 'string', 'description' => 'Generator identifier.'],
                    'tagGroupIds' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Tag group ids the tag should belong to.'
                    ]
                ]
            ]
        );
    }
}
