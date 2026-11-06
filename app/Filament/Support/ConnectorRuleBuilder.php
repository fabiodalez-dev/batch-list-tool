<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\QueryBuilder\Constraints\Constraint;
use Filament\QueryBuilder\Forms\Components\RuleBuilder;

/**
 * "Add rule" where every rule after the first says how it joins the rules
 * above it: AND, OR or AND NOT.
 *
 * Client 2026-11-06: the "OR group" worked but was clunky; her mock-up was a
 * plain list, "Where Notary Galea / AND Place Valletta / NOT Deed type Will".
 * The rules read top to bottom, each joining everything above it, so
 * "R16 OR R12 AND Bastardello" is (R16 OR R12) AND Bastardello.
 */
class ConnectorRuleBuilder extends RuleBuilder
{
    public const CONNECTOR = 'connector';

    public const AND = 'and';

    public const OR = 'or';

    public const NOT = 'not';

    /**
     * The join a stored rule uses: its own, or for links and saved filters
     * from before 2026-11-06 the list-wide AND / OR they were made with.
     *
     * @param array<string, mixed> $rule
     */
    public static function connectorOf(array $rule, string $fallback = self::AND): string
    {
        $connector = $rule['data'][self::CONNECTOR] ?? null;

        return in_array($connector, [self::AND, self::OR, self::NOT], true) ? $connector : $fallback;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->blocks(fn (): array => array_map(
                fn (Constraint $constraint): Block => self::withConnector($constraint->getBuilderBlock()),
                $this->getConstraints(),
            ))
            // Each rule names its own join, so nothing is printed between them.
            ->labelBetweenItems(null)
            // Client 2026-11-06: the list of rule types ran off the screen.
            // Typing narrows it; the panel itself scrolls (theme.css).
            ->searchable()
            ->extraAttributes(['class' => 'fi-fo-builder-not-contained nra-connector-rules']);
    }

    /**
     * The rule's own form, with the join chosen first. The original fields
     * stay a direct child of the block: Filament's clone and delete buttons
     * find the builder through that exact parent chain.
     */
    private static function withConnector(Block $block): Block
    {
        // The constraint's schema closure, read unevaluated: it needs the
        // block's container, which exists only once the form is built.
        $original = (fn (): mixed => $this->childComponents['default'] ?? [])->call($block);

        return $block->schema(fn (Block $component): array => [
            Select::make(self::CONNECTOR)
                ->hiddenLabel()
                ->options([
                    self::AND => 'AND',
                    self::OR => 'OR',
                    self::NOT => 'AND NOT',
                ])
                ->default(self::AND)
                ->selectablePlaceholder(false)
                ->native(false)
                ->extraAttributes(['class' => 'nra-rule-connector'])
                // The first rule has nothing above it to join.
                ->hidden(fn (Select $component): bool => self::isFirstRule($component)),
            ...(array) $component->evaluate($original),
        ]);
    }

    private static function isFirstRule(Select $component): bool
    {
        // .../rules.{key}.data.connector — the builder holds the rules by key.
        $path = (string) $component->getStatePath();
        $key = (string) str($path)->beforeLast('.data.' . self::CONNECTOR)->afterLast('.');
        $builder = $component->getContainer()->getParentComponent()?->getContainer()->getParentComponent();
        if (! $builder instanceof RuleBuilder) {
            return false;
        }
        $state = (array) $builder->getRawState();

        return array_key_first($state) === $key;
    }
}
