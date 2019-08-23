<?php

namespace App\Command;

use App\Entity\Book;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateFakeDataCommand extends Command
{
    const BATCH_SIZE = 1000;

    protected static $defaultName = 'app:generate-fake-data';

    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine, string $name = null)
    {
        parent::__construct($name);

        $this->doctrine = $doctrine;
    }

    protected function configure()
    {
        $this
            ->setDescription('Inserts fake data')
            ->addArgument('amount', InputArgument::OPTIONAL, 'Amount of data', "100")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $amount = (int)$input->getArgument('amount');

        $this->clean();

        $faker = FakerFactory::create();

        $remainingCount = $amount;
        $io->progressStart($amount);
        while ($remainingCount > 0) {
            $batchAmount = min($remainingCount, self::BATCH_SIZE);
            $books = $this->createFakeObjects($faker, $batchAmount);
            $this->batchAppend($books);
            $io->progressAdvance($batchAmount);
            $remainingCount -= $batchAmount;
        }
        $io->progressFinish();
        $io->newLine();
        $io->success($amount . ' records successfully created.');
    }

    private function clean(): void
    {
        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        $entityManager->createQueryBuilder()
            ->delete()
            ->from(Book::class, 'book')
            ->getQuery()
            ->execute();
    }

    private function createFakeObjects(Generator $faker, int $amount): array
    {
        $books = [];
        for ($i = 0; $i < $amount; $i++) {
            $books[] = (new Book())
                ->setTitle(strtoupper(rtrim($faker->sentence(mt_rand(2, 4)), '.')))
                ->setContents(implode("\n", $faker->paragraphs(mt_rand(5, 10))));
        }
        return $books;
    }

    private function batchAppend(array $books)
    {
        $db = $this->doctrine->getConnection();
        assert($db instanceof Connection);

        $values = [];
        foreach ($books as $book) {
            assert($book instanceof Book);
            $values[] = sprintf(
                "(%s, %s)",
                $db->quote($book->getTitle()),
                $db->quote($book->getContents())
            );
        }

        $valuesBlock = implode(", ", $values);
        $db->exec("INSERT INTO book (title, contents) VALUES $valuesBlock ;");
    }
}
