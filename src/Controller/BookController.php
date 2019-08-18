<?php

namespace App\Controller;

use App\Entity\Book;
use App\Repository\BookRepository;
use Elasticsearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class BookController extends AbstractController
{
    /**
     * @var Client
     */
    protected $elasticsearchClient;

    /**
     * @param Client $elasticsearchClient
     */
    public function __construct(Client $elasticsearchClient)
    {
        $this->elasticsearchClient = $elasticsearchClient;
    }

    /**
     * @Route("/book/sql", name="book-sql")
     */
    public function searchBySql()
    {
        $start = microtime(true);

        $entityManager = $this->getDoctrine()->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $books = $bookRepository->createQueryBuilder('book')
            ->where("book.contents LIKE :word")
            ->setParameter(':word', '%MAIORES%')
            ->orderBy('book.title','DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->json([
            'took' => sprintf('%0.2fms', (microtime(true) - $start) * 1000),
            'books' => $this->jsonifyBooks($books),
        ]);
    }

    /**
     * @Route("/book/es", name="book-es")
     */
    public function searchByEs()
    {
        $start = microtime(true);

        $response = $this->elasticsearchClient->search([
            'index' => 'book',
            // 'type' => 'book',
            'body' => [
                'query' => [
                    'match' => [
                        'contents' => 'maiores',
                    ],
                    // 'match_all' => new \stdClass(),
                ],
                'sort' => [
                    ['title' => ['order' => 'desc']],
                    //['_score' => ['order' => 'desc']],
                ],
                'size' => 20,
            ]
        ]);

        $ids = array_map(function (array $hit) {
            return $hit['_id'];
        }, $response['hits']['hits']);

        // check $response['hits']['total'] for navigation

        $entityManager = $this->getDoctrine()->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $nonOrderedBooks = $bookRepository->findBy(['id' => $ids]);
        $books = $this->reorderEntities($ids, $nonOrderedBooks);

        return $this->json([
            'took' => sprintf('%0.2fms', (microtime(true) - $start) * 1000),
            'books' => $this->jsonifyBooks($books),
        ]);
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
