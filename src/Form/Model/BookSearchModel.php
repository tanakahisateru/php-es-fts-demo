<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

class BookSearchModel
{
    /**
     * @var string
     */
    public $word;

    /**
     * @var string
     * @Assert\GreaterThanOrEqual(1)
     */
    public $page;
}