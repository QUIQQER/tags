<?php

/**
 * This file contains QUI\ERP\Products\Controls\Category\Menu
 */

namespace QUI\Tags\Controls;

use QUI;
use QUI\Tags\Exception;
use QUI\Tags\Groups\Handler as TagGroupsHandler;

use function dirname;
use function usort;

/**
 * Class TagSelect
 *
 * List all tag groups and their tags in a dropdown with selectable entries (entry = tag)
 */
class TagSelect extends QUI\Control
{
    /**
     * Current Project
     */
    protected QUI\Projects\Project $Project;

    /**
     * constructor
     *
     * @param array<string, mixed> $attributes
     * @throws QUI\Exception
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
                throw new QUI\Exception('No site available for tag select project resolution');
            }

            $this->Project = $Site->getProject();
        }

        $this->setAttributes([
            'data-qui' => 'package/quiqqer/tags/bin/TagSelect',
            'selectedTags' => []
        ]);

        $this->addCSSClass('quiqqer-tags-tagselect');
        $this->addCSSFile(dirname(__FILE__) . '/TagSelect.css');

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

        // Get Tag Search Site
        $Engine->assign([
            'children' => $this->getChildren(),
            'this' => $this,
            'Rewrite' => QUI::getRewrite(),
            'selectedTags' => $this->getAttribute('selectedTags')
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/TagSelect.html');
    }

    /**
     * Get all tag groups
     *
     * @return list<array{id: int, title: string, tags: list<array<string, mixed>>, priority: int}>
     * @throws Exception
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
