<?php

namespace Haringsrob\FilamentPageBuilder\Forms\Components;

use Closure;
use ErrorException;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Builder;
use Filament\Forms\Contracts\HasForms;
use Haringsrob\FilamentPageBuilder\Blocks\BlockEditorBlock;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Collection;
use PHPUnit\Exception;

class BlockEditor extends Builder
{
    protected string $view = 'filament-page-builder::block-editor';

    protected bool|Closure|null $isCollapsible = true;

    protected bool|Closure $isCollapsed = false;

    protected string|Closure|null $relationship = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected ?Collection $cachedExistingRecords = null;

    protected ?Closure $mutateRelationshipDataBeforeFillUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeSaveUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeCreateUsing = null;

    protected string $orderColumn = 'position';

    protected null|Closure|string $renderInView = 'filament-page-builder::preview';

    private array $coreFields = ['id', 'type', 'position'];

    public function configure(): static
    {
        parent::configure();
        $this->relationship('blocks');

        return $this;
    }

    public function blocks(Closure|array $blocks): static
    {
        if ($blocks instanceof \Closure) {
            throw new Exception('Not supported yet.');
        }

        $list = [];

        foreach ($blocks as $block) {
            $made = $block::make($block::getSystemName());
            if ($made instanceof BlockEditorBlock) {
                $list[] = $made;
            }
        }

        $this->childComponents($list);

        return $this;
    }

    public function getChildComponentContainers(bool $withHidden = false): array
    {
        $relationship = $this->getRelationship();

        $records = $relationship ? $this->getCachedExistingRecords() : null;

        return collect($this->getState())
            ->filter(fn (array $itemData): bool => $this->hasBlock($itemData['type']))
            ->map(
                fn (array $itemData, $itemIndex): ComponentContainer => $this
                    ->getBlock($itemData['type'])
                    ->getChildComponentContainer()
                    ->model($relationship ? $records[$itemIndex] ?? $this->getRelatedModel() : null)
                    ->getClone()
                    ->statePath("{$itemIndex}.data")
                    ->inlineLabel(false),
            )
            ->all();
    }

    public function relationship(string|Closure|null $name = null, ?Closure $callback = null): static
    {
        $this->relationship = $name ?? $this->getName();
        $this->modifyRelationshipQueryUsing = $callback;

        $this->afterStateHydrated(null);

        $this->loadStateFromRelationshipsUsing(static function (BlockEditor $component) {
            $component->clearCachedExistingRecords();

            $component->fillFromRelationship();
        });

        $this->saveRelationshipsUsing(static function (BlockEditor $component, HasForms $livewire, ?array $state) {
            if (! is_array($state)) {
                $state = [];
            }

            $relationship = $component->getRelationship();

            $existingRecords = $component->getCachedExistingRecords();

            $recordsToDelete = [];

            foreach ($existingRecords->pluck($relationship->getRelated()->getKeyName()) as $keyToCheckForDeletion) {
                if (array_key_exists("record-{$keyToCheckForDeletion}", $state)) {
                    continue;
                }

                $recordsToDelete[] = $keyToCheckForDeletion;
            }

            $relationship
                ->whereIn($relationship->getRelated()->getQualifiedKeyName(), $recordsToDelete)
                ->get()
                ->each(static fn (Model $record) => $record->delete());

            $childComponentContainers = $component->getChildComponentContainers();

            $itemOrder = 1;
            $orderColumn = $component->getOrderColumn();

            $activeLocale = $livewire->getActiveFormLocale();

            foreach ($childComponentContainers as $itemKey => $item) {
                $itemData = $item->getState(shouldCallHooksBefore: false);

                if ($orderColumn) {
                    $itemData[$orderColumn] = $itemOrder;

                    $itemOrder++;
                }

                /** @var Model $record */
                if ($record = ($existingRecords[$itemKey] ?? null)) {
                    $activeLocale && method_exists($record, 'setLocale') && $record->setLocale($activeLocale);

                    $itemData = $component->mutateRelationshipDataBeforeSave($itemData, record: $record);

                    $record->fill($itemData)->save();

                    continue;
                }

                $relatedModel = $component->getRelatedModel();

                $record = new $relatedModel();

                if ($activeLocale && method_exists($record, 'setLocale')) {
                    $record->setLocale($activeLocale);
                }

                /** @var ComponentContainer $item */
                $itemData = $component->mutateRelationshipDataBeforeCreate($itemData, $item->getParentComponent());

                $record->fill($itemData);

                $record = $relationship->save($record);
                $item->model($record)->saveRelationships();
            }
        });

        $this->dehydrated(false);

        return $this;
    }

