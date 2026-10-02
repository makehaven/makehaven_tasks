<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\JobBoard\JobBoard;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The job board review queue at /admin/content/job-board.
 */
class JobBoardController extends ControllerBase {

  public function __construct(
    protected JobBoard $manager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makehaven_tasks.job_board'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists waiting, recently posted and recently declined job board requests.
   */
  public function queue(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'job_posting')
      ->exists('field_job_source')
      ->condition('changed', strtotime('-90 days'), '>=')
      ->sort('created', 'DESC')
      ->range(0, 200)
      ->execute();
    $groups = ['pending' => [], 'posted' => [], 'declined' => []];
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($this->manager->isPending($node)) {
        $groups['pending'][] = $node;
      }
      elseif ($node->isPublished()) {
        $groups['posted'][] = $node;
      }
      else {
        $groups['declined'][] = $node;
      }
    }

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Requests from <a href=":form">the public form</a> land here. <strong>Approve</strong> publishes the posting (members only) and posts it to Slack #jobs, plus a pointer in up to two trade channels. Fix typos with Edit first if needed. Decline sends nothing. Members posting their own requests in #jobs never come through here.', [':form' => Url::fromUserInput('/commission')->toString()]) . '</p>',
    ];
    $build['pending'] = $this->table($this->t('Waiting for review'), $groups['pending'], 'pending', $this->t('Nothing waiting. New requests appear here as soon as someone submits the form.'));
    $build['posted'] = $this->table($this->t('Posted (last 90 days)'), $groups['posted'], 'posted', $this->t('Nothing posted yet.'));
    $build['declined'] = $this->table($this->t('Declined (last 90 days)'), $groups['declined'], 'declined', $this->t('Nothing declined.'));
    $build['#cache'] = ['tags' => ['node_list:job_posting'], 'contexts' => ['user.permissions']];
    return $build;
  }

  /**
   * Publishes (and so posts) one job board request.
   */
  public function approve(NodeInterface $node): RedirectResponse {
    if ($this->manager->isIntake($node) && !$node->isPublished()) {
      $node->set('field_job_status', $this->termId('job_status', 'Open'));
      $node->setPublished();
      $node->setRevisionLogMessage('Approved by ' . $this->currentUser()->getAccountName());
      $node->save();
      if ($node->get('field_job_slack_ts')->isEmpty()) {
        $this->messenger()->addWarning($this->t('"@title" is published, but the Slack post failed. Use "Post to Slack" to try again, or paste it into #jobs by hand.', ['@title' => $node->label()]));
      }
      else {
        $this->messenger()->addStatus($this->t('"@title" is posted to #jobs.', ['@title' => $node->label()]));
      }
    }
    return $this->backToQueue();
  }

  /**
   * Declines one job board request: stays unpublished, status Closed.
   */
  public function decline(NodeInterface $node): RedirectResponse {
    if ($this->manager->isIntake($node) && !$node->isPublished()) {
      $node->set('field_job_status', $this->termId('job_status', 'Closed'));
      $node->setRevisionLogMessage('Declined by ' . $this->currentUser()->getAccountName());
      $node->save();
      $this->messenger()->addStatus($this->t('Declined "@title". Nothing was sent to anyone.', ['@title' => $node->label()]));
    }
    return $this->backToQueue();
  }

  /**
   * Retries the Slack post for a published posting that never made it.
   */
  public function repost(NodeInterface $node): RedirectResponse {
    if ($this->manager->isIntake($node) && $node->isPublished() && $node->get('field_job_slack_ts')->isEmpty()) {
      if ($this->manager->postToSlack($node)) {
        $node->save();
        $this->messenger()->addStatus($this->t('"@title" is posted to #jobs.', ['@title' => $node->label()]));
      }
      else {
        $this->messenger()->addError($this->t('Slack refused the post again. The reason is in the site log (type makehaven_tasks).'));
      }
    }
    return $this->backToQueue();
  }

  /**
   * Renders one group as a table.
   */
  protected function table($title, array $nodes, string $group, $empty): array {
    $rows = [];
    foreach ($nodes as $node) {
      $d = $this->manager->messageData($node);
      $actions = [];
      if ($group === 'pending' || $group === 'declined') {
        $actions[] = $this->action($this->t('Approve and post'), 'makehaven_tasks.job_board.approve', $node, 'button--primary');
      }
      if ($group === 'pending') {
        $actions[] = $this->action($this->t('Decline'), 'makehaven_tasks.job_board.decline', $node);
      }
      if ($group === 'posted' && $node->get('field_job_slack_ts')->isEmpty()) {
        $actions[] = $this->action($this->t('Post to Slack'), 'makehaven_tasks.job_board.repost', $node, 'button--primary');
      }
      $edit = $node->toUrl('edit-form', ['query' => ['destination' => '/admin/content/job-board']]);
      $actions[] = Link::fromTextAndUrl($this->t('Edit'), $edit)->toRenderable()
        + ['#attributes' => ['class' => ['button', 'button--small']]];

      $summary = array_filter([
        $d['type'],
        $d['pay'] ? 'Pay: ' . $d['pay'] : '',
        $d['when'] ? 'When: ' . $d['when'] : '',
      ]);
      $title = Link::fromTextAndUrl($node->label(), $node->toUrl())->toString()
        . '<br><small>' . Html::escape(implode(' · ', $summary)) . '</small>';

      $received = $this->dateFormatter->format($node->getCreatedTime(), 'short');
      $source = $node->get('field_job_source')->value;
      if ($source !== 'form') {
        $received .= ' (' . ($source === 'staff' ? $this->t('staff') : $this->t('trusted')) . ')';
      }

      $slack = '';
      if ($group === 'posted') {
        $slack = $node->get('field_job_slack_ts')->isEmpty() ? $this->t('Not in Slack') : $this->t('In #jobs');
      }

      $rows[] = [
        ['data' => ['#markup' => $title]],
        implode(' · ', array_filter([$d['organization'], $d['contact_name']])),
        $received,
        $slack,
        [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['style' => 'display:flex;gap:.4rem;flex-wrap:wrap'],
            'actions' => $actions,
          ],
        ],
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $title . ' (' . count($nodes) . ')',
      '#open' => $group !== 'declined',
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Opportunity'), $this->t('From'), $this->t('Received'), $this->t('Slack'), $this->t('Actions')],
        '#rows' => $rows,
        '#empty' => $empty,
      ],
    ];
  }

  /**
   * A CSRF-protected action button.
   */
  protected function action($label, string $route, NodeInterface $node, string $class = ''): array {
    return Link::fromTextAndUrl($label, Url::fromRoute($route, ['node' => $node->id()]))->toRenderable()
      + ['#attributes' => ['class' => array_filter(['button', 'button--small', $class])]];
  }

  /**
   * Redirects to the queue.
   */
  protected function backToQueue(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('makehaven_tasks.job_board')->toString());
  }

  /**
   * Looks a term up by name.
   */
  protected function termId(string $vid, string $name): ?int {
    $ids = $this->entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)->condition('vid', $vid)->condition('name', $name)->range(0, 1)->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

}
