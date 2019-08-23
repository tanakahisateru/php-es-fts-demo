<?php

namespace App\Command;

use App\Command\Exception\ElasticserchAcknowledgementException;
use App\Entity\Book;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Elasticsearch\Client as ElasticsearchClient;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

class InitSearchIndexCommand extends Command
{
    const BATCH_SIZE = 1000;
    protected static $defaultName = 'app:init-search-index';

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
     * @param string $name
     */
    public function __construct(ManagerRegistry $doctrine, ElasticsearchClient $elasticsearch, string $name = null)
    {
        parent::__construct($name);

        $this->doctrine = $doctrine;
        $this->elasticsearch = $elasticsearch;
    }

    protected function configure()
    {
        $this->setDescription('Initialize search index');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->initIndex();
        } catch (ElasticserchAcknowledgementException $e) {
            $io->error("Initialization failed");
            $io->note($e->getMessage());
            return;
       }

        // $response = $indices->getMapping();
        // $io->text(json_encode($response, JSON_PRETTY_PRINT));

        /////////////////////
        $this->switchToUnbufferedMode();

        $totalAmount = $this->getTotalAmount();
        $io->progressStart($totalAmount);
        $query = $this->queryAllBooks();
        foreach ($this->iterateBulkData($query, self::BATCH_SIZE) as $bulkData) {
            $this->elasticsearch->bulk([
                'body' => $bulkData,
            ]);
            $io->progressAdvance(count($bulkData) / 2);
            // single data element of bulk transport spends 2 lines
        }
        $io->progressFinish();
        $io->newLine();
        $io->success($totalAmount . ' records successfully indexed.');
    }

    /**
     * @throws ElasticserchAcknowledgementException
     */
    private function initIndex(): void
    {
        $indices = $this->elasticsearch->indices();

        if ($indices->exists(['index' => 'book'])) {
            $indices->delete(['index' => 'book']);
        }

        $response = $indices->create([
            'index' => 'book',
            // 'body' => [], // Set shard numbers etc if clustering available
        ]);
        if (($response['acknowledged'] ?? 0) != 1) {
            throw new ElasticserchAcknowledgementException($response);
        }

        $response = $indices->putMapping([
            'index' => 'book',
            'body' => [
                'properties' => [
                    'title' => [
                        'type' => 'keyword',
                    ],
                    'contents' => [
                        'type' => 'text',
                        // Note: text type has no field data to sort/calc as default.
                    ],
                ],
            ],
        ]);
        if (($response['acknowledged'] ?? 0) != 1) {
            throw new ElasticserchAcknowledgementException($response);
        }
    }

    protected function switchToUnbufferedMode(): void
    {
        $connection = $this->getEntityManager()
            ->getConnection()
            ->getWrappedConnection();
        assert($connection instanceof PDOConnection);
        $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }

    protected function getTotalAmount()
    {
        try {
            return $this->getEntityManager()->createQueryBuilder()
                ->from(Book::class, 'book')
                ->select('count(book.id)')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            throw new UnexpectedValueException();
        }
    }

    protected function queryAllBooks(): Query
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->from(Book::class, 'book')
            ->select('book')
            ->getQuery();
    }

    private function iterateBulkData(Query $query, int $batchSize)
    {
        $bufferedCount = 0;
        foreach ($query->iterate() as $books) {
            foreach ($books as $book) {
                assert($book instanceof Book);
                $this->getEntityManager()->detach($book);

                $bulkData[] = [
                    'index' => [
                        '_index' => 'book',
                        '_id' => $book->getId(),
                    ],
                ];
                $bulkData[] = [
                    'title' => $book->getTitle(),
                    'contents' => $book->getContents(),
                ];

                $bufferedCount += 1;
                if ($bufferedCount >= $batchSize) {
                    yield $bulkData;
                    $bulkData = [];
                    $bufferedCount = 0;
                }
            }
        }
        if (!empty($bulkData)) {
            yield $bulkData;
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);
        return $entityManager;
    }
}
