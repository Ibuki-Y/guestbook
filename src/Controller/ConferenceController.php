<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Conference;
use App\Form\CommentFormType;
use App\Message\CommentMessage;
use App\Repository\CommentRepository;
use App\Repository\ConferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class ConferenceController extends AbstractController
{
    private $twig;
    private $entityManager;
    private $bus;

    public function __construct(
        Environment $twig,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ) {
        $this->twig = $twig;
        $this->entityManager = $entityManager;
        $this->bus = $bus;
    }

    // /en/にリダイレクト
    #[Route('/')]
    public function indexNoLocale(): Response
    {
        return $this->redirectToRoute('homepage', ['_locale' => 'en']);
    }

    // URLを国際化
    #[Route('/{_locale<%app.supported_locales%>}/', name: 'homepage')]
    public function index(ConferenceRepository $conferenceRepository): Response
    {
        // 1時間(3600s)キャッシュ
        $response = new Response($this->twig->render('conference/index.html.twig', [
            // Conferenceオブジェクトのリストをconferences変数として渡す
            'conferences' => $conferenceRepository->findAll(),
        ]));
        // setSharedMaxAge(): リバースプロキシのキャッシュ有効期限
        $response->setSharedMaxAge(3600);

        return $response;
    }

    // カンファレンス情報のHTMLの一部のみを返すコントローラー
    #[Route('/{_locale<%app.supported_locales%>}/conference_header', name: 'conference_header')]
    public function conferenceHeader(ConferenceRepository $conferenceRepository): Response
    {
        $response = new Response($this->twig->render('conference/header.html.twig', [
            'conferences' => $conferenceRepository->findAll(),
        ]));
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /*
    コメントを一覧表示する専用のページ
    id: データベースのconferenceテーブルのプライマリーキー => slugに変更
    */
    #[Route('/{_locale<%app.supported_locales%>}/conference/{slug}', name: 'conference')]
    public function show(
        Request $request,
        Conference $conference,
        CommentRepository $commentRepository,
        NotifierInterface $notifier, // 通知をチャネルから受け手に送る
        string $photoDir,
    ): Response {
        $comment = new Comment();
        // フォームタイプを直接生成してはいけない => createForm()
        $form = $this->createForm(CommentFormType::class, $comment);
        // フォームを送信してコントローラーでデータベースに情報を永続化
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setConference($conference);
            /*
            アップロードされた写真をカンファレンスページで表示できるように，
            Webからアクセスできるローカルのディスクに保存
            */
            if ($photo = $form['photo']->getData()) {
                // ファイルにランダムな名前をつける
                $filename = bin2hex(random_bytes(6)).'.'.$photo->guessExtension();
                try {
                    $photo->move($photoDir, $filename);
                } catch (FileException $e) {
                    // unable to upload the photo, give up
                }
                $comment->setPhotoFilename($filename);
            }

            $this->entityManager->persist($comment);
            $this->entityManager->flush();

            $context = [
                'user_ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('user-agent'),
                'referrer' => $request->headers->get('referer'),
                'permalink' => $request->getUri(),
            ];

            // レビューURL
            $reviewUrl = $this->generateUrl(
                'review_comment',
                ['id' => $comment->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $this->bus->dispatch(new CommentMessage(
                $comment->getId(),
                $reviewUrl,
                $context
            ));

            /*
            コメント送信時にフィードバック通知
            通知は題名，オプショナル内容，重要度を持つ
            */
            $notifier->send(new Notification(
                'Thank you for the feedback; your comment will be posted after moderation.',
                ['browser']
            ));

            return $this->redirectToRoute('conference', ['slug' => $conference->getSlug()]);
        }

        // コメントが公開時に通知
        if ($form->isSubmitted()) {
            $notifier->send(new Notification(
                'Can you check your submission? There are some problems with it.',
                ['browser']
            ));
        }

        // リクエストのクエリー文字列($request->query)からoffsetを整数として(getInt())取得
        $offset = max(0, $request->query->getInt('offset', 0));
        $paginator = $commentRepository->getCommentPaginator($conference, $offset);

        return new Response($this->twig->render('conference/show.html.twig', [
            'conference' => $conference,
            'comments' => $paginator,
            'previous' => $offset - CommentRepository::PAGINATOR_PER_PAGE,
            'next' => min(count($paginator), $offset + CommentRepository::PAGINATOR_PER_PAGE),
            'comment_form' => $form->createView(),
        ]));
    }
}
