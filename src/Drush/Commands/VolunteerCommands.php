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


  /**
   * Copy the old outreach volunteer webform's answers into How I like to help.
   *
   * Only submissions by a logged-in member can be copied (the answers live on
   * their account). Anonymous ones are listed for staff to contact by hand.
   * Nobody is opted in to emails: they never asked for them.
   */
  #[CLI\Command(name: 'makehaven_tasks:import-outreach-signups', aliases: ['mh-import-outreach'])]
  #[CLI\Option(name: 'apply', description: 'Write the answers (default is a dry run).')]
  #[CLI\Usage(name: 'drush makehaven_tasks:import-outreach-signups', description: 'Preview what would be copied.')]
  public function importOutreach(array $options = ['apply' => FALSE]): int {
    $apply = (bool) $options['apply'];
    $etm = \Drupal::entityTypeManager();
    if (!$etm->hasDefinition('webform_submission')) {
      $this->io()->writeln('Webform is not installed.');
      return self::EXIT_SUCCESS;
    }
    /** @var \Drupal\makehaven_tasks\Volunteer\Preferences $prefs */
    $prefs = \Drupal::service('makehaven_tasks.volunteer_preferences');
    $map = \Drupal\makehaven_tasks\Volunteer\Preferences::legacyWayMap();
    $submissions = $etm->getStorage('webform_submission')->loadByProperties(['webform_id' => 'sign_up_to_be_a_makehaven_outrea']);
    foreach ($submissions as $submission) {
      $data = $submission->getData();
      $uid = (int) $submission->getOwnerId();
      $chosen = (array) ($data['how_would_you_like_to_help'] ?? []);
      $ways = [];
      $other = [];
      foreach ($chosen as $value) {
        if (isset($map[$value])) {
          $ways[] = $map[$value];
        }
        elseif (trim((string) $value) !== '') {
          $other[] = trim((string) $value);
        }
      }
      $availability = array_map('strtolower', (array) ($data['what_is_your_general_availability'] ?? []));
      $notes = array_filter([
        implode('; ', $other),
        $data['what_groups_are_you_connected_to_that_you_could_share_makehaven'] ?? '',
        $data['what_businesses_or_organizations_could_you_approach_on_behalf_of'] ?? '',
        $data['do_you_have_specific_sponsors_or_grant_makers_in_mind'] ?? '',
        ($data['what_topic_or_interest_would_you_like_to_host_a_meetup_around'] ?? '') !== '' ? 'Meetup: ' . $data['what_topic_or_interest_would_you_like_to_host_a_meetup_around'] : '',
        ($data['what_would_you_do_to_help_get_media_coverage'] ?? '') !== '' ? 'Media: ' . $data['what_would_you_do_to_help_get_media_coverage'] : '',
      ], fn($t) => trim((string) $t) !== '');
      $email = (string) ($data['email'] ?? '');
      if (!$uid && $email !== '') {
        $match = $etm->getStorage('user')->loadByProperties(['mail' => $email]);
        $uid = $match ? (int) array_key_first($match) : 0;
      }
      $label = sprintf('#%d %s (%s): %s', $submission->id(), date('Y-m-d', (int) $submission->getCreatedTime()), $email ?: 'no email', implode(', ', array_unique($ways)) ?: implode('; ', $other));
      if (!$uid) {
        $this->io()->writeln('[contact by hand, no account] ' . $label);
        continue;
      }
      $current = $prefs->get($uid);
      if ($current['updated']) {
        $this->io()->writeln("[skip, uid $uid already answered] " . $label);
        continue;
      }
      $this->io()->writeln(($apply ? '[copied]' : '[would copy]') . " uid $uid " . $label);
      if ($apply) {
        $prefs->set($uid, [
          'ways' => array_unique($ways),
          'availability' => $availability,
          'connections' => implode("\n", array_map('trim', $notes)),
          'notify' => FALSE,
        ]);
      }
    }
    return self::EXIT_SUCCESS;
  }

}
