<?php

namespace Drupal\Tests\makehaven_tasks\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makehaven_tasks\SlackBot;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\makehaven_tasks\SlackBot
 * @group makehaven_tasks
 */
class SlackBotTest extends TestCase {

  /**
   * Requests the poster sent, in order.
   */
  protected array $sent = [];

  /**
   * Builds a poster whose HTTP client answers with the given JSON bodies.
   */
  protected function poster(array $bodies, string $token = 'xoxb-test', array $stateMap = ['fetched' => 0, 'ids' => []]): SlackBot {
    $mock = new MockHandler(array_map(fn($b) => new Response(200, [], json_encode($b)), $bodies));
    $stack = HandlerStack::create($mock);
    $this->sent = [];
    $stack->push(Middleware::history($this->sent));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['bot_token', $token],
      ['workspace_url', 'https://makehaven.slack.com/'],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn($stateMap);

    return new SlackBot($factory, new Client(['handler' => $stack]), $this->createMock(LoggerChannelFactoryInterface::class), $state);
  }

  /**
   * The bot is not in #jobs today: it must join, then post, and return the ts.
   */
  public function testJoinsChannelAndRetriesWhenNotInChannel(): void {
    $poster = $this->poster([
      ['ok' => FALSE, 'error' => 'not_in_channel'],
      ['ok' => TRUE],
      ['ok' => TRUE, 'ts' => '1759340000.123456'],
    ]);
    $this->assertSame('1759340000.123456', $poster->post('C3BSZ8DDJ', 'hi'));
    $methods = array_map(fn($t) => basename($t['request']->getUri()->getPath()), $this->sent);
    $this->assertSame(['chat.postMessage', 'conversations.join', 'chat.postMessage'], $methods);
  }

  /**
   * Any other Slack error surfaces, so the queue can say "not in Slack".
   */
  public function testOtherErrorsThrow(): void {
    $this->expectExceptionMessage('chat.postMessage failed: channel_not_found');
    $this->poster([['ok' => FALSE, 'error' => 'channel_not_found']])->post('C3BSZ8DDJ', 'hi');
  }

  /**
   * Non-live sites blank the token: nothing is sent at all.
   */
  public function testNoTokenSendsNothing(): void {
    $poster = $this->poster([], '');
    try {
      $poster->post('C3BSZ8DDJ', 'hi');
      $this->fail('Expected an exception.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('no_token', $e->getMessage());
    }
    $this->assertSame([], $this->sent);
  }

  /**
   * Trade channels are stored by name and resolved through the cached map.
   */
  public function testResolvesChannelNamesFromCachedMap(): void {
    $poster = $this->poster([['ok' => TRUE, 'ts' => '1.2']], 'xoxb-test', ['fetched' => time(), 'ids' => ['metal' => 'C0METAL']]);
    $poster->post('#metal', 'hi');
    $this->assertSame('C0METAL', json_decode((string) $this->sent[0]['request']->getBody(), TRUE)['channel']);
    $this->assertSame('https://makehaven.slack.com/archives/C0METAL/p1759340000123456', $poster->permalink('C0METAL', '1759340000.123456'));
  }

  /**
   * A user ID passes straight through: that is how reviewer DMs are sent.
   */
  public function testUserIdIsADirectMessage(): void {
    $poster = $this->poster([['ok' => TRUE, 'ts' => '1.2']]);
    $poster->post('U02DJKD4F', 'hi');
    $this->assertSame('U02DJKD4F', json_decode((string) $this->sent[0]['request']->getBody(), TRUE)['channel']);
  }

}
