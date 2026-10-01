<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Form\VolunteerPerkForm;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\Perks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff page: thank-yous owed, and what has been handed out.
 */
final class VolunteerPerksController extends ControllerBase {

  public function __construct(private readonly Perks $perks) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('makehaven_tasks.volunteer_perks'));
  }

  /**
   * Renders the page.
   */
  public function page(): array {
    $build = [
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
      '#cache' => ['max-age' => 0],
    ];
    $board = Url::fromRoute('view.tasks.page_tasks_interactive')->toString();
    $build['nav'] = ['#markup' => '<div class="task-back-link"><a href="' . $board . '">← ' . $this->t('Volunteer board') . '</a></div>'];
    $policy = $this->perks->policy();
    $build['policy'] = [
      '#markup' => '<p class="vol-help">' . htmlspecialchars($this->perks->policyText(), ENT_QUOTES) . ' '
      . $this->t('Counted from confirmed time slots on approved shifts and tabling once they have ended. "Given" on a t-shirt or hoodie also takes one off the store count. Amounts are in <a href=":settings">Tasks settings</a>.', [':settings' => Url::fromRoute('makehaven_tasks.settings_form')->toString()]) . '</p>',
    ];

    $owed = $this->perks->owed();
    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple(array_unique(array_column($owed, 'uid')));
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_unique(array_column($owed, 'nid')));
    $build['owed_title'] = ['#markup' => '<h2>' . $this->t('Owed (@n)', ['@n' => count($owed)]) . '</h2>'];
    if (!$owed) {
      $build['owed_empty'] = ['#markup' => '<p><em>' . $this->t('Nothing owed right now.') . '</em></p>'];
    }
    else {
      $rows = [];
      foreach ($owed as $i => $row) {
        $user = $users[$row['uid']] ?? NULL;
        $node = $nodes[$row['nid']] ?? NULL;
        $what = Perks::label($row['perk']) . ($row['perk'] === Perks::LUNCH ? ' ($' . $policy['lunch_amount'] . ')' : '');
        $form = \Drupal::classResolver(VolunteerPerkForm::class)->setRow($row);
        $rows[] = [
          $user ? ['data' => ['#type' => 'link', '#title' => $user->getDisplayName(), '#url' => $user->toUrl()]] : (string) $row['uid'],
          $what,
          $row['why'],
          Opportunity::dateLabel(strtotime($row['day'] . ' 12:00')),
          $node ? ['data' => ['#type' => 'link', '#title' => $node->label(), '#url' => $node->toUrl()]] : '-',
          ['data' => $this->formBuilder()->getForm($form, $row)],
        ];
      }
      $build['owed'] = [
        '#type' => 'table',
        '#header' => [$this->t('Volunteer'), $this->t('Thank-you'), $this->t('Why'), $this->t('Day'), $this->t('Opportunity'), ''],
        '#rows' => $rows,
        '#attributes' => ['class' => ['vol-perks-table']],
      ];
    }

    // Handed out, most recent first.
    $given = \Drupal::database()->select(Perks::TABLE, 'p')
      ->fields('p')
      ->orderBy('created', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll();
    $build['given_title'] = ['#markup' => '<h2>' . $this->t('Recorded') . '</h2>'];
    if (!$given) {
      $build['given_empty'] = ['#markup' => '<p><em>' . $this->t('Nothing recorded yet.') . '</em></p>'];
      return $build;
    }
    $people = $this->entityTypeManager()->getStorage('user')->loadMultiple(array_unique(array_merge(array_map(fn($r) => (int) $r->uid, $given), array_map(fn($r) => (int) $r->by_uid, $given))));
    $rows = [];
    foreach ($given as $r) {
      $rows[] = [
        isset($people[(int) $r->uid]) ? $people[(int) $r->uid]->getDisplayName() : $r->uid,
        Perks::label((string) $r->perk) . ($r->ref !== '' ? ' (' . $r->ref . ')' : ''),
        $r->status === 'skipped' ? $this->t('Skipped') : ($r->perk === Perks::NO_SHOW ? '-' : $this->t('Given')),
        (string) $r->note,
        $r->adjustment_id ? $this->t('Store adjusted') : '',
        (isset($people[(int) $r->by_uid]) ? $people[(int) $r->by_uid]->getDisplayName() : '') . ', ' . \Drupal::service('date.formatter')->format((int) $r->created, 'custom', 'M j, Y'),
      ];
    }
    $build['given'] = [
      '#type' => 'table',
      '#header' => [$this->t('Volunteer'), $this->t('What'), $this->t('Status'), $this->t('Note'), $this->t('Store'), $this->t('By')],
      '#rows' => $rows,
    ];
    return $build;
  }

}
