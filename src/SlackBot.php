<?php

namespace Drupal\makehaven_tasks;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Posts to Slack as the "MakeHaven Member Sync" bot (volunteer board and job board).
 *
 * Uses slack_member_sync's bot token rather than slack_connector's incoming
 * webhook: a modern webhook is locked to one channel, so it cannot reach #jobs
 * and the trade channels. Unlike SlackMemberSyncApiClient::postMessage() this
 * returns the message ts (needed to thread status replies later) and joins a
 * public channel the bot is not yet in, then retries once.
 */
class SlackBot {

  /**
   * State key for the cached channel name => ID map.
   */
  const CHANNEL_MAP_KEY = 'makehaven_tasks.slack_channel_ids';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected StateInterface $state,
  ) {}

  /**
   * Posts mrkdwn text to a channel.
   *
   * @param string $channel
   *   A channel ID (C…), a public channel name with or without '#', or a
   *   user ID (U…) to send the bot's direct message.
   * @param string $text
   *   Slack mrkdwn.
   *
   * @return string
   *   The message ts.
   *
   * @throws \RuntimeException
   *   With Slack's error code when the post fails.
   */
  public function post(string $channel, string $text): string {
    $channelId = $this->resolveChannelId($channel);
    $payload = [
      'channel' => $channelId,
      'text' => $text,
      'unfurl_links' => FALSE,
      'unfurl_media' => FALSE,
    ];
    $data = $this->call('chat.postMessage', $payload);
    if (empty($data['ok']) && ($data['error'] ?? '') === 'not_in_channel') {
      $join = $this->call('conversations.join', ['channel' => $channelId]);
      if (empty($join['ok'])) {
        throw new \RuntimeException('conversations.join failed: ' . ($join['error'] ?? 'unknown'));
      }
      $data = $this->call('chat.postMessage', $payload);
    }
    if (empty($data['ok']) || empty($data['ts'])) {
      throw new \RuntimeException('chat.postMessage failed: ' . ($data['error'] ?? 'unknown'));
    }
    return (string) $data['ts'];
  }

  /**
   * Builds the browser link to a posted message.
   */
  public function permalink(string $channelId, string $ts): string {
    $workspace = rtrim((string) ($this->configFactory->get('slack_member_sync.settings')->get('workspace_url') ?: 'https://makehaven.slack.com/'), '/');
    return $workspace . '/archives/' . $channelId . '/p' . str_replace('.', '', $ts);
  }

  /**
   * Turns '#metal' or 'metal' into a channel ID; passes channel and user IDs through.
   */
  public function resolveChannelId(string $channel): string {
    $channel = trim($channel);
    if (preg_match('/^[CGDUW][A-Z0-9]{6,}$/', $channel)) {
      return $channel;
    }
    $name = strtolower(ltrim($channel, '#'));
    $map = $this->state->get(self::CHANNEL_MAP_KEY, ['fetched' => 0, 'ids' => []]);
    if (!isset($map['ids'][$name]) && $map['fetched'] < time() - 3600) {
      $map = ['fetched' => time(), 'ids' => $this->listPublicChannels()];
      $this->state->set(self::CHANNEL_MAP_KEY, $map);
    }
    if (!isset($map['ids'][$name])) {
      throw new \RuntimeException('channel_not_found: #' . $name);
    }
    return $map['ids'][$name];
  }

  /**
   * Fetches every public, unarchived channel as name => ID.
   */
  protected function listPublicChannels(): array {
    $ids = [];
    $cursor = '';
    $pages = 0;
    do {
      $query = ['types' => 'public_channel', 'exclude_archived' => 'true', 'limit' => 1000];
      if ($cursor !== '') {
        $query['cursor'] = $cursor;
      }
      $data = $this->call('conversations.list', $query, 'GET');
      if (empty($data['ok'])) {
        throw new \RuntimeException('conversations.list failed: ' . ($data['error'] ?? 'unknown'));
      }
      foreach ($data['channels'] ?? [] as $c) {
        $ids[strtolower((string) $c['name'])] = (string) $c['id'];
      }
      $cursor = (string) ($data['response_metadata']['next_cursor'] ?? '');
    } while ($cursor !== '' && ++$pages < 20);
    return $ids;
  }

  /**
   * Calls one Slack Web API method and returns the decoded body.
   */
  protected function call(string $method, array $params, string $verb = 'POST'): array {
    $token = trim((string) $this->configFactory->get('slack_member_sync.settings')->get('bot_token'));
    if ($token === '') {
      // Non-live environments blank the token in settings.php on purpose.
      throw new \RuntimeException('no_token');
    }
    $options = [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      'timeout' => 10,
    ];
    if ($verb === 'GET') {
      $options['query'] = $params;
    }
    else {
      $options['json'] = $params;
    }
    $response = $this->httpClient->request($verb, 'https://slack.com/api/' . $method, $options);
    return json_decode((string) $response->getBody(), TRUE) ?: [];
  }

}
