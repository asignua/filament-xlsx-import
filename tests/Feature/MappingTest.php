<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Feature;

use Asignua\FilamentXlsxImport\Support\DefinitionResolver;
use Asignua\FilamentXlsxImport\Support\Mapping;
use Asignua\FilamentXlsxImport\Support\ModelIntrospector;
use Asignua\FilamentXlsxImport\Tests\TestCase;
use Workbench\App\Enums\ProductStatus;
use Workbench\App\Import\ContactImporter;
use Workbench\App\Models\Contact;
use Workbench\App\Models\Product;

class MappingTest extends TestCase
{
    public function test_targets_come_from_the_database_the_casts_and_the_relations(): void
    {
        $targets = ModelIntrospector::targets(Product::class);

        $this->assertArrayHasKey('sku', $targets);
        $this->assertArrayHasKey('price', $targets);
        $this->assertArrayHasKey('category.name', $targets);
        $this->assertArrayHasKey('tags.name', $targets);
        $this->assertArrayNotHasKey('created_at', $targets);
        $this->assertSame('belongsTo', $targets['category.name']->relationType);
        $this->assertSame('belongsToMany', $targets['tags.name']->relationType);
        $this->assertSame(['draft', 'active', 'archived'], $targets['status']->options);
        $this->assertSame('enum', $targets['status']->casts[0]['type']);
        $this->assertSame(['trim', 'decimal'], array_column($targets['price']->casts, 'type'));
        $this->assertSame(['trim', 'integer'], array_column($targets['stock']->casts, 'type'));
        $this->assertTrue($targets['sku']->required);
        $this->assertFalse($targets['notes']->required);
    }

    public function test_it_works_for_a_model_with_no_rules_at_all(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null);

        $this->assertFalse($definition->hasRules);
        $this->assertNull($definition->updateBy);
        $this->assertSame(['id', 'name', 'email', 'phone', 'city'], array_keys($definition->targets));
    }

    public function test_the_models_own_rules_are_read_and_marked(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $this->assertTrue($definition->hasRules);
        $this->assertSame('sku', $definition->updateBy);
        $this->assertTrue($definition->targets['sku']->fromRules);
        $this->assertTrue($definition->targets['sku']->mappingRequired);
        $this->assertFalse($definition->targets['notes']->fromRules);
        $this->assertSame('create', $definition->targets['category.name']->mode);
    }

    public function test_a_definition_class_in_config_stands_in_for_the_model(): void
    {
        config(['filament-xlsx-import.definitions' => [Contact::class => \Workbench\App\Import\ContactDefinition::class]]);

        $definition = DefinitionResolver::resolve(Contact::class, null);

        $this->assertSame('email', $definition->updateBy);
        $this->assertSame(['trim', 'lower'], array_column($definition->targets['email']->casts, 'type'));
    }

    public function test_an_importer_class_supplies_the_columns(): void
    {
        $definition = DefinitionResolver::resolve(null, ContactImporter::class);

        $this->assertSame(Contact::class, $definition->model);
        $this->assertSame(['name', 'email', 'phone', 'city'], array_keys($definition->targets));
        $this->assertTrue($definition->targets['email']->mappingRequired);
        $this->assertFalse($definition->targets['phone']->mappingRequired);
        $this->assertSame(['Lviv', 'Kyiv', 'Odesa'], $definition->targets['city']->options, 'The in: rule becomes a dropdown.');
        $this->assertSame('ada@example.com', $definition->targets['email']->example);
    }

    public function test_headers_match_by_name_label_alias_and_builtin_synonym_in_several_languages(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null);

        $mapping = Mapping::initial($definition, ['NAME', 'E-mail', 'Телефон', 'Місто', 'Whatever']);

        $this->assertSame(['name', 'email', 'phone', 'city', null], array_column($mapping, 'target'));
        $this->assertSame('none', $mapping[4]['origin']);
    }

    public function test_a_near_miss_is_still_matched(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null);

        $this->assertSame('email', Mapping::initial($definition, ['Emial'])[0]['target'] ?? null);
        $this->assertNull(Mapping::initial($definition, ['Xyz'])[0]['target']);
    }

    public function test_one_target_is_never_taken_twice(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null);

        $mapping = Mapping::initial($definition, ['email', 'E-mail']);

        $this->assertSame(['email', null], array_column($mapping, 'target'));
    }

    public function test_relation_headers_match_the_field_or_the_relation_name(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $mapping = Mapping::initial($definition, ['Category Name', 'tags']);

        $this->assertSame(['category.name', 'tags.name'], array_column($mapping, 'target'));
    }

    public function test_model_rules_mark_the_prefilled_columns(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $mapping = Mapping::initial($definition, ['article', 'notes']);

        $this->assertSame('rules', $mapping[0]['origin']);
        $this->assertSame('sku', $mapping[0]['rule']['target']);
        $this->assertSame('auto', $mapping[1]['origin']);
        $this->assertNull($mapping[1]['rule'], 'notes has no model rule, so it is not marked.');
    }

    public function test_a_preset_is_matched_by_header_whatever_the_column_order(): void
    {
        $definition = DefinitionResolver::resolve(Contact::class, null);
        $preset = [
            ['header' => 'Full name', 'target' => 'name', 'casts' => ['upper'], 'mode' => 'lookup', 'many' => 'add'],
            ['header' => 'Mail', 'target' => 'email', 'casts' => [], 'mode' => 'lookup', 'many' => 'add'],
            ['header' => 'Junk', 'target' => null, 'casts' => [], 'mode' => 'lookup', 'many' => 'add'],
        ];

        $mapping = Mapping::initial($definition, ['junk', 'MAIL', 'full name', 'City'], preset: $preset);

        $this->assertSame([null, 'email', 'name', 'city'], array_column($mapping, 'target'));
        $this->assertSame(['preset', 'preset', 'preset', 'auto'], array_column($mapping, 'origin'));
        $this->assertSame('upper', $mapping[2]['casts'][0]['type']);
    }

    public function test_an_enum_cast_lists_every_value_found_in_the_file_resolving_those_it_can(): void
    {
        $definition = DefinitionResolver::resolve(Product::class, null);

        $mapping = Mapping::initial($definition, ['status'], ['status' => ['Active', 'Згорнуто']], ['status' => ['Active', 'Згорнуто']]);

        $this->assertSame([['from' => 'Active', 'to' => ProductStatus::Active->value], ['from' => 'Згорнуто', 'to' => '']], $mapping[0]['casts'][0]['map']);
    }

    public function test_the_form_state_is_cleaned_into_entries(): void
    {
        $entries = Mapping::fromState([
            ['header' => 'A', 'target' => '', 'casts' => [['type' => 'bogus']], 'mode' => 'create', 'many' => 'replace', 'samples' => ['x']],
            ['header' => 'B', 'target' => 'name', 'casts' => [['type' => 'enum', 'map' => [['from' => 'x', 'to' => 'y']]]], 'mode' => 'weird'],
            'junk',
        ]);

        $this->assertNull($entries[0]['target']);
        $this->assertSame([], $entries[0]['casts']);
        $this->assertSame('replace', $entries[0]['many']);
        $this->assertSame(['x' => 'y'], $entries[1]['casts'][0]['map']);
        $this->assertSame('lookup', $entries[1]['mode']);
        $this->assertCount(2, $entries);
    }
}
