<?php

/*
 * This file is part of tobyz/json-api-server.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tobyz\JsonApiServer\Handler;

use Illuminate\Support\Arr;
use JsonApiPhp\JsonApi as Structure;
use JsonApiPhp\JsonApi\Link\LastLink;
use JsonApiPhp\JsonApi\Link\NextLink;
use JsonApiPhp\JsonApi\Link\PrevLink;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Exception\BadRequestException;
use Tobyz\JsonApiServer\JsonApi;
use Tobyz\JsonApiServer\JsonApiResponse;
use Tobyz\JsonApiServer\ResourceType;
use Tobyz\JsonApiServer\Schema\Attribute;
use Tobyz\JsonApiServer\Schema\HasMany;
use Tobyz\JsonApiServer\Schema\HasOne;
use Tobyz\JsonApiServer\Serializer;
use function Tobyz\JsonApiServer\evaluate;
use function Tobyz\JsonApiServer\run_callbacks;

class Index implements RequestHandlerInterface
{
    use Concerns\IncludesData;

    private $api;
    private $resource;

    public function __construct(JsonApi $api, ResourceType $resource)
    {
        $this->api = $api;
        $this->resource = $resource;
    }

    /**
     * Handle a request to show a resource listing.
     */
    public function handle(Request $request): Response
    {
        $adapter = $this->resource->getAdapter();
        $schema = $this->resource->getSchema();

        run_callbacks($schema->getListeners('listing'), [&$request]);

        $query = $adapter->query();

        run_callbacks($schema->getListeners('scope'), [$query, $request, null]);

        $include = $this->getInclude($request);

        [$offset, $limit] = $this->paginate($query, $request);
        $this->sort($query, $request);
        $this->filter($query, $request);

        $total = $schema->isCountable() ? $adapter->count($query) : null;
        $models = $adapter->get($query);

        $this->loadRelationships($models, $include, $request);

        run_callbacks($schema->getListeners('listed'), [$models, $request]);

        $serializer = new Serializer($this->api, $request);

        foreach ($models as $model) {
            $serializer->add($this->resource, $model, $include);
        }
        
        $context = new Context;
        $context->query = $query;
        $documentMeta = [];

        foreach ($schema->getDocumentMeta() as $key => $value) {
            $documentMeta[] = new Structure\Meta($key, $value($context));
        }

        return new JsonApiResponse(
            new Structure\CompoundDocument(
                new Structure\PaginatedCollection(
                    new Structure\Pagination(...$this->buildPaginationLinks($request, $offset, $limit, count($models), $total)),
                    new Structure\ResourceCollection(...$serializer->primary())
                ),
                new Structure\Included(...$serializer->included()),
                new Structure\Link\SelfLink($this->buildUrl($request)),
                new Structure\Meta('offset', $offset),
                new Structure\Meta('limit', $limit),
                ...($total !== null ? [new Structure\Meta('total', $total)] : []),
                ...$documentMeta
            )
        );
    }

    private function buildUrl(Request $request, array $overrideParams = []): string
    {
        [$selfUrl] = explode('?', $request->getUri(), 2);

        $queryParams = array_replace_recursive($request->getQueryParams(), $overrideParams);

        if (isset($queryParams['page']['offset']) && $queryParams['page']['offset'] <= 0) {
            unset($queryParams['page']['offset']);
        }

        if (isset($queryParams['filter'])) {
            foreach ($queryParams['filter'] as $k => &$v) {
                $v = $v === null ? '' : $v;
            }
        }

        $queryString = Arr::query($queryParams);

        return $selfUrl.($queryString ? '?'.$queryString : '');
    }

    private function buildPaginationLinks(Request $request, int $offset, ?int $limit, int $count, ?int $total)
    {
        $paginationLinks = [];
        $schema = $this->resource->getSchema();

        if ($offset > 0) {
            $paginationLinks[] = new Structure\Link\FirstLink($this->buildUrl($request, ['page' => ['offset' => 0]]));

            $prevOffset = $offset - $limit;

            if ($prevOffset < 0) {
                $params = ['page' => ['offset' => 0, 'limit' => $offset]];
            } else {
                $params = ['page' => ['offset' => max(0, $prevOffset)]];
            }

            $paginationLinks[] = new PrevLink($this->buildUrl($request, $params));
        }

        if ($schema->isCountable() && $schema->getPerPage() && $offset + $limit < $total) {
            $paginationLinks[] = new LastLink($this->buildUrl($request, ['page' => ['offset' => floor(($total - 1) / $limit) * $limit]]));
        }

        if (($total === null && $count === $limit) || $offset + $limit < $total) {
            $paginationLinks[] = new NextLink($this->buildUrl($request, ['page' => ['offset' => $offset + $limit]]));
        }

        return $paginationLinks;
    }

    private function sort($query, Request $request)
    {
        $schema = $this->resource->getSchema();

        if (! $sort = $request->getQueryParams()['sort'] ?? $schema->getDefaultSort()) {
            return;
        }

        $adapter = $this->resource->getAdapter();
        $sortFields = $schema->getSortFields();
        $fields = $schema->getFields();

        foreach ($this->parseSort($sort) as $name => $direction) {
            if (isset($sortFields[$name])) {
                $sortFields[$name]($query, $direction, $request);
                continue;
            }

            if (
                isset($fields[$name])
                && $fields[$name] instanceof Attribute
                && evaluate($fields[$name]->isSortable(), [$request])
            ) {
                $adapter->sortByAttribute($query, $fields[$name], $direction);
                continue;
            }

            throw new BadRequestException("Invalid sort field [$name]", 'sort');
        }
    }

    private function parseSort(string $string): array
    {
        $sort = [];

        foreach (explode(',', $string) as $field) {
            if ($field[0] === '-') {
                $field = substr($field, 1);
                $direction = 'desc';
            } else {
                $direction = 'asc';
            }

            $sort[$field] = $direction;
        }

        return $sort;
    }

    private function paginate($query, Request $request)
    {
        $schema = $this->resource->getSchema();
        $queryParams = $request->getQueryParams();
        $limit = $schema->getPerPage();

        if (isset($queryParams['page']['limit'])) {
            $limit = $queryParams['page']['limit'];

            if (! ctype_digit(strval($limit)) || $limit < 1) {
                throw new BadRequestException('page[limit] must be a positive integer', 'page[limit]');
            }

            $limit = min($schema->getLimit(), $limit);
        }

        $offset = 0;

        if (isset($queryParams['page']['offset'])) {
            $offset = $queryParams['page']['offset'];

            if (! ctype_digit(strval($offset)) || $offset < 0) {
                throw new BadRequestException('page[offset] must be a non-negative integer', 'page[offset]');
            }
        }

        if ($limit || $offset) {
            $this->resource->getAdapter()->paginate($query, $limit, $offset);
        }

        return [$offset, $limit];
    }

    private function filter($query, Request $request)
    {
        if (! $filter = $request->getQueryParams()['filter'] ?? null) {
            return;
        }

        if (! is_array($filter)) {
            throw new BadRequestException('filter must be an array', 'filter');
        }

        $schema = $this->resource->getSchema();
        $adapter = $this->resource->getAdapter();
        $filters = $schema->getFilters();
        $fields = $schema->getFields();

        foreach ($filter as $name => $value) {
            if ($name === 'id') {
                $adapter->filterByIds($query, explode(',', $value));
                continue;
            }

            if (isset($filters[$name])) {
                $filters[$name]->getCallback()($query, $value, $request);
                continue;
            }

            if (isset($fields[$name]) && evaluate($fields[$name]->isFilterable(), [$request])) {
                if ($fields[$name] instanceof Attribute) {
                    $adapter->filterByAttribute($query, $fields[$name], $value);
                } elseif ($fields[$name] instanceof HasOne) {
                    $value = explode(',', $value);
                    $adapter->filterByHasOne($query, $fields[$name], $value);
                } elseif ($fields[$name] instanceof HasMany) {
                    $value = explode(',', $value);
                    $adapter->filterByHasMany($query, $fields[$name], $value);
                }
                continue;
            }

            throw new BadRequestException("Invalid filter [$name]", "filter[$name]");
        }
    }
}
