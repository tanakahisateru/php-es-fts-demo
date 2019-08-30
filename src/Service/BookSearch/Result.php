<?php
namespace App\Service\BookSearch;

use App\Entity\Book;

class Result
{
    /**
     * @var string
     */
    public $word;

    /**
     * @var string
     */
    public $took;

    /**
     * @var int
     */
    public $total;

    /**
     * @var int
     */
    public $page;

    /**
     * @var Book[]
     */
    public $entities;
}