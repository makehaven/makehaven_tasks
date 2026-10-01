<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Volunteer\Perks;
use Drupal\makehaven_tasks\Volunteer\Preferences;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff page: who said how they like to help, and how much they have done.
 *
 * The ask list for outreach that is never a shift (press, sponsors, flyers,
 * groups to speak to), and for filling a shift by asking people directly.
 */
final class VolunteerPoolController extends ControllerBase {

  public function __construct(
    private readonly Preferences $preferences,
    private readonly Perks $perks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makehaven_tasks.volunteer_preferences'),
      $container->get('makehaven_tasks.volunteer_perks'),
    );
  }

  /**
   * Renders the page.
   */
  public function page(Request $request): array {
    $way = (string) $request->query->get('way', '');
    $ways = Preferences::ways();
    $build = [
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
      '#cache' => ['max-age' => 0],
    ];
    $board = Url::fromRoute('view.tasks.page_tasks_interactive')->toString();
    $build['nav'] = ['#markup' => '<div class="task-back-link"><a href="' . $board . '">← ' . $this->t('Volunteer board') . '</a></div>'];
    $build['help'] = ['#markup' => '<p class="vol-help">' . $this->t('People who told us how they like to help (at <a href=":prefs">How I like to help</a>), and everyone who has worked a shift. Use it to ask people directly.', [':prefs' => Url::fromRoute('makehaven_tasks.preferences')->toString()]) . '</p>'];

    $links = ['<a href="' . Url::fromRoute('makehaven_tasks.pool')->toString() . '"' . ($way === '' ? ' class="is-active"' : '') . '>' . $this->t('Everyone') . '</a>'];
    foreach ($ways as $key => $label) {
      $links[] = '<a href="' . Url::fromRoute('makehaven_tasks.pool', [], ['query' => ['way' => $key]])->toString() . '"' . ($way === $key ? ' class="is-active"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
    $build['filter'] = ['#markup' => '<nav class="vol-pool-filter">' . implode(' · ', $links) . '</nav>'];

    $prefs = $this->preferences->all();
    $days = $this->perks->days();
    $uids = array_unique(array_merge(array_keys($prefs), array_keys($days)));
    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($uids);
    $rows = [];
    foreach ($users as $uid => $user) {
      $p = $prefs[$uid] ?? NULL;
      if ($way !== '' && (!$p || !in_array($way, $p['ways'], TRUE))) {
        continue;
      }
      $mine = $days[$uid] ?? [];
      $rows[] = [
        'sort' => [count($mine), $user->getDisplayName()],
        'cells' => [
          ['data' => ['#type' => 'link', '#title' => $user->getDisplayName(), '#url' => $user->toUrl()]],
          $user->getEmail(),
          $p ? implode(', ', array_map(fn($k) => $ways[$k] ?? $k, $p['ways'])) : '',
          $p ? implode(', ', array_map(fn($k) => Preferences::availability()[$k] ?? $k, $p['availability'])) : '',
          $p ? $p['connections'] : '',
          $p && $p['notify'] ? '✓' : '',
          count($mine),
          $mine ? array_key_last($mine) : '',
        ],
      ];
    }
    usort($rows, fn($a, $b) => [$b['sort'][0], $a['sort'][1]] <=> [$a['sort'][0], $b['sort'][1]]);
    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Name'), $this->t('Email'), $this->t('Likes to'), $this->t('Free'), $this->t('Connections'), $this->t('Emails on'), $this->t('Days'), $this->t('Last')],
      '#rows' => array_column($rows, 'cells'),
      '#empty' => $this->t('Nobody yet.'),
      '#attributes' => ['class' => ['vol-pool-table']],
    ];
    return $build;
  }

}
