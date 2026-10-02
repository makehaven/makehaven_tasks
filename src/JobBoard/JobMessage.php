<?php

namespace Drupal\makehaven_tasks\JobBoard;

/**
 * Formats the Slack text for a job board posting. Pure, so it is unit-tested.
 */
class JobMessage {

  /**
   * Longest description carried into Slack; the rest is behind the link.
   */
  const MAX_DESCRIPTION = 900;

  /**
   * Builds the #jobs post.
   *
   * @param array $o
   *   Keys: title, type, organization, description, pay, when, where,
   *   people_needed, how_to_apply, link, contact_name, contact_email,
   *   contact_phone, url. Missing or empty keys are left out.
   */
  public static function jobsPost(array $o): string {
    $lines = [];
    $heading = '*' . self::escape($o['title'] ?? 'New opportunity') . '*';
    if (!empty($o['type'])) {
      $heading .= ' · ' . self::escape($o['type']);
    }
    $lines[] = $heading;
    if (!empty($o['organization'])) {
      $lines[] = self::escape($o['organization']);
    }
    if (!empty($o['description'])) {
      $lines[] = '';
      $lines[] = self::escape(self::trim($o['description'], self::MAX_DESCRIPTION));
    }
    $facts = [];
    foreach (['pay' => 'Pay', 'when' => 'When', 'where' => 'Where', 'people_needed' => 'People needed'] as $key => $label) {
      if (isset($o[$key]) && trim((string) $o[$key]) !== '') {
        $facts[] = '*' . $label . ':* ' . self::escape((string) $o[$key]);
      }
    }
    if ($facts) {
      $lines[] = '';
      $lines = array_merge($lines, $facts);
    }
    if (!empty($o['how_to_apply'])) {
      $lines[] = '*How to apply:* ' . self::escape($o['how_to_apply']);
    }
    $contact = array_filter([
      $o['contact_name'] ?? '',
      $o['contact_email'] ?? '',
      $o['contact_phone'] ?? '',
    ], fn($v) => trim((string) $v) !== '');
    if ($contact) {
      $lines[] = '*Contact:* ' . self::escape(implode(' · ', $contact));
    }
    if (!empty($o['link'])) {
      $lines[] = '*Link:* ' . self::link((string) $o['link']);
    }
    if (!empty($o['url'])) {
      $lines[] = '<' . $o['url'] . '|Full posting on the website>';
    }
    $lines[] = '';
    $lines[] = '_Shared by MakeHaven for an outside requester. Not vetted or guaranteed: any agreement is between you and them. If you take it on, reply in this thread so others know._';
    return implode("\n", $lines);
  }

  /**
   * Builds the short pointer posted in a trade channel.
   */
  public static function tradePointer(array $o, string $permalink): string {
    $text = 'New opportunity in #jobs: *' . self::escape($o['title'] ?? '') . '*';
    if (!empty($o['pay'])) {
      $text .= ' (' . self::escape((string) $o['pay']) . ')';
    }
    return $text . "\n<" . $permalink . '|Read it in #jobs>';
  }

  /**
   * Builds the staff alert for a submission waiting for review.
   *
   * @param array $o
   *   The posting, as for jobsPost().
   * @param string $queueUrl
   *   Absolute URL of the review queue.
   * @param string $groupId
   *   A Slack user group ID (S…) to mention, e.g. @staff; empty for none.
   */
  public static function reviewAlert(array $o, string $queueUrl, string $groupId = ''): string {
    $text = $groupId !== '' ? '<!subteam^' . $groupId . '> ' : '';
    $text .= 'New job board request waiting for review: *' . self::escape($o['title'] ?? '') . '*';
    if (!empty($o['organization'])) {
      $text .= ' from ' . self::escape($o['organization']);
    }
    return $text . "\n<" . $queueUrl . '|Approve or decline>';
  }

  /**
   * Whether a requester email is on the trusted list.
   *
   * @param string $email
   *   The requester's address.
   * @param string[] $trusted
   *   Exact addresses, or '@domain.org' to trust a whole domain.
   */
  public static function isTrusted(string $email, array $trusted): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
      return FALSE;
    }
    $domain = substr($email, strrpos($email, '@'));
    foreach ($trusted as $entry) {
      $entry = strtolower(trim((string) $entry));
      if ($entry === '') {
        continue;
      }
      if ($entry === $email || ($entry[0] === '@' && $entry === $domain)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Formats a requester-supplied URL as a Slack link they cannot relabel.
   *
   * Only http(s) is linked. '|' would start a custom label and '<' '>' would
   * end or open a link, so they are percent-encoded (SEC-035).
   */
  public static function link(string $url): string {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
      return self::escape($url);
    }
    $url = str_replace(['|', '<', '>', ' '], ['%7C', '%3C', '%3E', '%20'], $url);
    return '<' . str_replace('&', '&amp;', $url) . '>';
  }

  /**
   * Escapes the three characters Slack mrkdwn treats as control characters.
   */
  public static function escape(string $text): string {
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
  }

  /**
   * Shortens text on a word boundary.
   */
  public static function trim(string $text, int $max): string {
    $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
    if (mb_strlen($text) <= $max) {
      return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
  }

}
