<?php

namespace ZiffMedia\NovaSelectPlus;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ResourceRelationshipGuesser;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Laravel\Nova\TrashedStatus;

class SelectPlus extends Field implements RelatableField
{
    public $component = 'select-plus';

    public $relationshipResource = null;

    public $label = 'name';

    public $indexLabel = null;
    public $detailLabel = null;

    public $valueForIndexDisplay = null;
    public $valueForDetailDisplay = null;

    public $maxSelections = null;
    public $ajaxSearchable = null;
    public $reorderable = null;

    public function __construct($name, $attribute = null, $relationshipResource = null, $label = 'name')
    {
        parent::__construct($name, $attribute);

        $this->relationshipResource = $relationshipResource ?? ResourceRelationshipGuesser::guessResource($name);

        if (!class_exists($this->relationshipResource)) {
            throw new \RuntimeException("Relationship Resource {$this->relationshipResource} is not a valid class");
        }

        $this->label = $label;
    }

    public function label($label)
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param string|callable $indexLabel
     * @return $this
     */
    public function usingIndexLabel($indexLabel)
    {
        $this->indexLabel = $indexLabel;

        return $this;
    }

    /**
     * @param string|callable $detailLabel
     * @return $this
     */
    public function usingDetailLabel($detailLabel)
    {
        $this->detailLabel = $detailLabel;

        return $this;
    }

    public function maxSelections($maxSelections)
    {
        $this->maxSelections = $maxSelections;

        return $this;
    }

    public function ajaxSearchable($ajaxSearchable)
    {
        $this->ajaxSearchable = $ajaxSearchable;

        return $this;
    }

    public function reorderable(string $orderAttribute)
    {
        $this->reorderable = $orderAttribute;

        return $this;
    }

    /**
     * @param mixed|Resource|Model $resource
     * @param null $attribute
     */
    public function resolve($resource, $attribute = null)
    {
        // use base functionality to populate $this->value
        parent::resolve($resource, $attribute);

        // handle setting up values for relations
        if (method_exists($resource, $this->attribute)) {
            $this->resolveForRelations($resource);

            return;
        }

        throw new \RuntimeException('Currently attributes are not yet supported');

        // @todo $this->resolveForAttribute($resource);
    }

    protected function resolveForRelations($resource)
    {
        $relationQuery = $resource->{$this->attribute}();

        if (!$relationQuery instanceof BelongsToMany) {
            throw new \RuntimeException('This field currently only supports MorphsToMany and BelongsToMany');
        }

        // if the value is requested on the INDEX field, we need to roll it up to show something
        if ($this->indexLabel) {
            $this->valueForIndexDisplay = is_callable($this->indexLabel)
                ? call_user_func($this->indexLabel, $this->value)
                : $this->value->pluck($this->indexLabel)->implode(', ');
        } else {
            $count = $this->value->count();

            $this->valueForIndexDisplay = $count . ' ' . $this->name;
        }

        if ($this->detailLabel) {
            $this->valueForDetailDisplay = is_callable($this->detailLabel)
                ? call_user_func($this->detailLabel, $this->value)
                : $this->value->pluck($this->detailLabel)->implode(', ');
        } else {
            $count = $this->value->count();

            $this->valueForDetailDisplay = $count . ' ' . $this->name;
        }

        // convert to {key: xxx, label: xxx} format
        $this->value = $this->mapToSelectionValue($this->value);
    }

    protected function resolveForAttribute($resource)
    {
        if ($this->options === null) {
            throw new \RuntimeException('For attributes using SelectPlus, options() must be available');
        }

        $casts = $resource->getCasts();

        // @todo do things specific to the kind of cast it is, or throw exception, if no cast, assume its options with string types
    }

    protected function fillAttribute(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        // returning a function allows this to run after the model has been saved (which is crucial if this is a new model)
        return function () use ($request, $requestAttribute, $model, $attribute) {
            $values = collect(json_decode($request[$requestAttribute], true));

            $keyName = $model->getKeyName();

            if ($this->reorderable) {
                $syncValues = $values->mapWithKeys(function ($value, $index) use ($keyName) {
                    return [$value[$keyName] => [$this->reorderable => $index + 1]];
                });
            } else {
                $syncValues = $values->pluck($keyName);
            }

            $model->{$attribute}()->sync($syncValues);
        };
    }

    /**
     * Build an attachable query for the field.
     *
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @param bool $withTrashed
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function buildAttachableQuery(NovaRequest $request, $withTrashed = false)
    {
        $model = forward_static_call([$resourceClass = $this->resourceClass, 'newModel']);

        $query = $request->first === 'true'
            ? $model->newQueryWithoutScopes()->whereKey($request->current)
            : $resourceClass::buildIndexQuery(
                $request, $model->newQuery(), $request->search,
                [], [], TrashedStatus::fromBoolean($withTrashed)
            );

        return $query->tap(function ($query) use ($request, $model) {
            forward_static_call($this->attachableQueryCallable($request, $model), $request, $query);
        });
    }

    /**
     * Get the attachable query method name.
     *
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    protected function attachableQueryCallable(NovaRequest $request, $model)
    {
        return ($method = $this->attachableQueryMethod($request, $model))
            ? [$request->resource(), $method]
            : [$this->resourceClass, 'relatableQuery'];
    }

    /**
     * Get the attachable query method name.
     *
     * @param \Laravel\Nova\Http\Requests\NovaRequest $request
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    protected function attachableQueryMethod(NovaRequest $request, $model)
    {
        $method = 'relatable' . Str::plural(class_basename($model));

        if (method_exists($request->resource(), $method)) {
            return $method;
        }
    }

    public function mapToSelectionValue(Collection $models)
    {
        return $models->map(function (Model $model) {
            // todo add order field
            return [
                $model->getKeyName() => $model->getKey(),
                'label' => $model->{$this->label}
            ];
        });
    }

    public function jsonSerialize()
    {
        return array_merge(parent::jsonSerialize(), [
            'label' => $this->label,
            'ajax_searchable' => $this->ajaxSearchable !== null,
            'relationship_name' => $this->attribute,
            'value_for_index_display' => $this->valueForIndexDisplay,
            'value_for_detail_display' => $this->valueForDetailDisplay,
            'max_selections' => $this->maxSelections,
            'reorderable' => $this->reorderable !== null
        ]);
    }
}

