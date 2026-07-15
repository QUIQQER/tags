<?php

declare(strict_types=1);

namespace QUITests\Tags;

require_once __DIR__ . '/McpAbstractToolTestProxy.php';

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\AI\MCP\Server;
use QUI\Tags\MCP\Provider;
use ReflectionFunction;
use ReflectionProperty;

class McpProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setRequestUser(null);

        parent::tearDown();
    }

    public function testRegistersAllToolsWithSchemasAndHandlesUnknownProject(): void
    {
        $this->setRequestUser(QUI::getUsers()->getSystemUser());
        $Builder = new Builder();
        (new Provider())->register($Builder);
        $tools = $this->getTools($Builder);

        self::assertCount(17, $tools);
        self::assertSame([
            'quiqqer_tags_list',
            'quiqqer_tags_get',
            'quiqqer_tags_create',
            'quiqqer_tags_update',
            'quiqqer_tags_delete',
            'quiqqer_tags_get_site_tags',
            'quiqqer_tags_modify_site_tags',
            'quiqqer_tags_get_site_tag_groups',
            'quiqqer_tags_modify_site_tag_groups',
            'quiqqer_tags_get_tag_groups',
            'quiqqer_tags_get_sites',
            'quiqqer_taggroups_list',
            'quiqqer_taggroups_get',
            'quiqqer_taggroups_create',
            'quiqqer_taggroups_update',
            'quiqqer_taggroups_modify_tags',
            'quiqqer_taggroups_delete'
        ], array_column($tools, 'name'));

        foreach ($tools as $tool) {
            self::assertNotSame('', $tool['description']);
            self::assertSame('object', $tool['inputSchema']['type'] ?? null);

            $Callback = $tool['handler'];
            $Reflection = new ReflectionFunction($Callback);
            $arguments = [];

            foreach ($Reflection->getParameters() as $Parameter) {
                if ($Parameter->isOptional()) {
                    continue;
                }

                $arguments[] = match ($Parameter->getName()) {
                    'project' => 'missing-phpunit-project',
                    'tag' => 'PHPUnitTag',
                    'tags' => ['PHPUnitTag'],
                    'groupIds' => [1],
                    'siteId', 'groupId' => 1,
                    'operation' => 'add',
                    'title' => 'PHPUnit group',
                    default => null
                };
            }

            self::assertInstanceOf(CallToolResult::class, $Callback(...$arguments));
        }
    }

    public function testDoesNotRegisterToolsWithoutPermission(): void
    {
        $this->setRequestUser(new QUI\Users\Nobody());
        $Builder = new Builder();
        (new Provider())->register($Builder);

        self::assertSame([], $this->getTools($Builder));
    }

    public function testParsesTagDataGroupIdsAndLimits(): void
    {
        self::assertSame([
            'tag' => 'AlphaTag',
            'title' => 'Alpha',
            'description' => 'Description',
            'image' => '/image.jpg',
            'url' => '/alpha',
            'generated' => true,
            'generator' => 'phpunit',
            'count' => 3
        ], McpAbstractToolTestProxy::parseTagData([
            'tag' => 'AlphaTag',
            'title' => 'Alpha',
            'desc' => 'Description',
            'image' => '/image.jpg',
            'url' => '/alpha',
            'generated' => 1,
            'generator' => 'phpunit',
            'count' => '3'
        ]));
        self::assertSame([], McpAbstractToolTestProxy::parseGroupIds(null));
        self::assertSame([1, 2], McpAbstractToolTestProxy::parseGroupIds(['1', '2', '1', 'invalid']));
        self::assertSame([3, 4], McpAbstractToolTestProxy::parseGroupIds('3, 4, 3'));
        self::assertSame(50, McpAbstractToolTestProxy::limit(null));
        self::assertSame(1, McpAbstractToolTestProxy::limit(-5));
        self::assertSame(100, McpAbstractToolTestProxy::limit(500));
    }

    /**
     * @return list<array{handler: callable, name: string, description: string, inputSchema: array<string, mixed>|null}>
     */
    private function getTools(Builder $Builder): array
    {
        $Property = new ReflectionProperty($Builder, 'tools');
        $tools = $Property->getValue($Builder);
        $result = [];

        foreach ($tools as $name => $tool) {
            $result[] = [
                'handler' => $tool['handler'] ?? $tool['callback'],
                'name' => $tool['name'] ?? (string)$name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema']
            ];
        }

        return $result;
    }

    private function setRequestUser(?QUI\Interfaces\Users\User $User): void
    {
        $Property = new ReflectionProperty(Server::class, 'RequestUser');
        $Property->setValue(null, $User);
    }
}
