<?php

/**
 * This file contains \QUI\Tags\MCP\Provider
 */

namespace QUI\Tags\MCP;

use Mcp\Server\Builder;
use QUI\AI\MCP\ProviderInterface;
use QUI\AI\MCP\Server;
use QUI\MCP\ToolInterface;
use QUI\Permissions\Permission;
use QUI\Tags\MCP\Group\CreateGroup;
use QUI\Tags\MCP\Group\DeleteGroup;
use QUI\Tags\MCP\Group\GetGroup;
use QUI\Tags\MCP\Group\ListGroups;
use QUI\Tags\MCP\Group\UpdateGroup;
use QUI\Tags\MCP\Tag\CreateTag;
use QUI\Tags\MCP\Tag\DeleteTag;
use QUI\Tags\MCP\Tag\GetSiteTags;
use QUI\Tags\MCP\Tag\GetTag;
use QUI\Tags\MCP\Tag\GetTagSites;
use QUI\Tags\MCP\Tag\ListTags;
use QUI\Tags\MCP\Tag\SetSiteTags;
use QUI\Tags\MCP\Tag\UpdateTag;
use Throwable;

/**
 * Tags MCP provider
 */
class Provider implements ProviderInterface
{
    /**
     * @var array<ToolInterface>
     */
    protected array $tools;

    public function __construct()
    {
        $this->tools = [
            new ListTags(),
            new GetTag(),
            new CreateTag(),
            new UpdateTag(),
            new DeleteTag(),
            new GetSiteTags(),
            new SetSiteTags(),
            new GetTagSites(),
            new ListGroups(),
            new GetGroup(),
            new CreateGroup(),
            new UpdateGroup(),
            new DeleteGroup()
        ];
    }

    public function register(Builder $serverBuilder): void
    {
        if (!$this->canUseMcp()) {
            return;
        }

        foreach ($this->tools as $Tool) {
            $Tool->register($serverBuilder);
        }
    }

    protected function canUseMcp(): bool
    {
        try {
            Permission::checkPermission(
                AbstractTool::TAGS_MCP_PERMISSION,
                Server::getRequestUser()
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
