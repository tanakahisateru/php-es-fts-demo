<?php
namespace App\Controller;

use App\Entity\Book;
use App\Form\Model\BookSearchModel;
use App\Service\BookSearch\SearcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @property ValidatorInterface $validator
 */
trait SearchControllerTrait
{
    /**
     * @param Request $request
     * @param SearcherInterface $searcher
     * @return JsonResponse
     */
    protected function execute(Request $request, SearcherInterface $searcher): JsonResponse
    {
        $searchModel = $this->requestToSearchModel($request);

        $errors = $this->validator->validate($searchModel);

        if ($errors->count() > 0) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => (string)$errors,
            ], 400);
        }

        $result = $searcher->search((string)$searchModel->word, (int)$searchModel->page);

        return new JsonResponse([
            'took' => $result->took,
            'word' => $result->word,
            'total' => $result->total,
            'books' => $this->jsonifyBooks($result->entities),
        ], 200);
    }

    private function requestToSearchModel(Request $request): BookSearchModel
    {
        $model = new BookSearchModel();
        $model->word = $request->query->get('q', null);
        $model->page = $request->query->get('page', 1);

        return $model;
    }

    private function jsonifyBooks(array $books): array
    {
        return array_map(function (Book $book) {
            return [
                'id' => $book->getId(),
                'title' => $book->getTitle(),
                'contents' => mb_strimwidth($book->getContents(), 0, 80, '...'),
                // 'contents' => $book->getContents(),
            ];
        }, $books);
    }
}