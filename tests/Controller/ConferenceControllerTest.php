<?php
// HTTPリクエストをコンテキスト内で実行する必要がある

namespace App\Tests\Controller;

use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
/* 機能テストの便利な機能
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
*/
// 実際のブラウザとHTTPを使うことが可能
use Symfony\Component\Panther\PantherTestCase;

class ConferenceControllerTest extends PantherTestCase
{
    public function testIndex()
    {
        // ブラウザをシミュレート
        $client = static::createPantherClient(['external_base_uri' => $_SERVER['SYMFONY_PROJECT_DEFAULT_ROUTE_URL']]);
        $client->request('GET', '/');
        /*
        クライアントとサーバーの間の往復をしないので処理が速くなる
        各HTTPリクエストの後のサービスの状態を調べるテストが可能
        */
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Give your feedback');
    }

    // フォームの投稿をシミュレート
    public function testCommentSubmission()
    {
        $client = static::createClient();
        $client->request('GET', '/conference/amsterdam-2019');
        $client->submitForm('Submit', [
            'comment_form[author]' => 'Fabien',
            'comment_form[text]' => 'Some feedback from an automated functional test',
            'comment_form[email]' => $email = 'me@automat.ed',
            'comment_form[photo]' => dirname(__DIR__, 2).'/public/images/under-construction.gif',
            ]);
        $this->assertResponseRedirects();

        /* simulate comment validation
        self::getContainer()->get(): 全サービスを取得
        */
        $comment = self::getContainer()->get(CommentRepository::class)->findOneByEmail($email);
        $comment->setState('published');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->followRedirect();
        $this->assertSelectorExists('div:contains("There are 2 comments")');
    }

    // ホームページから特定のカンファレンスページをクリックするテスト
    public function testConferencePage()
    {
        // ホームページを開く
        $client = static::createClient();
        // ページ内の要素を探すのに便利なCrawlerインスタンスを返す
        $crawler = $client->request('GET', '/');

        // CSSセレクターを使って，ホームページにカンファレンスが2つ表示されているのを確認
        $this->assertCount(2, $crawler->filter('h4'));

        // "View"リンクをクリック(最初に見つけたリンクを選択)
        $client->clickLink('View');

        // ページタイトル，レスポンス，ページの<h2>が正しいページのものであるかアサート
        $this->assertPageTitleContains('Amsterdam');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Amsterdam 2019');
        // ページにコメントが1つあることをアサート
        $this->assertSelectorExists('div:contains("There are 1 comments")');
    }
}
