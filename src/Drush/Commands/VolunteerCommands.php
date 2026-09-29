<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Drush\Commands;

use Drupal\makehaven_tasks\Volunteer\Decider;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Volunteer board rules from the command line.
 *
 * Lives under src/Drush/Commands with attribute discovery: the only layout
 * Drush 13 supports.
 */
class VolunteerCommands extends DrushCommands {

  public function __construct(protected Decider $decider) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('makehaven_tasks.volunteer_decider'));
  }

  /**
   * Run the decide-by and stale-claim rules (what cron runs).
   */
  #[CLI\Command(name: 'makehaven_tasks:volunteer-tick', aliases: ['mh-volunteer-tick'])]
  #[CLI\Option(name: 'dry-run', description: 'List what would happen without sending or saving anything.')]
  #[CLI\Usage(name: 'drush makehaven_tasks:volunteer-tick --dry-run', description: 'Preview declines, ready-to-approve emails and stale-claim nudges.')]
  public function tick(array $options = ['dry-run' => FALSE]): int {
    $dry = (bool) $options['dry-run'];
    $lines = $this->decider->tick(NULL, $dry);
    $this->io()->writeln(($dry ? '[dry run] ' : '') . count($lines) . ' action(s).');
    foreach ($lines as $line) {
      $this->io()->writeln(' - ' . $line);
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * List open claims: post-launch (nudged) and pre-launch (staff cleanup).
   */
  #[CLI\Command(name: 'makehaven_tasks:stale-claims', aliases: ['mh-stale-claims'])]
  public function staleClaims(): int {
    $claims = $this->decider->staleClaims();
    foreach (['post_launch' => 'Claimed since launch (nudged at 14/28 idle days)', 'pre_launch' => 'Claimed before launch (never emailed; staff cleanup list)'] as $key => $heading) {
      $this->io()->writeln($heading . ': ' . count($claims[$key]));
      foreach ($claims[$key] as $row) {
        $this->io()->writeln(sprintf('  #%d %s - %s - %d days idle', $row['nid'], $row['node']->label(), $row['lead'] ? $row['lead']->getDisplayName() . ' (uid ' . $row['lead']->id() . ')' : 'no lead', $row['idle_days']));
      }
    }
    return self::EXIT_SUCCESS;
  }

}
