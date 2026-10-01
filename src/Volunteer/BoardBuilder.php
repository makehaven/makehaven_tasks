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
    private readonly Preferences $preferences,
    private readonly Perks $perks,
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
   * Everything recruiting, in one list: gathering interest and approved dated
   * opportunities that have not ended. Dated first, soonest first; undated
   * ones after, by sign-up deadline.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The opportunities.
   */
  public function opportunities(array $filters, AccountInterface $account): array {
    $all = [];
    foreach (array_merge($this->gathering($filters, $account), $this->comingUp($filters, $account)) as $node) {
      $all[(int) $node->id()] = $node;
    }
    $key = fn(NodeInterface $n) => Opportunity::start($n) !== NULL ? [0, Opportunity::start($n)] : [1, Opportunity::decideBy($n) ?? PHP_INT_MAX];
    uasort($all, fn(NodeInterface $a, NodeInterface $b) => $key($a) <=> $key($b));
    return array_values($all);
  }

  /**
   * Whether a task is new (posted in the last week).
   */
  public function isNew(NodeInterface $node): bool {
    return (int) $node->getCreatedTime() >= $this->time->getCurrentTime() - 7 * 86400;
  }

  /**
   * Whether a task suits this person: a way they said they like to help, or
   * a badge it needs that they hold.
   */
  public function isForYou(NodeInterface $node, AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ($this->preferences->matches($node, (int) $account->id())) {
      return TRUE;
    }
    $audience = $node->hasField('field_task_audience') ? (string) $node->get('field_task_audience')->value : '';
    if ($audience === 'badge_holders' && $node->hasField('field_task_required_badge') && ($tid = (int) $node->get('field_task_required_badge')->target_id)) {
      return function_exists('_makehaven_tasks_user_has_badge') && _makehaven_tasks_user_has_badge((int) $account->id(), $tid);
    }
    return FALSE;
  }

  /**
   * One opportunity card: tags, title, when, a fill bar and one button.
   */
  public function cardHtml(NodeInterface $node, AccountInterface $account): string {
    $nid = (int) $node->id();
    $uid = (int) $account->id();
    $gathering = Opportunity::isGathering($node);
    $url = $node->toUrl()->toString();
    $slots = Opportunity::slots($node);
    $counts = $this->signups->slotCounts($node);
    $min = Opportunity::minNeeded($node);
    $short = $this->signups->stillNeeded($node);
    $full = $this->signups->full($node);
    $e = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES);

    // Status tag: the one thing to know.
    if ($full) {
      $status = '<span class="vol-pill vol-pill--full">' . t('Full') . '</span>';
    }
    elseif ($short > 0) {
      $status = '<span class="vol-pill vol-pill--needs">' . t('@n more needed', ['@n' => $short]) . '</span>';
    }
    elseif ($gathering) {
      $status = '<span class="vol-pill vol-pill--ok">' . t('Enough people · staff to confirm') . '</span>';
    }
    else {
      $status = '<span class="vol-pill vol-pill--on">' . t("It's on · room for more") . '</span>';
    }
    $chips = $status;
    if (Opportunity::isTabling($node)) {
      $chips .= '<span class="vol-chip vol-chip--tabling">' . t('Tabling') . '</span>';
    }
    if ($this->isForYou($node, $account)) {
      $chips .= '<span class="vol-chip vol-chip--you">★ ' . t('For you') . '</span>';
    }
    if ($this->isNew($node)) {
      $chips .= '<span class="vol-chip vol-chip--new">' . t('New') . '</span>';
    }
    $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : NULL;
    if ($audience === 'badge_holders') {
      $chips .= '<span class="vol-chip vol-chip--badge">🔑 ' . t('Needs a badge') . '</span>';
    }

    // When: one line. Several slots on a day read "Sun Oct 11 · 3 time slots".
    $when = '';
    if (count($slots) > 1) {
      $days = array_unique(array_map(fn($slot) => Opportunity::dateLabel($slot['start']), $slots));
      $when = implode(', ', $days) . ' · ' . t('@n time slots', ['@n' => count($slots)]);
    }
    elseif ($slots) {
      $when = Opportunity::whenLabel($node);
    }

    // Fill bar: people in across every slot, against what is needed.
    $needed_total = $min * max(1, count($counts));
    $have = 0;
    foreach ($counts as $n) {
      $have += min($n, $min);
    }
    $pct = $needed_total ? (int) round(100 * $have / $needed_total) : 0;
    $bar = '<div class="vol-fill" role="img" aria-label="' . $e(t('@have of @need people', ['@have' => $have, '@need' => $needed_total])) . '"><span style="width:' . $pct . '%"></span></div>'
      . '<span class="vol-fill__label">' . t('@have of @need', ['@have' => $have, '@need' => $needed_total]) . '</span>';

    $action = '';
    if ($account->isAuthenticated()) {
      $interest_url = Url::fromRoute('makehaven_tasks.interest', ['node' => $nid])->toString();
      if ($this->signups->has($node, $uid)) {
        $action = '<a class="task-action-btn task-action-btn--done" href="' . $interest_url . '">✓ ' . t("You're in") . '</a>';
      }
      elseif (!$full) {
        $action = '<a class="task-action-btn" href="' . $interest_url . '">' . t("I'm in") . '</a>';
      }
    }
    else {
      $action = '<a class="task-action-btn" href="' . Url::fromRoute('user.login', [], ['query' => ['destination' => $url]])->toString() . '">' . t('Log in to sign up') . '</a>';
    }

    return '<article class="vol-card' . ($gathering ? ' vol-card--gathering' : ' vol-card--on') . '">'
      . '<div class="vol-card__chips">' . $chips . '</div>'
      . '<h3 class="vol-card__title"><a href="' . $url . '">' . $e($node->label()) . '</a></h3>'
      . ($when !== '' ? '<div class="vol-card__meta">🗓 ' . $e($when) . '</div>' : '')
      . '<div class="vol-card__fill">' . $bar . '</div>'
      . ($action ? '<div class="task-card-actions">' . $action . '</div>' : '')
      . '</article>';
  }

  /**
   * "You've volunteered on 2 days · 3 more for a hoodie" and a nudge to say
   * how you like to help.
   */
  public function youBarHtml(AccountInterface $account): string {
    if (!$account->isAuthenticated()) {
      return '';
    }
    $uid = (int) $account->id();
    $bits = [];
    $progress = $this->perks->progress($uid);
    if ($progress['days'] > 0) {
      $bits[] = (string) t('You have volunteered on @n @days.', ['@n' => $progress['days'], '@days' => $progress['days'] === 1 ? t('day') : t('days')])
        . ($progress['has_hoodie'] || $progress['to_hoodie'] === 0 ? '' : ' ' . t('@n more for a MakeHaven hoodie.', ['@n' => $progress['to_hoodie']]));
    }
    $prefs_url = Url::fromRoute('makehaven_tasks.preferences')->toString();
    $bits[] = $this->preferences->has($uid)
      ? '<a href="' . $prefs_url . '">' . t('How I like to help') . '</a>'
      : '<a href="' . $prefs_url . '">' . t('Tell us how you like to help') . '</a> ' . t('and we will point out what suits you.');
    return '<p class="vol-you">' . implode(' ', $bits) . '</p>';
  }

  /**
   * The sections rendered above the ongoing task list.
   */
  public function sectionsHtml(array $filters, AccountInterface $account): string {
    $opportunities = $this->opportunities($filters, $account);
    $html = $this->youBarHtml($account);
    $html .= '<section class="vol-section" aria-labelledby="vol-opps"><h2 id="vol-opps">' . t('Volunteer opportunities') . '</h2>';
    if ($opportunities) {
      $html .= '<div class="vol-cards">';
      foreach ($opportunities as $node) {
        $html .= $this->cardHtml($node, $account);
      }
      $html .= '</div>';
    }
    else {
      $html .= '<p class="vol-empty">' . t('Nothing scheduled right now. The ongoing tasks below can be done any time.') . '</p>';
    }
    $html .= '<p class="vol-perks">🎁 ' . htmlspecialchars($this->perks->policyText(), ENT_QUOTES) . '</p>';
    $html .= '</section>';
    $html .= '<h2 class="vol-ongoing-heading">' . t('Ongoing tasks') . '</h2>'
      . '<p class="vol-help">' . t('Things to do any time. ★ marks ones that suit you; claim one to let others know you are on it.') . '</p>';
    return $html;
  }

}
