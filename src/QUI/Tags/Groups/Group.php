<?php

/**
 * This file contains QUI\Tags\Groups\Group
 */

namespace QUI\Tags\Groups;

use Exception;
use QUI;
use QUI\Projects\Media\Image;
use QUI\Projects\Project;
use QUI\Utils\Security\Orthos;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function sort;
use function strcmp;
use function trim;
use function usort;

/**
 * Class Group
 *
 * @package QUI\Tags\Groups
 */
class Group
{
    /**
     * internal project
     *
     * @var Project
     */
    protected Project $Project;

    /**
     * @var integer
     */
    protected int $id;

    /**
     * @var string
     */
    protected string $desc = '';

    /**
     * @var string
     */
    protected string $title = '';

    /**
     * @var string
     */
    protected string $workingtitle = '';

    /**
     * @var string
     */
    protected string $image = '';

    /**
     * @var int
     */
    protected int $priority = 1;

    /**
     * @var bool
     */
    protected bool $generated = false;

    /**
     * @var string
     */
    protected string $generator = '';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $tags = [];

    /**
     * ID of parent tag group
     *
     * @var null|int - null if no parent set; ID otherwise
     */
    protected ?int $parentId = null;

    /**
     * @var null|QUI\Tags\Manager
     */
    protected ?QUI\Tags\Manager $Manager = null;

