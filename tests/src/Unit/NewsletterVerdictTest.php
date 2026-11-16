<?php

declare(strict_types=1);

namespace Drupal\Tests\makehaven_tasks\Unit;

use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\Tests\UnitTestCase;

/**
 * Which tasks the newsletter's "Lend a hand" list carries, and why not.
 *
 * @group makehaven_tasks
 */
final class NewsletterVerdictTest extends UnitTestCase {

  private const NOW = 1_800_000_000;

  private function verdict(array $facts): array {
    return Opportunity::newsletterVerdict($facts + [
      'published' => TRUE,
      'stage' => '',
      'dated' => FALSE,
      'ended' => FALSE,
      'full' => FALSE,
      'enough' => FALSE,
      'decide_by' => NULL,
      'now' => self::NOW,
    ]);
  }

  public function testPlainTaskIsNotListedAndSaysHowToListIt(): void {
    $v = $this->verdict([]);
    $this->assertFalse($v['listed']);
    $this->assertStringContainsString('a task people can simply claim', $v['reason']);
  }

  public function testGatheringTaskIsListed(): void {
    $this->assertTrue($this->verdict(['stage' => 'gathering', 'decide_by' => self::NOW + 86400])['listed']);
  }

  public function testGatheringWithItsPeopleStaysListedUntilTheDecideByDate(): void {
    $v = $this->verdict(['stage' => 'gathering', 'enough' => TRUE, 'decide_by' => self::NOW + 86400]);
    $this->assertTrue($v['listed']);
  }

  public function testGatheringWithItsPeopleAndPastTheDateDropsOut(): void {
    // The bathroom-mural task: minimum of 4 met, no maximum, decide-by passed.
    $v = $this->verdict(['stage' => 'gathering', 'enough' => TRUE, 'decide_by' => self::NOW - 11 * 86400]);
    $this->assertFalse($v['listed']);
    $this->assertStringContainsString('Approve it', $v['reason']);
  }

  public function testGatheringStillShortPastTheDateStaysListed(): void {
    $this->assertTrue($this->verdict(['stage' => 'gathering', 'enough' => FALSE, 'decide_by' => self::NOW - 86400])['listed']);
  }

  public function testFullGatheringTaskDropsOut(): void {
    $this->assertFalse($this->verdict(['stage' => 'gathering', 'full' => TRUE, 'enough' => TRUE])['listed']);
  }

  public function testUpcomingShiftIsListedWithOrWithoutAStage(): void {
    $this->assertTrue($this->verdict(['dated' => TRUE])['listed']);
    $this->assertTrue($this->verdict(['dated' => TRUE, 'stage' => 'approved'])['listed']);
  }

  public function testPastOrFullShiftDropsOut(): void {
    $this->assertFalse($this->verdict(['dated' => TRUE, 'ended' => TRUE])['listed']);
    $this->assertFalse($this->verdict(['dated' => TRUE, 'full' => TRUE])['listed']);
  }

  public function testApprovedPlainTaskIsNotListed(): void {
    $this->assertFalse($this->verdict(['stage' => 'approved'])['listed']);
  }

  public function testDeclinedAndUnpublishedAreNotListed(): void {
    $this->assertFalse($this->verdict(['stage' => 'declined'])['listed']);
    $v = $this->verdict(['stage' => 'gathering', 'published' => FALSE]);
    $this->assertFalse($v['listed']);
    $this->assertStringContainsString('not published', $v['reason']);
  }

}
