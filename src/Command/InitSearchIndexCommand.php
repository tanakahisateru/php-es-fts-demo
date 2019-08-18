<?php

namespace App\Command;

use App\Entity\Book;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Elasticsearch\Client;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

class InitSearchIndexCommand extends Command
{
    protected static $defaultName = 'app:init-search-index';

    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @param ManagerRegistry $doctrine
     * @param Client $client
     * @param string $name
     */
    public function __construct(ManagerRegistry $doctrine, Client $client, string $name = null)
    {
        parent::__construct($name);

        $this->doctrine = $doctrine;
        $this->client = $client;
    }


    protected function configure()
    {
        $this
            ->setDescription('Initialize search index')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $indices = $this->client->indices();

        if ($indices->exists(['index' => 'book'])) {
            $indices->delete(['index' => 'book']);
        }
        $response = $indices->create([
            'index' => 'book',
            // 'body' => [], // Set shard numbers etc if clustering available
        ]);
        if (($response['acknowledged'] ?? 0) != 1) {
            $io->error("Creation failed");
            $io->note(json_encode($response, JSON_PRETTY_PRINT));
            return;
        }

        $response = $indices->putMapping([
            'index' => 'book',
            'body' => [
                'properties' => [
                    'title' => [
                        'type' => 'keyword',
//                        'fielddata' => true,
                        // Note: text type has no field data to sort/calc as default.
                    ],
                    'contents' => [
                        'type' => 'text',
                    ],
                ],
            ],
        ]);
        if (($response['acknowledged'] ?? 0) != 1) {
            $io->error("Mapping failed");
            $io->note(json_encode($response, JSON_PRETTY_PRINT));
            return;
        }

        $io->success('Type mapped indices are successfully initialized.');

        $response = $indices->getMapping();
        $io->text(json_encode($response, JSON_PRETTY_PRINT));

        /////////////////////
        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager
            ->getConnection()
            ->getWrappedConnection();
        assert($connection instanceof PDOConnection);
        $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $amount = $entityManager->createQueryBuilder()
                ->from(Book::class, 'book')
                ->select('count(book.id)')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            throw new UnexpectedValueException();
        }

        $query = $entityManager->createQueryBuilder()
            ->from(Book::class, 'book')
            ->select('book')
            //->setMaxResults(100)
            ->getQuery();

        $io->progressStart($amount);
        $bulkData = [];
        $bufferedCount = 0;
        foreach ($query->iterate() as $books) {
            foreach ($books as $book) {
                assert($book instanceof Book);
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
                if ($bufferedCount >= 1000) {
                    $this->client->bulk([
                        'body' => $bulkData,
                    ]);
                    $bulkData = [];
                    $io->progressAdvance($bufferedCount);
                    $bufferedCount = 0;
                }
                $entityManager->detach($book);
            }
        }
        $io->progressFinish();
    }
}
