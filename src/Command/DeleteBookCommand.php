<?php

namespace App\Command;

use App\Command\Exception\ElasticserchAcknowledgementException;
use App\Entity\Book;
use App\Repository\BookRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Elasticsearch\Client as ElasticsearchClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeleteBookCommand extends Command
{
    protected static $defaultName = 'app:delete-book';

    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var ElasticsearchClient
     */
    private $elasticsearch;

    public function __construct(ManagerRegistry $doctrine, ElasticsearchClient $elasticsearch, string $name = null)
    {
        parent::__construct($name);

        $this->doctrine = $doctrine;
        $this->elasticsearch = $elasticsearch;
    }

    protected function configure()
    {
        $this->setDescription('Delete a new book')
            ->addArgument('id', InputOption::VALUE_REQUIRED, 'ID')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        $id = $input->getArgument('id');

        $connection = $entityManager->getConnection();
        $bookRepository = $entityManager->getRepository(Book::class);
        assert($bookRepository instanceof BookRepository);

        $book = $bookRepository->find($id);
        if (!$book) {
            $io->warning("No such book found.");
            return 1;
        }

        $connection->beginTransaction();
        try {
            $removedBookId = $book->getId();

            $entityManager->remove($book);
            $entityManager->flush();

            $response = $this->elasticsearch->delete([
                'index' => 'book',
                'id' => $removedBookId,
            ]);
            if (($response['result'] ?? null) != 'deleted') {
                throw new ElasticserchAcknowledgementException($response);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $connection->rollBack();
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $e;
        }

        $io->success('Successfully deleted id=' . $removedBookId . '.');

        return 0;
    }
}
