<?php
// ロジックを持たず，"シンプルな"シリアライズ可能なデータのみを格納

namespace App\Message;

class CommentMessage
{
    private $id;
    private $reviewUrl;
    private $context;

    public function __construct(int $id, string $reviewUrl, array $context = [])
    {
        $this->id = $id;
        $this->reviewUrl = $reviewUrl;
        $this->context = $context;
    }

    // コメント・メッセージの一部としてレビューURLを追加
    public function getReviewUrl(): string
    {
        return $this->reviewUrl;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
