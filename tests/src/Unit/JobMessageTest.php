<?php

namespace Drupal\Tests\makehaven_tasks\Unit;

use Drupal\makehaven_tasks\JobBoard\JobMessage;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\makehaven_tasks\JobBoard\JobMessage
 * @group makehaven_tasks
 */
class JobMessageTest extends TestCase {

  /**
   * The #jobs post carries the facts members decide on, and the disclaimer.
   */
  public function testJobsPostIncludesFactsContactAndDisclaimer(): void {
    $text = JobMessage::jobsPost([
      'title' => 'Festival crew',
      'type' => 'Part-time',
      'organization' => 'Town Green District',
      'description' => 'Setup & breakdown <tents>',
      'pay' => '$20/hour',
      'when' => 'Oct 18, 8am-6pm',
      'people_needed' => '6',
      'contact_name' => 'Pat',
      'contact_email' => 'pat@example.org',
      'url' => 'https://www.makehaven.org/job/festival-crew',
    ]);
    $this->assertStringStartsWith('*Festival crew* · Part-time', $text);
    $this->assertStringContainsString('Setup &amp; breakdown &lt;tents&gt;', $text);
    $this->assertStringContainsString('*Pay:* $20/hour', $text);
    $this->assertStringContainsString('*People needed:* 6', $text);
    $this->assertStringContainsString('*Contact:* Pat · pat@example.org', $text);
    $this->assertStringContainsString('<https://www.makehaven.org/job/festival-crew|Full posting on the website>', $text);
    $this->assertStringContainsString('Not vetted or guaranteed', $text);
    $this->assertStringNotContainsString('*Where:*', $text);
  }

  /**
   * Long descriptions are cut on a word with an ellipsis.
   */
  public function testLongDescriptionIsTrimmed(): void {
    $text = JobMessage::jobsPost(['title' => 'X', 'description' => str_repeat('word ', 400)]);
    $this->assertStringContainsString('word…', $text);
    $this->assertLessThan(1300, mb_strlen($text));
  }

  /**
   * Trusted senders match exact addresses and whole domains, nothing looser.
   */
  public function testIsTrusted(): void {
    $trusted = ['Events@TownGreen.org', '@newhavenarts.org', ''];
    $this->assertTrue(JobMessage::isTrusted('events@towngreen.org', $trusted));
    $this->assertTrue(JobMessage::isTrusted('anyone@NewHavenArts.org', $trusted));
    $this->assertFalse(JobMessage::isTrusted('other@towngreen.org', $trusted));
    $this->assertFalse(JobMessage::isTrusted('x@evilnewhavenarts.org', $trusted));
    $this->assertFalse(JobMessage::isTrusted('x@newhavenarts.org.evil.com', $trusted));
    $this->assertFalse(JobMessage::isTrusted('', $trusted));
    $this->assertFalse(JobMessage::isTrusted('anyone@x.org', []));
  }

  /**
   * The trade-channel pointer links back to the #jobs message.
   */
  public function testTradePointer(): void {
    $text = JobMessage::tradePointer(['title' => 'Weld a railing', 'pay' => '$400'], 'https://makehaven.slack.com/archives/C3BSZ8DDJ/p1');
    $this->assertSame("New opportunity in #jobs: *Weld a railing* (\$400)\n<https://makehaven.slack.com/archives/C3BSZ8DDJ/p1|Read it in #jobs>", $text);
  }

  /**
   * The staff alert mentions the user group only when one is configured.
   */
  public function testReviewAlert(): void {
    $o = ['title' => 'Festival crew', 'organization' => 'Town Green'];
    $this->assertSame("<!subteam^S014AFFKAF4> New job board request waiting for review: *Festival crew* from Town Green\n<https://x/q|Approve or decline>", JobMessage::reviewAlert($o, 'https://x/q', 'S014AFFKAF4'));
    $this->assertStringStartsWith('New job board request', JobMessage::reviewAlert($o, 'https://x/q'));
  }

  /**
   * A requester's link cannot carry its own Slack label or a second link.
   */
  public function testLinkCannotBeRelabelled(): void {
    $this->assertSame('<https://evil.example/?a=1%7CClick%20for%20prize%3E>', JobMessage::link('https://evil.example/?a=1|Click for prize>'));
    $this->assertSame('<https://x.org/?a=1&amp;b=2>', JobMessage::link('https://x.org/?a=1&b=2'));
    $this->assertSame('javascript:alert(1)', JobMessage::link('javascript:alert(1)'));
    $text = JobMessage::jobsPost(['title' => 'X', 'link' => 'https://a.org/|label']);
    $this->assertStringContainsString('*Link:* <https://a.org/%7Clabel>', $text);
  }

}
