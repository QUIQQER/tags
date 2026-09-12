<?php

/**
 * This file contains \QUI\Tags\Controls\TagList
 */

namespace QUI\Tags\Controls;

use Doctrine\DBAL\ArrayParameterType;
use Exception;
use QUI;
use QUI\Tags\Groups\Handler as TagGroupsHandler;
use QUI\Utils\Doctrine;

use function array_filter;
use function array_unique;
use function array_values;
use function dirname;
use function is_array;
use function is_null;
use function json_decode;
use function preg_match;

/**
 * tag list control
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class TagList extends QUI\Control
{
    /**
     * constructor
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->addCSSFile(
            dirname(__FILE__) . '/TagList.css'
        );

        $this->setAttribute('class', 'quiqqer-tags-list grid-100 grid-parent');
    }

    /**
     * @throws Exception
     */
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $Rewrite = QUI::getRewrite();

        $urlParams = $Rewrite->getUrlParamsList();

        $Engine->assign([
            'Project' => $this->getProject(),
            'Site' => $this->getSite(),
            'Locale' => QUI::getLocale()
        ]);


        $needle = 'abc';

        if (!empty($urlParams)) {
            switch ($urlParams[0]) {
                case 'def':
                case 'ghi':
                case 'jkl':
                case 'mno':
                case 'pqr':
                case 'stu':
                case 'vz':
                case '123':
                    $needle = $urlParams[0];
                    break;
            }
        }


        $tags = $this->getList($needle);

        $Engine->assign([
            'tags' => $tags,
            'list' => $needle
        ]);


        return $Engine->fetch(dirname(__FILE__) . '/TagList.html');
    }

    /**
     * Return a tag list by its sector (title)
     *
     * @param string $sector - tag sector, "abc", "def", "ghi", "jkl", "mno", "pqr", "stu", "vz", "123"
     * @param int|null $groupId (optional) - limit results to a specific tag group
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    public function getList(string $sector, null | int $groupId = null): array
    {
        $QueryBuilder = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(QUI::getDBProjectTableName('tags', $this->getProject())))
            ->orderBy(Doctrine::quoteIdentifier('title'), 'ASC');
        $letters = match ($sector) {
            'def' => ['d', 'e', 'f'],
            'ghi' => ['g', 'h', 'i'],
            'jkl' => ['j', 'k', 'l'],
            'mno' => ['m', 'n', 'o'],
            'pqr' => ['p', 'q', 'r'],
            'stu' => ['s', 't', 'u'],
            'vz' => ['v', 'w', 'x', 'y', 'z'],
            '123', 'special', 'all' => [],
            default => ['a', 'b', 'c']
        };

        if (!empty($letters)) {
            $expressions = [];

            foreach ($letters as $index => $letter) {
                $parameter = 'letter' . $index;
                $expressions[] = Doctrine::quoteIdentifier('title') . ' LIKE :' . $parameter;
                $QueryBuilder->setParameter($parameter, $letter . '%');
            }

            $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$expressions));
        }

        if (!is_null($groupId)) {
            $TagGroup = TagGroupsHandler::get($this->getProject(), $groupId);
            $tags = [];
            $groupTags = $TagGroup->getTags();

            if (empty($groupTags)) {
                return [];
            }

            foreach ($groupTags as $tagData) {
                $tags[] = $tagData['tag'];
            }

            $tags = array_unique($tags);

            $QueryBuilder
                ->andWhere(Doctrine::quoteIdentifier('tag') . ' IN (:tags)')
                ->setParameter('tags', $tags, ArrayParameterType::STRING);
        }

        $result = $QueryBuilder->executeQuery()->fetchAllAssociative();

        if ($sector !== '123' && $sector !== 'special') {
            return $result;
        }

        $pattern = $sector === 'special' ? '/^[^A-Za-z0-9]/' : '/^[0-9]/';

        return array_values(array_filter(
            $result,
            static fn(array $tag): bool => preg_match($pattern, (string)($tag['title'] ?? '')) === 1
        ));
    }

    /**
     * Return the Tag Search Site
     *
     * @return QUI\Projects\Site
     * @throws Exception
     */
    protected function getSite(): QUI\Projects\Site
    {
        if ($this->getAttribute('Site')) {
            return $this->getAttribute('Site');
        }

        $Project = $this->getProject();
        $language = $Project->getLang();
        $tagSearchIds = $Project->getConfig('tags.tagSearchId');

        if ($tagSearchIds) {
            $tagSearchIds = json_decode($tagSearchIds, true);

            if ($tagSearchIds[$language]) {
                try {
                    $Site = QUI\Projects\Site\Utils::getSiteByLink($tagSearchIds[$language]);
                    $this->setAttribute('Site', $Site);

                    return $Site;
                } catch (QUI\Exception) {
                }
            }
        }

        $result = $Project->getSitesIds([
            'where' => [
                'type' => 'quiqqer/tags:types/tag-listing'
            ],
            'limit' => 1
        ]);

        if (!isset($result[0]['id'])) {
            throw new Exception('No tag listing site found');
        }

        return $Project->get($result[0]['id']);
    }
}
