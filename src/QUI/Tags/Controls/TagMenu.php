<?php

/**
 * This file contains QUI\ERP\Products\Controls\Category\Menu
 */

namespace QUI\Tags\Controls;

use QUI;
use QUI\Exception;
use QUI\Tags\Groups\Handler as TagGroupsHandler;

use function dirname;
use function usort;

/**
 * Class TagMenu
 */
class TagMenu extends QUI\Control
{
    /**
     * Current Project
     */
    protected QUI\Projects\Project $Project;

    /**
     * constructor
     *
     * @param array<string, mixed> $attributes
     * @throws Exception
     */
    public function __construct(array $attributes = [])
    {
        if (
            isset($attributes['Project'])
            && $attributes['Project'] instanceof QUI\Projects\Project
        ) {
            $this->Project = $attributes['Project'];
        } else {
            $Site = QUI::getRewrite()->getSite();

            if ($Site === null) {
                throw new Exception('No site available for tag menu project resolution');
            }

            $this->Project = $Site->getProject();
        }

        $this->setAttributes([
            'data-qui' => 'package/quiqqer/tags/bin/TagMenu',
            'selectedTags' => [],
            'TagSearchSite' => false
        ]);

        $this->addCSSClass('quiqqer-tags-tagmenu');
        $this->addCSSFile(dirname(__FILE__) . '/TagMenu.css');

        parent::__construct($attributes);
    }

    /**
     * (non-PHPdoc)
     *
     * @throws Exception
     * @see \QUI\Control::create()
     */
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        $TagSearchSite = $this->getAttribute('TagSearchSite');

        if ($TagSearchSite instanceof QUI\Projects\Site) {
            $tagSearchUrl = $TagSearchSite->getUrlRewritten();
        } else {
            $tagSearchUrl = false;
        }

        // Get Tag Search Site
        $Engine->assign([
            'children' => $this->getChildren(),
            'this' => $this,
            'childrenTemplate' => dirname(__FILE__) . '/TagMenu.Children.html',
            'Rewrite' => QUI::getRewrite(),
            'tagSearchUrl' => $tagSearchUrl,
            'selectedTags' => $this->getAttribute('selectedTags')
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/TagMenu.html');
    }

    /**
     * Get all tag groups
     *
     * @return list<array{id: int, title: string, tags: list<array<string, mixed>>, priority: int}>
     * @throws QUI\Tags\Exception
     */
    public function getChildren(): array
    {
        $tagGroupIds = TagGroupsHandler::getGroupIds($this->Project);
        $children = [];

        foreach ($tagGroupIds as $tagGroupId) {
            $TagGroup = TagGroupsHandler::get($this->Project, $tagGroupId);

            $tags = $TagGroup->getTags();

            if (empty($tags)) {
                continue;
            }

            $children[] = [
                'id' => $TagGroup->getId(),
                'title' => $TagGroup->getTitle(),
                'tags' => $tags,
                'priority' => $TagGroup->getPriority()
            ];
        }

        // sort by priority DESC
        usort($children, function ($a, $b) {
            $prioA = $a['priority'];
            $prioB = $b['priority'];

            if ($prioA === $prioB) {
                return 0;
            }

            return $prioA < $prioB ? 1 : -1;
        });

        return $children;
    }
}
