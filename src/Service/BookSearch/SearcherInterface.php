<?php
namespace App\Service\BookSearch;

interface SearcherInterface
{
    const ITEMS_PER_PAGE = 20;

    /**
     * @param string $word
     * @param int $page
     * @return Result
     */
    public function search(string $word, int $page): Result;
}