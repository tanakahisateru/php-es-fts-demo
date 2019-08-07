<?php

namespace App\Controller;

use App\Entity\Book;
use App\Repository\BookRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class BookController extends AbstractController
{
    /**
     * @Route("/book", name="book")
     */
    public function index()
    {
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
            'books' => array_map(function (Book $book) {
                return [
                    'id' => $book->getId(),
                    'title' => $book->getTitle(),
                    'contents' => mb_strimwidth($book->getContents(), 0, 80, '...'),
                ];
            }, $books),
        ]);
    }
}
