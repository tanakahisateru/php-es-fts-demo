<?php

namespace App\Command;

use App\Entity\Book;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory as FackerFactory;
use Faker\ORM\Doctrine\Populator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateFakeDataCommand extends Command
{
    const BATCH_SIZE = 100;

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

        $entityManager = $this->doctrine->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        $entityManager->createQueryBuilder()
            ->delete()
            ->from(Book::class, 'book')
            ->getQuery()
            ->execute();

        $faker = FackerFactory::create();

        $remains = $amount;
        $io->progressStart($amount);
        while ($remains > 0) {
            $size = min($remains, self::BATCH_SIZE);

            // FIXME EntityManager 使うと遅いので DBAL でバルクインサートにしたい
            $populator = new Populator($faker, $entityManager);
            $populator->addEntity(Book::class, $size, [
                'title' => function () use ($faker) {
                    return strtoupper(rtrim($faker->sentence(mt_rand(2, 4)), '.'));
                },
                'contents' => function () use ($faker) {
                    return implode("\n", $faker->paragraphs(mt_rand(5, 10)));
                }
            ]);
            $populator->execute();

            $io->progressAdvance($size);

            $entityManager->clear();
            $remains -= $size;
        }
        $io->progressFinish();
        $io->newLine();
        $io->success($amount . 'records successfully created.');
    }
}
