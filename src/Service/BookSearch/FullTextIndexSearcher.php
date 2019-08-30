<?php
namespace App\Service\BookSearch;

use App\Entity\Book;
use App\Repository\BookRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Elasticsearch\Client as ElasticsearchClient;
use stdClass;

class FullTextIndexSearcher implements SearcherInterface
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
     * @param ManagerRegistry $doctrine
     * @param ElasticsearchClient $elasticsearch
     */
    public function __construct(ManagerRegistry $doctrine, ElasticsearchClient $elasticsearch)
    {
        $this->doctrine = $doctrine;
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * @param string $word
     * @param int $page
     * @return Result
     */
    public function search(string $word, int $page): Result
    {
        $start = microtime(true);

        $result = new Result();
        $result->word = $word;

        if (empty($word)) {
            $query = $this->wordUnspecifiedQuery();
        } else if (mb_strlen($word, 'utf-8') == 1) {
            $query = $this->singleLetterQuery($word);
        } else {
            $query = $this->standardQuery($word);
        }

        $response = $this->send($query, $page);

        $result->total = $response['hits']['total']['value'];
        // $response['hits']['total']['value'] == 'gt' ? '>' : '=';

        $orderedIds = array_map(function (array $hit) {
            return $hit['_id'];
        }, $response['hits']['hits']);

        $unorderedBooks = $this->fetchBooksFromDatabase($orderedIds);
        $result->entities = $this->reorderEntities($orderedIds, $unorderedBooks);

        $result->took = sprintf('%0.2fms', (microtime(true) - $start) * 1000);

        return $result;
    }

    private function wordUnspecifiedQuery(): array
    {
        return [
            'match_all' => new stdClass(),
        ];
    }

    private function singleLetterQuery(string $word): array
    {
        return [
            'bool' => [
                'should' => [
                    ['match' => ['title.unigram' => $word]],
                    ['match' => ['contents.unigram' => $word]],
                ]
            ]
        ];
    }

    private function standardQuery(string $word): array
    {
        return [
            'bool' => [
                'should' => [
                    ['match_phrase' => ['title.bigram' => $word]],
                    ['match_phrase' => ['contents.bigram' => $word]],
                ]
            ]
        ];
    }

    private function send(array $query, int $page): array
    {
        return $this->elasticsearch->search([
            'index' => 'book',
            'body' => [
                'query' => $query,
                'sort' => [
                    ['title' => ['order' => 'asc']],
                    //['_score' => ['order' => 'desc']],
                ],
                'from' => ($page - 1) * self::ITEMS_PER_PAGE,
                'size' => self::ITEMS_PER_PAGE,
            ]
        ]);
    }

    private function fetchBooksFromDatabase(array $orderedIds): array
    {
        $entityManager = $this->doctrine->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        return $bookRepository->findBy(['id' => $orderedIds]);
    }

    private function reorderEntities(array $orderedIds, array $unsortedEntityBag): array
    {
        $id2entity = array_combine(array_map(function ($entity) {
            /** @var object $entity */
            return $entity->getId();
        }, $unsortedEntityBag), $unsortedEntityBag);

        return array_map(function ($id) use ($id2entity) {
            return $id2entity[$id];
        }, $orderedIds);
    }
}