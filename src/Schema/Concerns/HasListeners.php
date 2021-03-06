<?php

/*
 * This file is part of tobyz/json-api-server.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tobyz\JsonApiServer\Schema\Concerns;

trait HasListeners
{
    protected $listeners = [];

    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }
}