    protected function getRelatedModel(): string
    {
        return $this->getRelationship()->getModel()::class;
    }

    public function clearCachedExistingRecords(): void
    {
        $this->cachedExistingRecords = null;
    }

    public function fillFromRelationship(): void
    {
        $this->state(
            $this->getStateFromRelatedRecords($this->getCachedExistingRecords()),
        );
    }

    public function getCachedExistingRecords(): Collection
    {
        if ($this->cachedExistingRecords) {
            return $this->cachedExistingRecords;
        }

        $relationship = $this->getRelationship();
        $relationshipQuery = $relationship->getQuery();

        if ($this->modifyRelationshipQueryUsing) {
            $relationshipQuery = $this->evaluate($this->modifyRelationshipQueryUsing, [
                'query' => $relationshipQuery,
            ]) ?? $relationshipQuery;
        }

        if ($orderColumn = $this->getOrderColumn()) {
            $relationshipQuery->orderBy($orderColumn);
        }

        $relatedKeyName = $relationship->getRelated()->getKeyName();

        return $this->cachedExistingRecords = $relationshipQuery->get()->mapWithKeys(
            fn (Model $item): array => ["record-{$item[$relatedKeyName]}" => $item],
        );
    }

    public function getRelationship(): HasOneOrMany|BelongsToMany|null
    {
        if (! $this->hasRelationship()) {
            return null;
        }

        return $this->getModelInstance()->{$this->getRelationshipName()}();
    }

    public function hasRelationship(): bool
    {
        return filled($this->getRelationshipName());
    }

    public function getRelationshipName(): ?string
    {
        return $this->evaluate($this->relationship);
    }

    protected function getStateFromRelatedRecords(Collection $records): array
    {
        if (! $records->count()) {
            return [];
        }

        $activeLocale = $this->getLivewire()->getActiveFormLocale();

        return $records
            ->map(function (Model $record) use ($activeLocale): array {
                $state = $record->attributesToArray();

                if (
                    $activeLocale &&
                    method_exists($record, 'getTranslatableAttributes') &&
                    method_exists($record, 'getTranslation')
                ) {
                    foreach ($record->getTranslatableAttributes() as $attribute) {
                        $state[$attribute] = $record->getTranslation($attribute, $activeLocale);
                    }
                }

                return $this->mutateRelationshipDataBeforeFill($state);
            })
            ->toArray();
    }

    public function mutateRelationshipDataBeforeCreate(array $data, BlockEditorBlock $item): array
    {
        if ($this->mutateRelationshipDataBeforeCreateUsing instanceof Closure) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeCreateUsing, [
                'data' => $data,
            ]);
        }

        $newData = ['type' => $item->getName()];
        foreach ($data as $field => $value) {
            if (in_array($field, $this->coreFields, true)) {
                $newData[$field] = $value;
            } else {
                $newData['content'][$field] = $value;
            }
        }

        return $newData;
    }

    public function mutateRelationshipDataBeforeSave(array $data, Model $record): array
    {
        if ($this->mutateRelationshipDataBeforeSaveUsing instanceof Closure) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeSaveUsing, [
                'data' => $data,
                'record' => $record,
            ]);
        }

        $data['type'] = $record->type;

        $newData = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $this->coreFields, true)) {
                $newData[$field] = $value;
            } else {
                $newData['content'][$field] = $value;
            }
        }

        return $newData;
    }

    public function mutateRelationshipDataBeforeFill(array $data): array
    {
        if ($this->mutateRelationshipDataBeforeFillUsing instanceof Closure) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeFillUsing, [
                'data' => $data,
            ]);
        }

        if (is_array($data['content'])) {
            foreach ($data['content'] as $field => $value) {
                if (! in_array($field, $this->coreFields, true)) {
                    $data['data'][$field] = $value;
                }
            }

            unset($data['content']);
        }

        return $data;
    }

    public function getOrderColumn(): ?string
    {
        return $this->evaluate($this->orderColumn);
    }

    public function renderInView(string|Closure $string): static
    {
        $this->renderInView = $string;

        return $this;
    }

    public function preview(ComponentContainer $container): View|string
    {
        if (! $view = $this->evaluate($this->renderInView)) {
            return __('renderInView not set or null');
        }

        try {
            return view(
                $view,
                ['preview' => $container->getParentComponent()->renderDisplay($container->getState())]
            );
        } catch (ErrorException|Exception $e) {
            return __('Error when rendering: :phError', ['phError' => $e->getMessage()]);
        }
    }
}
