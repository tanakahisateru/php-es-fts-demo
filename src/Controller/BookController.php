<?php

namespace App\Controller;

use App\Entity\Book;
use App\Form\Model\BookSearchModel;
use App\Repository\BookRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\NonUniqueResultException;
use Elasticsearch\Client as ElasticsearchClient;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use UnexpectedValueException;

class BookController extends AbstractController
{
    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    /**
     * @var ElasticsearchClient
     */
    protected $elasticsearch;
    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @param ManagerRegistry $doctrine
     * @param ElasticsearchClient $elasticsearch
     * @param ValidatorInterface $validator
     */
    public function __construct(
        ManagerRegistry $doctrine,
        ElasticsearchClient $elasticsearch,
        ValidatorInterface $validator
    ) {
        $this->doctrine = $doctrine;
        $this->elasticsearch = $elasticsearch;
        $this->validator = $validator;
    }

    /**
     * @Route("/book/sql", name="book-sql")
     * @param Request $request
     * @return Response
     */
    public function searchBySql(Request $request): Response
    {
        $start = microtime(true);

        $searchModel = new BookSearchModel();
        $searchModel->word = $request->query->get('q', null);
        $searchModel->page = $request->query->get('page', 1);

        $errors = $this->validator->validate($searchModel);
        if ($errors->count() > 0) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => (string)$errors,
            ], 400);
        }

        $page = (int)$searchModel->page;

        $entityManager = $this->doctrine->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $qb = $bookRepository->createQueryBuilder('book');
        if (!empty($searchModel->word)) {
            $qb->where("book.contents LIKE :word")
                ->setParameter(':word', '%' . addcslashes($searchModel->word, '%_') . '%');
        }

        try {
            $countQb = clone $qb;
            $countQb->select("count(book)");
            $total = (int)$countQb->getQuery()->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            throw new UnexpectedValueException($e->getMessage());
        }

        $qb->orderBy('book.title','ASC');
        $qb->setFirstResult(($page - 1) * 20);
        $qb->setMaxResults(20);

        $books = $qb->getQuery()->getResult();

        return $this->json([
            'took' => sprintf('%0.2fms', (microtime(true) - $start) * 1000),
            'word' => $searchModel->word,
            'total' => $total,
            'books' => $this->jsonifyBooks($books),
        ])->setEncodingOptions(JSON_PRETTY_PRINT);
    }

    /**
     * @Route("/book/es", name="book-es")
     * @param Request $request
     * @return Response
     */
    public function searchByEs(Request $request): Response
    {
        $start = microtime(true);

        $searchModel = new BookSearchModel();
        $searchModel->word = $request->query->get('q', null);
        $searchModel->page = $request->query->get('page', 1);

        $errors = $this->validator->validate($searchModel);
        if ($errors->count() > 0) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => (string)$errors,
            ], 400);
        }

        $page = (int)$searchModel->page;

        if (!empty($searchModel->word)) {
            $query = [
                'match' => [
                    'contents' => $searchModel->word,
                ],
            ];
        } else {
            $query = [
                'match_all' => new stdClass(),
            ];
        }

        $response = $this->elasticsearch->search([
            'index' => 'book',
            // 'type' => 'book',
            'body' => [
                'query' => $query,
                'sort' => [
                    ['title' => ['order' => 'asc']],
                    //['_score' => ['order' => 'desc']],
                ],
                'from' => ($page - 1) * 20,
                'size' => 20,
            ]
        ]);

        $total = $response['hits']['total']['value'];
        // $response['hits']['total']['value'] == 'gt' ? '>' : '=';

        $ids = array_map(function (array $hit) {
            return $hit['_id'];
        }, $response['hits']['hits']);

        $entityManager = $this->doctrine->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $nonOrderedBooks = $bookRepository->findBy(['id' => $ids]);
        $books = $this->reorderEntities($ids, $nonOrderedBooks);

        return $this->json([
            'took' => sprintf('%0.2fms', (microtime(true) - $start) * 1000),
            'word' => $searchModel->word,
            'total' => $total,
            'books' => $this->jsonifyBooks($books),
        ])->setEncodingOptions(JSON_PRETTY_PRINT);
    }

    private function jsonifyBooks(array $books): array
    {
        return array_map(function (Book $book) {
            return [
                'id' => $book->getId(),
                'title' => $book->getTitle(),
                'contents' => mb_strimwidth($book->getContents(), 0, 80, '...'),
            ];
        }, $books);
    }

    private function reorderEntities(array $orderedIds, array $unsortedEntityBag): array
    {
        $id2entity = array_combine(array_map(function ($entity) {
            /** @var object $entity */
            return $entity->getId();
        }, $unsortedEntityBag), $unsortedEntityBag);

        $orderedEntities = array_map(function ($id) use ($id2entity) {
            return $id2entity[$id];
        }, $orderedIds);

        return $orderedEntities;
    }
}
