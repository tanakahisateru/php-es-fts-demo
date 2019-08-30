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

        $docMapping = [
            'properties' => [
                'title' => [
                    'type' => 'keyword',
                    'fields' => [
                        "bigram" => ['type' => 'text', 'analyzer' => 'bigram_analyzer'],
                        "unigram" => ['type' => 'text', 'analyzer' => 'unigram_analyzer'],
                    ],
                ],
                // Note: text type has no field data to sort/calc by default.

                'contents' => [
                    'type' => 'text',
                    // 'index' => false,
                    // You can remove default index data to save disk space.
                    'fields' => [
                        "bigram" => ['type' => 'text', 'analyzer' => 'bigram_analyzer'],
                        "unigram" => ['type' => 'text', 'analyzer' => 'unigram_analyzer'],
                    ],
                ],
            ],
        ];

        $response = $indices->create([
            'index' => 'book',
            'body' => [
                'settings' => [
                    // 'number_of_shards' => 3,
                    // 'number_of_replicas' => 2,
                    // Set shard numbers etc if clustering available
                    'analysis' => [
                        'tokenizer' => [
                            'bigram_tokenizer' => ['type' => 'nGram', 'min_gram' => 2, 'max_gram' => 2],
                            'unigram_tokenizer' => ['type' => 'nGram', 'min_gram' => 1, 'max_gram' => 1],
                        ],
        
                        'analyzer' => [
                            'bigram_analyzer' => [
                                'type' => 'custom',
                                'tokenizer' => 'bigram_tokenizer',
                                'filter' => ['cjk_width', 'lowercase'],
                            ],
                            'unigram_analyzer' => [
                                'type' => 'custom',
                                'tokenizer' => 'unigram_tokenizer',
                                'filter' => ['cjk_width', 'lowercase'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        if (($response['acknowledged'] ?? 0) != 1) {
            throw new ElasticserchAcknowledgementException($response);
        }

        $response = $indices->putMapping([
            // The mapping definition cannot be nested under a type [_doc] unless include_type_name is set to true.
            'include_type_name' => true,
            'index' => 'book',
            'type' => '_doc',
            'body' => [
                '_doc' => $docMapping,
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
