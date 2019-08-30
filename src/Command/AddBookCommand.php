<?php

namespace App\Command;

use App\Command\Exception\ElasticserchAcknowledgementException;
use App\Entity\Book;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Elasticsearch\Client as ElasticsearchClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AddBookCommand extends Command
{
    protected static $defaultName = 'app:add-book';

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
        $this->setDescription('Add a new book')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title')
            ->addOption('contents', null, InputOption::VALUE_REQUIRED, 'Contents')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        $title = $input->getOption('title');
        if (empty($title)) {
            $title = $io->ask("Title");
        }

        $contents = $input->getOption('contents');
        if (empty($contents)) {
            $contents = $io->ask("Contents");
        }

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $book = (new Book())
                ->setTitle($title)
                ->setContents($contents)
            ;
            $entityManager->persist($book);
            $entityManager->flush();

            $response = $this->elasticsearch->index([
                'index' => 'book',
                'id' => $book->getId(),
                'body' => [
                    'title' => $book->getTitle(),
                    'contents' => $book->getContents(),
                ]
            ]);
            if (($response['result'] ?? null) != 'created') {
                throw new ElasticserchAcknowledgementException($response);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $connection->rollBack();
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $e;
        }

        $io->success('Successfully saved as id=' . $book->getId() . '.');
    }
}
