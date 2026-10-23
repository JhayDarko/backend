<?php

namespace App\Contracts\Article;

interface HostResolverInterface
{
    /**
     * @return list<string>
     */
    public function resolve(string $hostname): array;
}
