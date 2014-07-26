<?php namespace Tobscure\Permissible\Condition;

class Compiler
{
    protected $model;

    protected $user;

    public function __construct($model, $user)
    {
        $this->model = $model;
        $this->user = $user;
    }

    public function compile($conditions)
    {
        $query = $this->model->newQuery()->getQuery();

        if ($conditions === false) {
            return $query->whereRaw('FALSE');
        } elseif ($conditions === true || ! count($conditions->wheres)) {
            return $query;
        }

        foreach ($conditions->wheres as $k => &$where) {
            $method = "where{$where['type']}";

            $query = $this->$method($query, $where);
        }

        return $query;
    }

    protected function whereNested($query, $where)
    {
        return $query->addNestedWhereQuery($this->compile($where['query']));
    }

    protected function expandColumn($column)
    {
        $parts = explode('.', $column);
        $field = array_pop($parts);
        
        if ($parts) {

            $model = $this->model;
            $part = array_shift($parts);
            $relation = $model->$part();

            $column = $model->getTable().'.'.$relation->getForeignKey();

            $model = $relation->getModel();

            foreach ($parts as $part) {
                $relation = $model->$part();

                $column = DB::raw(
                    '('.DB::table($model->getTable())
                        ->select($relation->getForeignKey())
                        ->whereRaw($model->getTable().'.'.$model->getKeyName().' = '.$column)
                        ->toSql().')'
                );

                $model = $relation->getModel();
            }

            $column = DB::raw(
                '('.DB::table($model->getTable())
                    ->select($field)
                    ->whereRaw($model->getTable().'.'.$model->getKeyName().' = '.$column)
                    ->toSql().')'
            );

        } else {
            $column = $field;
        }

        return $column;
    }

    protected function whereBasic($query, $where)
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        $column = $this->expandColumn($column);

        return $query->where($column, $operator, $value, $where['boolean']);
    }

    protected function whereIn($query, $where)
    {
        $column = $where['column'];
        $value = $where['value'];

        $column = $this->expandColumn($column);

        return $query->whereIn($column, $value, $where['boolean']);
    }

    protected function whereNotIn($query, $where)
    {
        $column = $where['column'];
        $value = $where['value'];

        $column = $this->expandColumn($column);

        return $query->whereNotIn($column, $value, $where['boolean']);
    }

    protected function whereNull($query, $where)
    {
        $column = $where['column'];

        $column = $this->expandColumn($column);

        return $query->whereNull($column, $where['boolean']);
    }

    protected function whereNotNull($query, $where)
    {
        $column = $where['column'];

        $column = $this->expandColumn($column);

        return $query->whereNotNull($column, $where['boolean']);
    }

    protected function whereExists($query, $where)
    {
        $sub = $where['callback']($this->model->newQuery(), $this->model->getTable().'.'.$this->model->getKeyName());

        return $query->whereRaw('exists ('.$sub->toSql().')', $sub->getBindings(), $where['boolean']);
    }

    protected function whereNotExists($query, $where)
    {
        $sub = $where['callback']($this->model->newQuery(), $this->model->getTable().'.'.$this->model->getKeyName());

        return $query->whereRaw('not exists ('.$sub->toSql().')', $sub->getBindings(), $where['boolean']);
    }

    protected function whereCan($query, $where)
    {
        $relation = $where['relation'];

        $model = $this->model;
        
        if ($relation) {
            $model = $model->$relation()->getModel();
        }

        $conditions = $model->getPermissionWheres($this->user, $where['permission']);

        if (! $relation) {
            return $query->addNestedWhereQuery($conditions, $where['boolean']);

        } else {
            $relation = $this->model->$relation();
            $column = $this->model->getTable().'.'.$relation->getForeignKey();

            return $query->whereIn($column, function ($sub) use ($conditions, $relation) {
                $sub->select('id')
                    ->from($relation->getModel()->getTable())
                    ->addNestedWhereQuery($conditions);
            });
        }
    }

    protected function whereNotCan($query, $where)
    {
        $relation = $where['relation'];

        $model = $this->model;
        
        if ($relation) {
            $model = $model->$relation;
        }

        $conditions = $model->getPermissionWheres($this->user, $where['permission']);

        if (! $relation) {
            return $query->whereRaw('! ('.$conditions->toSql().')', $conditions->getBindings(), $where['boolean']);

        } else {
            $relation = $this->model->$relation();
            $column = $this->model->getTable().'.'.$relation->getForeignKey();

            return $query->whereNotIn($column, function ($sub) use ($conditions, $relation) {
                $sub->select('id')
                    ->from($relation->getModel()->getTable())
                    ->addNestedWhereQuery($conditions);
            });
        }
    }
}