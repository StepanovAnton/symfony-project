<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Conference;
use App\Form\CommentFormType;
use App\Repository\CommentRepository;
use App\Repository\ConferenceRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use http\Exception\RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ConferenceController extends AbstractController
{
    private $twig;
    private $entityManager;

    public function __construct(Environment $twig, EntityManagerInterface $entityManager)
    {
        $this->twig = $twig;
        $this->entityManager = $entityManager;
    }

    public function findAll()
    {
        return $this->findBy([], ['year' => 'ASC', 'city' => 'ASC']);
    }

    /**
     * @Route("/", name="homepage")
     * @param ConferenceRepository $conferenceRepository
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function index(ConferenceRepository $conferenceRepository)
    {
        return new Response($this->twig->render('conference/index.html.twig', [
            'conferences' => $conferenceRepository->findAll(),
        ]));
    }

    /**
     * @Route("/conference/{slug}", name="conference")
     *
     * @param Request $request
     * @param Conference $conference
     * @param CommentRepository $commentRepository
     * @param ConferenceRepository $conferenceRepository
     * @param SpamChecker $spamChecker
     * @param string $photoDir
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function show(
        Request $request,
        Conference $conference,
        CommentRepository $commentRepository,
        ConferenceRepository $conferenceRepository,
        SpamChecker $spamChecker,
        string $photoDir
    ) {
        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setConference($conference);
            if ($photo = $form['photo']->getData()) {
                $filename = bin2hex(random_bytes(6)) . '.' . $photo->guessExtension();
                try {
                    $photo->move($photoDir, $filename);
                } catch (FileException $e) {
                    // enable to upload the photo, give up
                }
                $comment->setPhotoFilename($filename);
            }

            $this->entityManager->persist($comment);

            $context = [
                'user_ip' => $request->getClientIp(),
                'user_agent' => $request->getClientIp(),
                'referrer' => $request->headers->get('referer'),
                'permalink' => $request->getUri(),
            ];

            if (2 === $spamChecker->getSpamScore($comment, $context)) {
                throw new \RuntimeException('Blatant spam, go away!');
            }
            $this->entityManager->flush();

            return $this->redirectToRoute('conference', ['slug' => $conference->getSlug()]);
        }

        $offset = max(0, $request->query->getInt('offset', 0));
        $paginator = $commentRepository->getCommentPaginator($conference, $offset);

        return new Response($this->twig->render('conference/show.html.twig', [
            'conferences' => $conferenceRepository->findAll(),
            'conference' => $conference,
            'comments' => $paginator,
            'previous' => $offset - CommentRepository::PAGINATOR_PER_PAGE,
            'next' => min(count($paginator), $offset + CommentRepository::PAGINATOR_PER_PAGE),
            'comment_form' => $form->createView(),
        ]));
    }
}
