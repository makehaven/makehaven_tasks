<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Form\VolunteerDecisionForm;
use Drupal\makehaven_tasks\Volunteer\Decider;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\SignupStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The staff queue: opportunities to decide, drafts to release, stale claims.
 */
final class VolunteerApprovalsController extends ControllerBase {

  public function __construct(
    private readonly SignupStore $signups,
    private readonly Decider $decider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makehaven_tasks.volunteer_signups'),
      $container->get('makehaven_tasks.volunteer_decider'),
    );
  }

  /**
   * Renders the approvals page.
   */
  public function page(): array {
    $build = [
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
      '#cache' => ['max-age' => 0],
    ];
    $board = Url::fromRoute('makehaven_tasks.volunteer')->toString();
    $build['nav'] = ['#markup' => '<div class="task-back-link"><a href="' . $board . '">← Volunteer board</a></div>'];

    if (!Opportunity::fieldsInstalled()) {
      $build['pending'] = ['#markup' => '<p>' . $this->t('The volunteer fields are not installed yet. Run database updates.') . '</p>'];
      return $build;
    }

    $storage = $this->entityTypeManager()->getStorage('node');
    $now = \Drupal::time()->getCurrentTime();

    // 1. Gathering interest: the decision queue.
    $gathering = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('field_task_stage', Opportunity::STAGE_GATHERING)
      ->sort('field_task_decide_by')
      ->execute());
    $build['gathering'] = $this->section(
      $this->t('Gathering interest (@n)', ['@n' => count($gathering)]),
      $this->t('Approve when it is aligned, viable and has enough people. Anything still short on its decide-by date is declined automatically, and only its volunteers are told.'),
      $gathering,
      $now
    );

    // 2. Proposed drafts waiting to be released.
    $proposed = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('field_task_stage', Opportunity::STAGE_PROPOSED)
      ->sort('created')
      ->execute());
    $build['proposed'] = $this->section(
      $this->t('Drafts waiting to be put out (@n)', ['@n' => count($proposed)]),
      $this->t('Not on the board and not posted anywhere yet. A facilitator or staff member puts one out to gather interest.'),
      $proposed,
      $now
    );

    // 3. Claims.
    $claims = $this->decider->staleClaims($now);
    $first = (int) ($this->config('makehaven_tasks.settings')->get('stale_first_days') ?: 14);
    $post = array_filter($claims['post_launch'], fn($r) => $r['idle_days'] >= $first);
    $build['stale'] = $this->claimTable(
      $this->t('Stale claims (@n)', ['@n' => count($post)]),
      $this->t('Claimed since the volunteer board launched and idle @d+ days. The lead has been emailed privately; nothing is released automatically.', ['@d' => $first]),
      $post
    );
    $build['cleanup'] = $this->claimTable(
      $this->t('Older claims to clean up (@n)', ['@n' => count($claims['pre_launch'])]),
      $this->t('Claimed before the volunteer board launched. These people are never emailed automatically; check in by hand, then reassign, hand off or mark done.'),
      $claims['pre_launch']
    );
    return $build;
  }

  /**
   * One section of opportunity rows, each with its inline decision form.
   */
  private function section($title, $help, array $nodes, int $now): array {
    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vol-approvals-section']],
      'title' => ['#markup' => '<h2>' . $title . '</h2><p class="vol-help">' . $help . '</p>'],
    ];
    if (!$nodes) {
      $section['empty'] = ['#markup' => '<p><em>' . $this->t('Nothing here.') . '</em></p>'];
      return $section;
    }
    foreach ($nodes as $node) {
      /** @var \Drupal\node\NodeInterface $node */
      $decide = Opportunity::decideBy($node);
      $overdue = $decide && $decide <= $now;
      $owner = $node->getOwner();
      $header = '<div class="vol-row-head"><a href="' . $node->toUrl()->toString() . '"><strong>'
        . htmlspecialchars((string) $node->label(), ENT_QUOTES) . '</strong></a>'
        . ' <span class="vol-kind">' . (Opportunity::isShift($node) ? $this->t('Shift') : $this->t('Task')) . '</span>'
        . ($overdue ? ' <span class="vol-overdue">' . $this->t('decide-by passed') . '</span>' : '')
        . ' <span class="vol-meta">' . $this->t('posted by @who', ['@who' => $owner ? $owner->getDisplayName() : '?']) . '</span></div>';
      $form = \Drupal::classResolver(VolunteerDecisionForm::class)->setNid((int) $node->id());
      $section['row_' . $node->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['vol-approval-row']],
        'head' => ['#markup' => $header . VolunteerDecisionForm::summaryHtml($node, $this->signups)],
        'form' => $this->formBuilder()->getForm($form, $node, TRUE),
      ];
    }
    return $section;
  }

  /**
   * A table of claims.
   */
  private function claimTable($title, $help, array $rows): array {
    $out = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vol-approvals-section']],
      'title' => ['#markup' => '<h2>' . $title . '</h2><p class="vol-help">' . $help . '</p>'],
    ];
    if (!$rows) {
      $out['empty'] = ['#markup' => '<p><em>' . $this->t('Nothing here.') . '</em></p>'];
      return $out;
    }
    $table_rows = [];
    foreach ($rows as $row) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $row['node'];
      $lead = $row['lead'];
      $table_rows[] = [
        ['data' => ['#type' => 'link', '#title' => $node->label(), '#url' => $node->toUrl()]],
        $lead ? ['data' => ['#type' => 'link', '#title' => $lead->getDisplayName(), '#url' => $lead->toUrl()]] : '-',
        \Drupal::service('date.formatter')->format($row['last_activity'], 'custom', 'M j, Y'),
        $row['idle_days'],
        ['data' => ['#type' => 'link', '#title' => $this->t('Hand off / reassign'), '#url' => Url::fromRoute('makehaven_tasks.handoff', ['node' => $node->id()])]],
      ];
    }
    $out['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Task'), $this->t('Lead'), $this->t('Last change'), $this->t('Days idle'), ''],
      '#rows' => $table_rows,
    ];
    return $out;
  }

}
