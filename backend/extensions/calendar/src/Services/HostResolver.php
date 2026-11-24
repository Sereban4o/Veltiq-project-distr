<?php

namespace App\Extensions\Calendar\Services;

interface HostResolver
{
    /**
     * Resolve a hostname to a list of IP addresses.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array;
}