    /**
     * Group constructor
     *
     * @param integer $groupId
     * @param Project $Project
     * @throws QUI\Tags\Exception
     */
    public function __construct(int $groupId, Project $Project)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from' => Handler::table($Project),
                'where' => [
                    'id' => $groupId
                ],
                'limit' => 1
            ]);
        } catch (QUI\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());

            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.group.not.found'
            ]);
        }

        if (empty($result)) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.group.not.found'
            ]);
        }

        $this->Project = $Project;
        $this->id = $groupId;
        $this->Manager = new QUI\Tags\Manager($this->Project);

        $data = $result[0];

        $this->setTitle($data['title']);
        $this->setWorkingTitle($data['workingtitle']);

        if (!empty($data['desc'])) {
            $this->setDescription($data['desc']);
        }

        $this->setPriority($data['priority']);
        $this->setGenerateStatus($data['generated']);
        $this->setGenerator($data['generator']);

        if (!empty($data['parentId'])) {
            $this->parentId = (int)$data['parentId'];
        }

        try {
            $this->setImage($data['image']);
        } catch (QUI\Exception) {
        }

        if (!isset($data['tags'])) {
            return;
        }

        $tags = explode(',', $data['tags']);

        foreach ($tags as $tag) {
            try {
                $this->addTag($tag);
            } catch (QUI\Tags\Exception) {
            }
        }
    }

    /**
     * Return the group id
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Return the group title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Return the group working title
     *
     * @return string
     */
    public function getWorkingTitle(): string
    {
        return $this->workingtitle;
    }

    /**
     * Return the group priority
     *
     * @return integer
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Return the group description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->desc;
    }

    /**
     * Return the group image
     *
     * @return QUI\Projects\Media\Image|false
     */
    public function getImage(): QUI\Projects\Media\Image | bool
    {
        if (QUI\Projects\Media\Utils::isMediaUrl($this->image)) {
            try {
                return QUI\Projects\Media\Utils::getImageByUrl($this->image);
            } catch (QUI\Exception) {
            }
        }

        $Image = $this->Project->getMedia()->getPlaceholderImage();

        if ($Image !== null && QUI\Projects\Media\Utils::isImage($Image)) {
            return $Image;
        }

        return false;
    }

    /**
     * @param string $search
     * @return list<array<string, mixed>>
     */
    public function searchTags(string $search): array
    {
        $tags = $this->getTags();
        $result = [];

        foreach ($tags as $tag) {
            if (!str_contains($tag['tag'], $search) && !str_contains($tag['title'], $search)) {
                continue;
            }

            $result[] = $tag;
        }

        return $result;
    }

    /**
     * Is the group generated?
     *
     * @return bool
     */
    public function isGenerated(): bool
    {
        return $this->generated;
    }

    /**
     * Return generator of this tag group
     *
     * @return string
     */
    public function getGenerator(): string
    {
        return $this->generator;
    }

    /**
     * Set the tag group title
     * no html allowed
     *
     * @param string|null $title
     */
    public function setTitle(?string $title): void
    {
        if ($title === null) {
            return;
        }

        if (!empty($title)) {
            $title = trim(Orthos::removeHTML($title));
        }

        $this->title = $title;
    }

    /**
     * Set the tag group working title
     * no html allowed
     *
     * @param string|null $title
     */
    public function setWorkingTitle(?string $title): void
    {
        if ($title === null) {
            return;
        }

        if (!empty($title)) {
            $title = trim(Orthos::removeHTML($title));
        }

        $this->workingtitle = $title;
    }

    /**
     * Set the tag group description
     * no html allowed
     *
     * @param string|null $description
     */
    public function setDescription(?string $description): void
    {
        if ($description === null) {
            return;
        }

        if (!empty($description)) {
            $description = trim(Orthos::removeHTML($description));
        }

        $this->desc = $description;
    }

    /**
     *  Set the tag group priority
     *
     * @param integer $priority
     */
    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * Set the generated status
     * Is the group generated?
     *
     * @param bool $status
     */
    public function setGenerateStatus(bool $status): void
    {
        // cannot set to false if a generator is set
        if (!$status && !empty($this->generator)) {
            return;
        }

        $this->generated = $status;
    }

    /**
     * Set string describing the generator of the tags (e.g. package name)
     *
     * @param string|null $generator
     */
    public function setGenerator(?string $generator): void
    {
        if (empty($generator)) {
            return;
        }

        $this->generator = $generator;
        $this->generated = true;
    }

    /**
     * Set the tag group image
     *
     * @param Image|string|null $Image
     *
     * @throws QUI\Exception
     * @throws QUI\Tags\Exception
     */
    public function setImage(null | QUI\Projects\Media\Image | string $Image): void
    {
        if (empty($Image)) {
            $this->image = '';

            return;
        }

        if (is_string($Image)) {
            $Image = QUI\Projects\Media\Utils::getImageByUrl($Image);
        }

        if (!QUI\Projects\Media\Utils::isImage($Image)) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.taggroup.no.image'
            ]);
        }

        $this->image = $Image->getUrl();
    }

    /**
     * Set the parent tag group of this tag group
     *
     * @param int $groupId - ID of parent tag group
     * @return void
     *
     * @throws QUI\Tags\Exception|QUI\Database\Exception
     */
    public function setParentGroup(int $groupId): void
    {
        if ($this->parentId === $groupId) {
            return;
        }

        if ($groupId === $this->id) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.groups.group.cannot.be.its.own.parent'
            ]);
        }

        if (!Handler::exists($this->Project, $groupId)) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.groups.group.parent.does.not.exist',
                [
                    'tagGroupId' => $groupId
                ]
            ]);
        }

        if (in_array($groupId, $this->getChildrenIds())) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.groups.group.parent.cannot.be.child',
                [
                    'childTagGroupId' => $groupId,
                    'tagGroupId' => $this->id
                ]
            ]);
        }

        QUI::getDataBase()->update(
            Handler::table($this->Project),
            [
                'parentId' => $groupId
            ],
            [
                'id' => $this->id
            ]
        );

        $this->parentId = $groupId;
    }

    /**
     * Get IDs of all child tag groups
     *
     * @return list<int>
     */
    public function getChildrenIds(): array
    {
        return Handler::getTagGroupChildrenIds($this->Project, $this->id);
    }

    /**
     * Removes the current parent tag group from this tag group.
     * This makes the tag group parent less.
     *
     * @return void
     */
    public function removeParentGroup(): void
    {
        if ($this->parentId === null) {
            return;
        }

        try {
            QUI::getDataBase()->update(
                Handler::table($this->Project),
                ['parentId' => null],
                ['id' => $this->id]
            );

            $this->parentId = null;
        } catch (QUI\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
        }
    }

    /**
     * Delete the group
     */
    public function delete(): void
    {
        try {
            Handler::delete($this->Project, $this->getId());
        } catch (QUI\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
        }
    }

    /**
     * Save the group
     */
    public function save(): void
    {
        // image
        $image = '';

        if (QUI\Projects\Media\Utils::isMediaUrl($this->image)) {
            try {
                $Image = QUI\Projects\Media\Utils::getImageByUrl($this->image);
                $image = $Image->getUrl();
            } catch (QUI\Exception) {
            }
        }

        // tags
        $tags = array_map(function ($tag) {
            return $tag['tag'];
        }, $this->getTags());

        $tags = array_values(array_unique($tags));

        // database
        QUI::getDataBase()->update(
            Handler::table($this->Project),
            [
                'title' => $this->getTitle(),
                'workingtitle' => $this->getWorkingTitle(),
                'desc' => $this->getDescription(),
                'image' => $image,
                'priority' => $this->getPriority(),
                'tags' => ',' . implode(',', $tags) . ',',
                'generated' => $this->isGenerated() ? 1 : 0,
                'generator' => $this->getGenerator()
            ],
            [
                'id' => $this->getId()
            ]
        );
    }

    /**
     * Add a tag to the group
     *
     * @param string $tag - Tag
     * @throws QUI\Tags\Exception
     */
    public function addTag(string $tag): void
    {
        if (empty($tag)) {
            return;
        }

        if ($this->Manager === null) {
            throw new QUI\Tags\Exception([
                'quiqqer/tags',
                'exception.tag.not.found'
            ]);
        }

        $tagData = $this->Manager->get($tag);

        if (!isset($this->tags[$tagData['tag']])) {
            $this->tags[$tagData['tag']] = $tagData;
        }
    }

    /**
     * Add multiple tags to the group
     *
     * @param array<int, string> $tags - tags
     */
    public function addTags(array $tags): void
    {
        foreach ($tags as $tag) {
            try {
                $this->addTag($tag);
            } catch (Exception) {
                // do not add tag
            }
        }
    }

    /**
     * Set tags to group (overwrites all previous tags!)
     *
     * @param array<int, string> $tags
     * @return void
     */
    public function setTags(array $tags): void
    {
        $this->tags = [];

        foreach ($tags as $tag) {
            try {
                $this->addTag($tag);
            } catch (QUI\Exception $exception) {
                QUI\System\Log::addError($exception->getMessage());
            }
        }
    }

    /**
     * Remove a tag from the group
     *
     * @param string $tag
     * @return void
     */
    public function removeTag(string $tag): void
    {
        if (isset($this->tags[$tag])) {
            unset($this->tags[$tag]);
        }
    }

    /**
     * Remove all tags with specific generator from the group
     *
     * @param string $generator - tag generator
     * @return void
     */
    public function removeTagsByGenerator(string $generator): void
    {
        foreach ($this->tags as $tag => $tagData) {
            if ($tagData['generator'] == $generator) {
                unset($this->tags[$tag]);
            }
        }
    }

    /**
     * Return the tags from the group
     *
     * @return list<array<string, mixed>>
     */
    public function getTags(): array
    {
        $tags = array_values($this->tags);

        usort($tags, function ($a, $b) {
            return strcmp($a["title"], $b["title"]);
        });

        return $tags;
    }

    /**
     * Return the group as an array
     *
     * @return array<string, int|string|bool|null>
     */
    public function toArray(): array
    {
        $tags = array_map(function ($tag) {
            return $tag['tag'];
        }, $this->getTags());

        sort($tags);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'workingtitle' => $this->workingtitle,
            'desc' => $this->desc,
            'image' => $this->image,
            'priority' => $this->priority,
            'tags' => implode(',', $tags),
            'countTags' => count($this->tags),
            'generated' => $this->isGenerated(),
            'parentId' => $this->parentId
        ];
    }

    /**
     * Return the group as json
     *
     * @return string
     */
    public function toJSON(): string
    {
        return json_encode($this->toArray()) ?: '';
    }
}
