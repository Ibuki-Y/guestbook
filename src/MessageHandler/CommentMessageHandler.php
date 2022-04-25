<?php
/*
CommentMessageメッセージを処理する
マーカーインターフェース
画像最適化
*/

namespace App\MessageHandler;

use App\ImageOptimizer;
use App\Message\CommentMessage;
use App\Notification\CommentReviewNotification;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class CommentMessageHandler implements MessageHandlerInterface
{
    private $spamChecker;
    private $entityManager;
    private $commentRepository;
    private $bus;
    private $workflow;
    private $notifier;
    private $imageOptimizer;
    private $photoDir;
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        SpamChecker $spamChecker,
        CommentRepository $commentRepository,
        MessageBusInterface $bus,
        WorkflowInterface $commentStateMachine,
        NotifierInterface $notifier,
        ImageOptimizer $imageOptimizer,
        string $photoDir,
        LoggerInterface $logger = null
    ) {
        $this->entityManager = $entityManager;
        $this->spamChecker = $spamChecker;
        $this->commentRepository = $commentRepository;
        $this->bus = $bus;
        $this->workflow = $commentStateMachine;
        $this->notifier = $notifier;
        $this->imageOptimizer = $imageOptimizer;
        $this->photoDir = $photoDir;
        $this->logger = $logger;
    }

    // ハンドラーのロジック
    public function __invoke(CommentMessage $message)
    {
        $comment = $this->commentRepository->find($message->getId());
        if (!$comment) {
            return;
        }

        /*
        <ワークフロー>
        メッセージ内のコメントにおいてaccept遷移が可能であればスパムチェック
        */
        if ($this->workflow->can($comment, 'accept')) {
            $score = $this->spamChecker->getSpamScore($comment, $message->getContext());
            $transition = 'accept';
            if (2 === $score) {
                $transition = 'reject_spam'; // 2: スパム
            } elseif (1 === $score) {
                $transition = 'might_be_spam'; // 1: スパムの可能性
            }
            $this->workflow->apply($comment, $transition); //コメント更新
            $this->entityManager->flush(); // データベースに変更をコミット

            // ワークフローの再遷移を許容させるためメッセージを再ディスパッチ
            $this->bus->dispatch($message);
        } elseif ($this->workflow->can($comment, 'publish') || $this->workflow->can($comment, 'publish_ham')) {
            /*
            メッセージハンドラー内で通知
            レビューURLを渡す
            getAdminRecipients(): 通知(notifier)設定にある管理者の受信者設定を返す
            */
            $notification = new CommentReviewNotification($comment, $message->getReviewUrl());
            $this->notifier->send($notification, ...$this->notifier->getAdminRecipients());
        } elseif ($this->workflow->can($comment, 'optimize')) {
            // 画像最適化
            if ($comment->getPhotoFilename()) {
                $this->imageOptimizer->resize($this->photoDir.'/'.$comment->getPhotoFilename());
            }
            $this->workflow->apply($comment, 'optimize');
            $this->entityManager->flush();
        } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
        }
    }
}
