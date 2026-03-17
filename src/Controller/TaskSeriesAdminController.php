<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin page for recurring task series.
 */
class TaskSeriesAdminController extends ControllerBase {

  /**
   * Creates the controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * Builds the recurring series admin page.
   */
  public function content(): array {
    $header = [
      $this->t('Series'),
      $this->t('Frequency'),
      $this->t('Paused'),
      $this->t('Next due'),
      $this->t('Open occurrences'),
      $this->t('Current occurrence'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($this->loadSeriesSources() as $source) {
      $occurrences = $this->loadOpenOccurrences((int) $source->id());
      $current = $occurrences ? reset($occurrences) : NULL;

      $rows[] = [
        'series' => Link::fromTextAndUrl($source->label(), $source->toUrl())->toString(),
        'frequency' => _makehaven_tasks_field_label_or_fallback($source, 'field_task_frequency'),
        'paused' => $this->formatPaused($source),
        'next_due' => $this->formatNextDue($source),
        'open_occurrences' => (string) count($occurrences),
        'current_occurrence' => $current ? Link::fromTextAndUrl($current->label(), $current->toUrl())->toString() : $this->t('None'),
        'actions' => $this->buildActionLinks($source, $current),
      ];
    }

    $build = [
      'intro' => [
        '#markup' => '<p>' . $this->t('Manage recurring task sources in one place. Pause a series to stop future copies, or open the current occurrence to defer just that one.') . '</p>',
      ],
    ];

    if (!$rows) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No recurring task series found yet.') . '</p>',
      ];
      return $build;
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No recurring task series found.'),
    ];

    return $build;
  }

  /**
   * Loads source tasks for recurring series.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Source tasks keyed by nid.
   */
  protected function loadSeriesSources(): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->sort('title', 'ASC')
      ->execute();

    if (!$nids) {
      return [];
    }

    $tasks = $storage->loadMultiple($nids);
    $series = [];
    foreach ($tasks as $task) {
      if (!$task instanceof NodeInterface) {
        continue;
      }
      if ($task->hasField('field_task_series_source') && !$task->get('field_task_series_source')->isEmpty()) {
        continue;
      }

      $frequency = $task->hasField('field_task_frequency') && !$task->get('field_task_frequency')->isEmpty()
        ? (string) $task->get('field_task_frequency')->value
        : 'once';
      $paused = $task->hasField('field_task_series_paused') && (bool) $task->get('field_task_series_paused')->value;

      if (!_makehaven_tasks_is_recurring_frequency($frequency) && !$paused) {
        continue;
      }

      $series[(int) $task->id()] = $task;
    }

    return $series;
  }

  /**
   * Loads uncompleted occurrences for a series.
   *
   * @param int $source_nid
   *   Source node id.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Open occurrences sorted by next due.
   */
  protected function loadOpenOccurrences(int $source_nid): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->sort('created', 'DESC');

    $group = $query->orConditionGroup()
      ->condition('nid', $source_nid)
      ->condition('field_task_series_source.target_id', $source_nid);
    $query->condition($group);

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $completed = $this->loadCompletedTaskIds(array_values($nids));
    $tasks = $storage->loadMultiple($nids);

    $open = [];
    foreach ($tasks as $task) {
      $nid = (int) $task->id();
      if (isset($completed[$nid])) {
        continue;
      }
      $open[$nid] = $task;
    }

    uasort($open, function (NodeInterface $a, NodeInterface $b): int {
      $a_due = $a->hasField('field_task_next_due') && !$a->get('field_task_next_due')->isEmpty()
        ? strtotime((string) $a->get('field_task_next_due')->value) ?: PHP_INT_MAX
        : PHP_INT_MAX;
      $b_due = $b->hasField('field_task_next_due') && !$b->get('field_task_next_due')->isEmpty()
        ? strtotime((string) $b->get('field_task_next_due')->value) ?: PHP_INT_MAX
        : PHP_INT_MAX;
      return $a_due <=> $b_due;
    });

    return $open;
  }

  /**
   * Loads completed task ids.
   */
  protected function loadCompletedTaskIds(array $nids): array {
    if (!$nids) {
      return [];
    }

    $results = $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.flag_id', 'task_completed')
      ->condition('f.entity_id', $nids, 'IN')
      ->distinct()
      ->execute()
      ->fetchCol();

    $completed = [];
    foreach ($results as $entity_id) {
      $completed[(int) $entity_id] = TRUE;
    }
    return $completed;
  }

  /**
   * Formats the paused column.
   */
  protected function formatPaused(NodeInterface $source): string {
    if ($source->hasField('field_task_series_paused') && (bool) $source->get('field_task_series_paused')->value) {
      return (string) $this->t('Yes');
    }
    return (string) $this->t('No');
  }

  /**
   * Formats the next due column.
   */
  protected function formatNextDue(NodeInterface $source): string {
    if ($source->hasField('field_task_next_due') && !$source->get('field_task_next_due')->isEmpty()) {
      $timestamp = strtotime((string) $source->get('field_task_next_due')->value);
      if ($timestamp) {
        return $this->dateFormatter()->format($timestamp, 'custom', 'M j, Y g:i a');
      }
    }
    return (string) $this->t('Not set');
  }

  /**
   * Builds admin action links.
   */
  protected function buildActionLinks(NodeInterface $source, ?NodeInterface $current): string {
    $links = [];
    $links[] = Link::fromTextAndUrl($this->t('Manage series'), $source->toUrl('edit-form'))->toString();
    if ($current) {
      $links[] = Link::fromTextAndUrl($this->t('Edit current occurrence'), $current->toUrl('edit-form'))->toString();
    }
    $links[] = Link::fromTextAndUrl($this->t('View series source'), $source->toUrl())->toString();

    return implode(' | ', $links);
  }

}
