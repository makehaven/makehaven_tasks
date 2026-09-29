<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The volunteer board's own sections, above the existing task list.
 *
 * /tasks (and /volunteer, the same view display) is the one place that shows
 * things people can do in the space: opportunities gathering interest, dated
 * shifts coming up, then the ongoing task list. All three honour the same
 * skill and interest filters (JR, 2026-09-28).
 */
final class BoardBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SignupStore $signups,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The filters from the query string, normalised.
   *
   * @return array{skill:string, area:string}
   *   skill: '' | 'open' | 'badge'; area: '' | 'general' | a term id.
   */
  public static function filters(Request $request): array {
    $skill = (string) $request->query->get('skill', '');
    $area = (string) $request->query->get('area', '');
    return [
      'skill' => in_array($skill, ['open', 'badge'], TRUE) ? $skill : '',
      'area' => ($area === 'general' || ctype_digit($area)) ? $area : '',
    ];
  }

  /**
   * Area-of-interest term ids a task belongs to (its own, plus its tool's).
   */
  public static function areasOf(NodeInterface $node): array {
    $tids = [];
    if ($node->hasField('field_task_interest')) {
      foreach ($node->get('field_task_interest') as $item) {
        $tids[] = (int) $item->target_id;
      }
    }
    if ($node->hasField('field_task_equipment') && ($item = $node->get('field_task_equipment')->entity)) {
      if ($item->hasField('field_item_area_interest')) {
        foreach ($item->get('field_item_area_interest') as $ref) {
          $tids[] = (int) $ref->target_id;
        }
      }
    }
    return array_values(array_unique(array_filter($tids)));
  }

  /**
   * Whether a task passes the board filters.
   */
  public static function matches(NodeInterface $node, array $filters): bool {
    $audience = $node->hasField('field_task_audience') ? (string) $node->get('field_task_audience')->value : '';
    if ($filters['skill'] === 'open' && !in_array($audience, ['', 'open_member'], TRUE)) {
      return FALSE;
    }
    if ($filters['skill'] === 'badge' && $audience !== 'badge_holders') {
      return FALSE;
    }
    if ($filters['area'] !== '') {
      $areas = self::areasOf($node);
      if ($filters['area'] === 'general' ? (bool) $areas : !in_array((int) $filters['area'], $areas, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Published opportunities gathering interest, soonest decide-by first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The tasks.
   */
  public function gathering(array $filters, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_stage', Opportunity::STAGE_GATHERING)
      ->sort('field_task_decide_by')
      ->execute();
    return array_values(array_filter($storage->loadMultiple($nids), fn(NodeInterface $n) => self::matches($n, $filters) && $this->visible($n, $account)));
  }

  /**
   * Approved dated shifts that have not ended, soonest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The shifts.
   */
  public function comingUp(array $filters, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_type', Opportunity::TYPE_SHIFT)
      ->condition('field_task_when.end_value', Opportunity::timestampToStorage($this->time->getCurrentTime()), '>=')
      ->sort('field_task_when.value');
    $or = $query->orConditionGroup()
      ->notExists('field_task_stage')
      ->condition('field_task_stage', Opportunity::STAGE_APPROVED);
    $query->condition($or);
    return array_values(array_filter($storage->loadMultiple($query->execute()), fn(NodeInterface $n) => self::matches($n, $filters) && $this->visible($n, $account)));
  }

  /**
   * Staff-only work stays off everyone else's board, as in the task list.
   */
  private function visible(NodeInterface $node, AccountInterface $account): bool {
    $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : NULL;
    return $audience !== 'staff_only' || (bool) array_intersect(['administrator', 'manager', 'content_editor'], $account->getRoles());
  }

  /**
   * The filter bar (a plain GET form, so it works without JavaScript).
   */
  public function filterBarHtml(array $filters, string $action): string {
    $skill_opts = ['' => t('Any skill level'), 'open' => t('No badge needed'), 'badge' => t('Needs a badge')];
    $area_opts = ['' => t('Any interest'), 'general' => t('General (not tied to an area)')];
    if ($this->entityTypeManager->hasDefinition('taxonomy_term')) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'area_of_interest', 'status' => 1]);
      uasort($terms, fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));
      foreach ($terms as $term) {
        $area_opts[(string) $term->id()] = $term->label();
      }
    }
    $select = function (string $name, array $options, string $current, string $label): string {
      $html = '<label class="vol-filter"><span class="visually-hidden">' . htmlspecialchars($label, ENT_QUOTES) . '</span><select name="' . $name . '" onchange="this.form.submit()">';
      foreach ($options as $value => $text) {
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . htmlspecialchars((string) $text, ENT_QUOTES) . '</option>';
      }
      return $html . '</select></label>';
    };
    $active = $filters['skill'] !== '' || $filters['area'] !== '';
    return '<form class="vol-filters" method="get" action="' . htmlspecialchars($action, ENT_QUOTES) . '" role="search" aria-label="' . t('Filter the volunteer board') . '">'
      . $select('skill', $skill_opts, $filters['skill'], (string) t('Skill level'))
      . $select('area', $area_opts, $filters['area'], (string) t('Interest'))
      . '<noscript><button type="submit">' . t('Filter') . '</button></noscript>'
      . ($active ? ' <a class="vol-filter-clear" href="' . htmlspecialchars($action, ENT_QUOTES) . '">' . t('Clear') . '</a>' : '')
      . '</form>';
  }

  /**
   * One opportunity card (gathering or an approved shift).
   */
  public function cardHtml(NodeInterface $node, AccountInterface $account): string {
    $nid = (int) $node->id();
    $uid = (int) $account->id();
    $count = $this->signups->count($node);
    $needed = Opportunity::minNeeded($node);
    $max = Opportunity::maxAllowed($node);
    $gathering = Opportunity::isGathering($node);
    $url = $node->toUrl()->toString();

    $chips = '<span class="vol-chip vol-chip--' . ($gathering ? 'gathering' : 'shift') . '">'
      . ($gathering ? t('Gathering interest') : t('Coming up')) . '</span>';
    if (Opportunity::isShift($node)) {
      $chips .= '<span class="vol-chip">' . t('Shift') . '</span>';
    }
    $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : NULL;
    if ($audience === 'badge_holders') {
      $chips .= '<span class="vol-chip vol-chip--badge">🔑 ' . t('Needs a badge') . '</span>';
    }

    $meta = [];
    if (($when = Opportunity::whenLabel($node)) !== '') {
      $meta[] = '🗓 ' . htmlspecialchars($when, ENT_QUOTES);
    }
    $count_cls = $count >= $needed ? 'vol-count vol-count--ok' : 'vol-count vol-count--short';
    $meta[] = '<span class="' . $count_cls . '">' . ($gathering
      ? t('@count interested of @needed needed', ['@count' => $count, '@needed' => $needed])
      : t('@count signed up', ['@count' => $count])) . ($max ? ' ' . t('(max @max)', ['@max' => $max]) : '') . '</span>';
    if ($gathering && ($decide = Opportunity::decideBy($node))) {
      $meta[] = t('decide by @d', ['@d' => Opportunity::dateLabel($decide)]);
    }

    $faces = '';
    foreach (array_slice($this->signups->list($node), 0, 6) as $row) {
      $faces .= function_exists('_makehaven_tasks_render_face') ? _makehaven_tasks_render_face($row['uid'], '', 'sm') : '';
    }

    $action = '';
    if ($account->isAuthenticated()) {
      $interest_url = Url::fromRoute('makehaven_tasks.interest', ['node' => $nid])->toString();
      if ($this->signups->has($node, $uid)) {
        $action = '<span class="task-card-badge task-card-inprogress">✓ ' . ($gathering ? t("You're interested") : t("You're signed up")) . '</span> '
          . '<a class="task-card-details-link" href="' . $interest_url . '">' . t('Change') . '</a>';
      }
      elseif ($max !== NULL && $count >= $max) {
        $action = '<span class="task-card-badge">' . t('Full') . '</span>';
      }
      else {
        $action = '<a class="task-action-btn" href="' . $interest_url . '">' . ($gathering ? '🙋 ' . t("I'm interested") : t('Sign me up')) . '</a>';
      }
    }
    $action .= ' <a class="task-card-details-link" href="' . $url . '">' . t('Details →') . '</a>';

    return '<article class="vol-card">'
      . '<div class="vol-card__chips">' . $chips . '</div>'
      . '<h3 class="vol-card__title"><a href="' . $url . '">' . htmlspecialchars((string) $node->label(), ENT_QUOTES) . '</a></h3>'
      . '<div class="vol-card__meta">' . implode(' · ', $meta) . '</div>'
      . ($faces ? '<div class="vol-card__people">' . $faces . '</div>' : '')
      . '<div class="task-card-actions">' . $action . '</div>'
      . '</article>';
  }

  /**
   * The sections rendered above the task list.
   */
  public function sectionsHtml(array $filters, AccountInterface $account): string {
    $html = '';
    $gathering = $this->gathering($filters, $account);
    // Always rendered, even when empty: with nothing gathering, a board that
    // shows only the task list gives no hint that opportunities exist or where
    // to post one (JR, 2026-09-29, first look at live).
    $post_url = Url::fromRoute('makehaven_tasks.request')->toString();
    $elevated = $account->hasPermission('makehaven_tasks.create_task');
    $post_label = $elevated ? t('Post a volunteer opportunity') : t('Suggest an opportunity');
    $html .= '<section class="vol-section" aria-labelledby="vol-gathering"><h2 id="vol-gathering">' . t('Gathering interest') . '</h2>'
      . '<p class="vol-help">' . t('Ideas that go ahead once enough people are in: a build day, a clean-up, staffing a table at a community event. Tap "I\'m interested" on one and staff confirm it once enough people have signed up. Saying you are interested is not a promise.') . '</p>';
    if ($gathering) {
      $html .= '<div class="vol-cards">';
      foreach ($gathering as $node) {
        $html .= $this->cardHtml($node, $account);
      }
      $html .= '</div>';
    }
    else {
      $html .= '<p class="vol-empty">' . t('Nothing is gathering volunteers right now.') . '</p>';
    }
    $html .= '<p class="vol-post"><a class="task-staff-btn task-staff-btn--primary" href="' . $post_url . '">' . $post_label . '</a>'
      . ($elevated ? ' <span class="vol-help">' . t('Staff and facilitators: a dated shift (such as tabling at an event) can gather interest first or go straight to the board with its crew.') . '</span>' : '')
      . '</p></section>';
    $coming = $this->comingUp($filters, $account);
    if ($coming) {
      $html .= '<section class="vol-section" aria-labelledby="vol-coming"><h2 id="vol-coming">' . t('Coming up') . '</h2><div class="vol-cards">';
      foreach ($coming as $node) {
        $html .= $this->cardHtml($node, $account);
      }
      $html .= '</div></section>';
    }
    $html .= '<h2 class="vol-ongoing-heading">' . t('Ongoing tasks') . '</h2>';
    return $html;
  }

}
