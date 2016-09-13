<?php

namespace Nuwave\Relay\Support;

use Illuminate\Database\Eloquent\Collection;
use Nuwave\Relay\Traits\GlobalIdTrait;
use Illuminate\Database\Eloquent\Model;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use StorWork\Core\GraphQL\Services\GraphQLHelper;
use StorWork\Core\Models\History;
use StorWork\Core\Traits\ActiveGroupTrait;
use StorWork\Core\Http\Controllers\AuthenticateController;
use StorWork\Permissions\Models\Group;
use StorWork\Core\GraphQL\Services\ElasticSearch;

class ConnectionResolver
{
    use ActiveGroupTrait;

    private $args;

    /**
     * Attempt to auto-resolve connection.
     *
     * @param  mixed      $root
     * @param  array       $args
     * @param  ResolveInfo $info
     * @param  string      $name
     * @return LengthAwarePaginator
     */
    public function resolve($root, array $args, ResolveInfo $info, $name = '')
    {
        $this->setArgs($args);

        $items = $this->getItems($root, $info, $name);

        if (isset($args['first'])) {
            $total       = (($items instanceof Paginator) ? $items->total() : $items->count());
            $first       = $args['first'];
            $after       = $this->decodeCursor($args);
            $currentPage = $first && $after ? floor(($first + $after) / $first) : 1;

            return new Paginator(
                $items->slice($after)->take($first),
                $total,
                $first,
                $currentPage
            );
        }

        return new Paginator(
            $items,
            count($items),
            (count($items) > 0 ? count($items) : 1)
        );
    }

    /**
     * @param             $collection
     * @param ResolveInfo $info
     * @param             $name
     * @return mixed|Collection
     */
    protected function getItems($collection, ResolveInfo $info, $name)
    {
        $items = [];

        if ($collection instanceof Model) {
            if (in_array($name, array_keys($collection->getRelations()))) {
                return $collection->{$name};
            }

            $items = method_exists($collection, $name)
                ? $collection->{$name}()->get() //->select(...$this->getSelectFields($info))->get()
                : $collection->getAttribute($name);

            if(is_null($items)) {
                return null;
            }

            $class = get_class($items->first());
            $model = new $class;

            $newItems = new Collection();
            if($model instanceof Model) {
                $ids = $items->lists('id')->toArray();

                if($name == 'histories') {
                    $history = new History();
                    $model->setConnection($history->getConnection()->getName());
                }

                if(class_exists(\Elastica\Client::class)) {
                    $activeGroup = AuthenticateController::getActiveGroup();
                    $index = Group::findOrFail($activeGroup['id'])->slug;
                    $type = str_plural(strtolower(class_basename($model)));
                    $elasticsearch = new ElasticSearch($index, $type, $ids);
                    $newItems = $elasticsearch->filter($this->getArgs());
                } else {
                    $entityModel = new GraphQLHelper($model, $ids);
                    $newItems = $entityModel->orderBy($this->getArgs());
                    $newItems = $newItems->modelKeys();
                }
            }

            return $items->only($newItems);

        } elseif (is_object($collection) && method_exists($collection, 'get')) {
            $items = $collection->get($name);
            return $items;
        } elseif (is_array($collection) && isset($collection[$name])) {
            return collect($collection[$name]);
        }

        return $items;
    }


    /**
     * @return mixed
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * @param mixed $args
     */
    public function setArgs($args)
    {
        $this->args = $args;
    }

    /**
     * Select only certain fields on queries instead of all fields.
     *
     * @param ResolveInfo $info
     * @return array
     */
    protected function getSelectFields(ResolveInfo $info)
    {
        $camel = config('relay.camel_case');

        return collect($info->getFieldSelection(4)['edges']['node'])
            ->reject(function ($value) {
                is_array($value);
            })->keys()->transform(function ($value) use ($camel) {
                if ($camel) {
                    return snake_case($value);
                }

                return $value;
            })->toArray();
    }

    /**
     * Decode cursor from query arguments.
     *
     * @param  array  $args
     * @return integer
     */
    public function decodeCursor(array $args)
    {
        return isset($args['after']) ? $this->getCursorId($args['after']) : 0;
    }

    /**
     * Get id from encoded cursor.
     *
     * @param  string $cursor
     * @return integer
     */
    protected function getCursorId($cursor)
    {
        return (int)$this->decodeRelayId($cursor);
    }
}