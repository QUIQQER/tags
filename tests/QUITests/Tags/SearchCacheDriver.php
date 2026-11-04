<?php

namespace QUITests\Tags;

use Stash\Driver\Ephemeral;

class SearchCacheDriver extends Ephemeral
{
    public function advanceTime(int $seconds): void
    {
        foreach ($this->store as $key => $entry) {
            $this->store[$key]['expiration'] -= $seconds;
            $this->store[$key]['data']['createdOn'] -= $seconds;
        }
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return array_keys($this->store);
    }
}
