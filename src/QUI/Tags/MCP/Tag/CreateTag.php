<?php

/**
 * This file contains \QUI\Tags\MCP\Tag\CreateTag
 */

namespace QUI\Tags\MCP\Tag;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI\AI\MCP\ToolHelper;
use QUI\Tags\MCP\AbstractTool;
use Throwable;

class CreateTag extends AbstractTool
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
                string | null $generator = null
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

                    $createdTag = $Manager->add($tag, $params);

                    return self::parseTag($Manager->get($createdTag));
                } catch (Throwable $Exception) {
                    return ToolHelper::parseExceptionToResult($Exception);
                }
            },
            name: 'quiqqer_tags_create',
            description: 'Creates a new tag for a QUIQQER project.',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['project', 'tag'],
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Project name.'],
                    'lang' => ['type' => 'string', 'description' => 'Project language.'],
                    'tag' => ['type' => 'string', 'description' => 'Tag name / title to create.'],
                    'title' => ['type' => 'string', 'description' => 'Optional display title.'],
                    'description' => ['type' => 'string', 'description' => 'Optional description.'],
                    'image' => ['type' => 'string', 'description' => 'Optional image URL.'],
                    'url' => ['type' => 'string', 'description' => 'Optional URL.'],
                    'generator' => ['type' => 'string', 'description' => 'Optional generator identifier.']
                ]
            ]
        );
    }
}
