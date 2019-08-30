<?php
namespace App\Controller;

use App\Service\BookSearch\DatabaseQuerySearcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DatabaseQueryBookSearchController extends AbstractController
{
    use SearchControllerTrait;

    /**
     * @var DatabaseQuerySearcher
     */
    protected $searcher;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @param DatabaseQuerySearcher $searcher
     * @param ValidatorInterface $validator
     */
    public function __construct(DatabaseQuerySearcher $searcher, ValidatorInterface $validator)
    {
        $this->searcher = $searcher;
        $this->validator = $validator;
    }

    /**
     * @Route("/book/sql", name="book-sql")
     * @param Request $request
     * @return Response
     */
    public function search(Request $request): Response
    {
        return $this->execute($request, $this->searcher)
            ->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
