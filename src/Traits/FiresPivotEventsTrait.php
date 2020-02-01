<?php

namespace Fico7489\Laravel\Pivot\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

trait FiresPivotEventsTrait
{
    /**
     * Attach a model to the parent.
     *
     * @param mixed $id
     * @param array $attributes
     * @param bool  $touch
     */
    public function attach($ids, array $attributes = [], $touch = true)
    {
        list($idsOnly, $idsAttributes) = $this->getIdsWithAttributes($ids, $attributes);

        $this->parent->fireModelEvent('pivotAttaching', true, $this->getRelationName(), $idsOnly, $idsAttributes);
        $parentResult = parent::attach($ids, $attributes, $touch);
        $this->parent->fireModelEvent('pivotAttached', false, $this->getRelationName(), $idsOnly, $idsAttributes);

        return $parentResult;
    }

    /**
     * Detach models from the relationship.
     *
     * @param mixed $ids
     * @param bool  $touch
     *
     * @return int
     */
    public function detach($ids = null, $touch = true)
    {
        if (is_null($ids)) {
            $ids = $this->query->pluck($this->query->qualifyColumn($this->relatedKey))->toArray();
        }

        list($idsOnly) = $this->getIdsWithAttributes($ids);
        $pivots = $this->newPivotStatementForId($idsOnly)->get();

        $this->parent->fireModelEvent('pivotDetaching', true, $this->getRelationName(), $idsOnly);
        $parentResult = parent::detach($ids, $touch);
        $this->parent->fireModelEvent('pivotDetached', false, $this->getRelationName(), $idsOnly, $pivots);

        return $parentResult;
    }

    /**
     * Update an existing pivot record on the table.
     *
     * Only fire 'pivotUpdated' event if the data actually changed.
     * But 'pivotUpdating' event is always fired.
     *
     * Fixed original function issue where "updated_at" field was getting updated
     * regardless of whether the data changed or not, thus considering it "updated" always.
     *
     * It fixes that by first updating the database pivot record without "updated_at" field.
     * If the update was successful and returns 1 (real changes made), then we update
     * the "updated_at" field alone after.
     *
     * Downside is this performs 2 update operation to the database instead of 1.
     *
     * # https://github.com/laravel/framework/issues/30573
     *
     * @param mixed $id
     * @param array $attributes
     * @param bool  $touch
     *
     * @return int
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        list($idsOnly, $idsAttributes) = $this->getIdsWithAttributes($id, $attributes);
        $this->parent->fireModelEvent('pivotUpdating', true, $this->getRelationName(), $idsOnly, $idsAttributes);

        if ($this->using && empty($this->pivotWheres) && empty($this->pivotWhereIns)) {
            return $this->updateExistingPivotUsingCustomClass($id, $attributes, $touch);
        }

        $statement = $this->newPivotStatementForId($this->parseId($id));
        $original = $statement->first();

        $updated = $statement->update(
            $this->castAttributes($attributes)
        );

        if ($updated && in_array($this->updatedAt(), $this->pivotColumns)) {
            $timestampAttributes = $this->addTimestampsToAttachment([], true);

            $statement->update(
                $this->castAttributes($timestampAttributes)
            );
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        if ($updated) {
            $this->parent->fireModelEvent('pivotUpdated', false, $this->getRelationName(), $idsOnly, $idsAttributes, $original);
        }

        return $updated;
    }

    /**
     * Update an existing pivot record on the table via a custom class.
     *
     * Same changes/fixes applied as "updateExistingPivot" function above
     *
     * @param  mixed  $id
     * @param  array  $attributes
     * @param  bool  $touch
     * @return int
     */
    protected function updateExistingPivotUsingCustomClass($id, array $attributes, $touch)
    {
        $pivot = $this->getCurrentlyAttachedPivots()
                    ->where($this->foreignPivotKey, $this->parent->{$this->parentKey})
                    ->where($this->relatedPivotKey, $this->parseId($id))
                    ->first();

        $updated = $pivot ? $pivot->fill($attributes)->isDirty() : false;

        $pivot = $this->newPivot([
            $this->foreignPivotKey => $this->parent->{$this->parentKey},
            $this->relatedPivotKey => $this->parseId($id),
        ], true);

        $pivot->timestamps = $updated && in_array($this->updatedAt(), $this->pivotColumns);

        $pivot->fill($attributes)->save();

        if ($touch) {
            $this->touchIfTouching();
        }

        return (int) $updated;
    }

    /**
     * Cleans the ids and ids with attributes
     * Returns an array with and array of ids and array of id => attributes.
     *
     * @param mixed $id
     * @param array $attributes
     *
     * @return array
     */
    private function getIdsWithAttributes($id, $attributes = [])
    {
        $ids = [];

        if ($id instanceof Model) {
            $ids[$id->getKey()] = $attributes;
        } elseif ($id instanceof Collection) {
            foreach ($id as $model) {
                $ids[$model->getKey()] = $attributes;
            }
        } elseif (is_array($id)) {
            foreach ($id as $key => $attributesArray) {
                if (is_array($attributesArray)) {
                    $ids[$key] = array_merge($attributes, $attributesArray);
                } else {
                    $ids[$attributesArray] = $attributes;
                }
            }
        } elseif (is_int($id) || is_string($id)) {
            $ids[$id] = $attributes;
        }

        $idsOnly = array_keys($ids);

        return [$idsOnly, $ids];
    }
}
