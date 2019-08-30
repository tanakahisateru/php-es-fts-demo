<?php
namespace App\Service\BookSearch;

use App\Entity\Book;
use App\Repository\BookRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;

class DatabaseQuerySearcher implements SearcherInterface
{
    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    /**
     * @param ManagerRegistry $doctrine
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
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

        $entityManager = $this->doctrine->getManager();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $qb = $this->createBaseQueryBuilderPrototype($word, $bookRepository);
        $result->total = $this->queryCount($qb);
        $result->entities = $this->queryBody($page, $qb);

        $result->took = sprintf('%0.2fms', (microtime(true) - $start) * 1000);

        return $result;
    }

    private function createBaseQueryBuilderPrototype(string $word, BookRepository $bookRepository)
    {
        $qb = $bookRepository->createQueryBuilder('book');
        if (!empty($word)) {
            $likeExpression = '%' . addcslashes($word, '%_') . '%';
            $qb
                ->where("book.title LIKE :word")
                ->orWhere("book.contents LIKE :word")
                ->setParameter(':word', $likeExpression)
            ;
        }
        return $qb;
    }

    private function queryCount(QueryBuilder $qb): int
    {
        $qb = clone $qb;
        $qb->select("count(book)");

        try {
            return (int)$qb->getQuery()->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            throw new \UnexpectedValueException();
        }
    }

    private function queryBody(int $page, QueryBuilder $qb): array
    {
        $qb = clone $qb;
        $qb
            ->orderBy('book.title', 'ASC')
            ->setFirstResult(($page - 1) * self::ITEMS_PER_PAGE)
            ->setMaxResults(self::ITEMS_PER_PAGE)
        ;

        return $qb->getQuery()->getResult();
    }
}