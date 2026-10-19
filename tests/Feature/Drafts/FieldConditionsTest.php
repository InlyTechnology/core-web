<?php

namespace Inly\CoreWeb\Tests\Feature\Drafts;

use Inly\CoreWeb\Drafts\FieldConditions;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Fields\Fields;

/** Which values the CP's form leaves out of a save, as Statamic's field-conditions JavaScript decides it. */
class FieldConditionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values
     */
    private static function hidden(array $config, array $values): bool
    {
        $fields = new Fields([
            ['handle' => 'kind', 'field' => ['type' => 'text']],
            ['handle' => 'count', 'field' => ['type' => 'integer']],
            ['handle' => 'tags', 'field' => ['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']]],
            ['handle' => 'on', 'field' => ['type' => 'toggle']],
            ['handle' => 'target', 'field' => ['type' => 'text', ...$config]],
        ]);

        return FieldConditions::omitted($fields, $values) === ['target'];
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function conditions(): array
    {
        return [
            'equals, shown' => [['if' => ['kind' => 'equals article']], ['kind' => 'article'], false],
            'equals, hidden' => [['if' => ['kind' => 'equals article']], ['kind' => 'page'], true],
            'a bare value is equals' => [['if' => ['kind' => 'article']], ['kind' => 'page'], true],
            'equals compares a number loosely' => [['if' => ['count' => 'equals 3']], ['count' => 3], false],
            'not' => [['if' => ['kind' => 'not article']], ['kind' => 'article'], true],
            'empty, missing' => [['if' => ['kind' => 'empty']], [], false],
            'empty, an empty string' => [['if' => ['kind' => 'empty']], ['kind' => ''], false],
            'not empty' => [['if' => ['kind' => 'not empty']], ['kind' => ''], true],
            'not empty, filled' => [['if' => ['kind' => 'not empty']], ['kind' => 'x'], false],
            'a toggle against true' => [['if' => ['on' => true]], ['on' => false], true],
            'a toggle against true, on' => [['if' => ['on' => 'equals true']], ['on' => true], false],
            'contains in a list' => [['if' => ['tags' => 'contains b']], ['tags' => ['a']], true],
            'contains in a string' => [['if' => ['kind' => 'contains art']], ['kind' => 'article'], false],
            'contains_any' => [['if' => ['tags' => 'contains_any b, c']], ['tags' => ['a', 'c']], false],
            'greater than' => [['if' => ['count' => '> 3']], ['count' => 2], true],
            'unless' => [['unless' => ['kind' => 'equals article']], ['kind' => 'article'], true],
            'if_any passes on one' => [['if_any' => ['kind' => 'article', 'count' => '> 5']], ['kind' => 'page', 'count' => 9], false],
            'if needs every one' => [['if' => ['kind' => 'article', 'count' => '> 5']], ['kind' => 'page', 'count' => 9], true],
            'several conditions on one field' => [['if' => ['count' => ['> 1', '< 5']]], ['count' => 7], true],
            'always_save keeps the value' => [['if' => ['kind' => 'article'], 'always_save' => true], ['kind' => 'page'], false],
            'a hidden field keeps its value' => [['if' => ['kind' => 'article'], 'visibility' => 'hidden'], ['kind' => 'page'], false],
            'a custom condition is the CP\'s to run' => [['if' => ['kind' => 'custom isArticle']], ['kind' => 'page'], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('conditions')]
    public function test_a_condition_hides_its_field_as_the_cp_does(array $config, array $values, bool $hidden): void
    {
        $this->assertSame($hidden, self::hidden($config, $values));
    }

    public function test_a_revealer_never_hides_a_value(): void
    {
        $fields = new Fields([
            ['handle' => 'more', 'field' => ['type' => 'revealer']],
            ['handle' => 'kind', 'field' => ['type' => 'text']],
            ['handle' => 'style', 'field' => ['type' => 'text', 'if' => ['more' => 'equals true']]],
            ['handle' => 'entry', 'field' => ['type' => 'text', 'if' => ['kind' => 'entry', 'more' => 'equals true']]],
        ]);

        $this->assertSame(['entry'], FieldConditions::omitted($fields, ['kind' => 'url', 'style' => 'x', 'entry' => 'y']));
        $this->assertSame([], FieldConditions::omitted($fields, ['kind' => 'entry', 'style' => 'x', 'entry' => 'y']));
    }

    public function test_nested_fields_are_hidden_by_their_siblings_the_root_and_their_parent(): void
    {
        $fields = new Fields([
            ['handle' => 'mode', 'field' => ['type' => 'text']],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['cards' => ['fields' => [
                ['handle' => 'source', 'field' => ['type' => 'text']],
                ['handle' => 'limit', 'field' => ['type' => 'integer', 'if' => ['source' => 'equals articles']]],
                ['handle' => 'note', 'field' => ['type' => 'text', 'if' => ['$root.mode' => 'equals full']]],
                ['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'if' => ['$parent.source' => 'equals manual']]],
                ]]],
            ]]]]]]],
        ]);

        $omitted = FieldConditions::omitted($fields, ['mode' => 'short', 'blocks' => [
            ['type' => 'cards', 'source' => 'manual', 'limit' => 3, 'note' => 'x', 'items' => [['title' => 'A']]],
            ['type' => 'cards', 'source' => 'articles', 'limit' => 3, 'items' => [['title' => 'B']]],
        ]]);

        $this->assertEqualsCanonicalizing(['blocks.0.limit', 'blocks.0.note', 'blocks.1.note', 'blocks.1.items.0.title'], $omitted);
    }
}
