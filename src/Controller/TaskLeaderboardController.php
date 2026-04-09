<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the community task leaderboard page.
 *
 * Shows all-time + this-month rankings, a monthly completion trend,
 * and a category breakdown — all as accessible CSS-based visualizations.
 */
class TaskLeaderboardController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * Renders the leaderboard page.
   */
  public function page(): array {
    // Determine the actual base table for the flagging entity.
    $flagging_table = $this->entityTypeManager()
      ->getDefinition('flagging')
      ->getBaseTable();

    $all_time = $this->queryLeaderboard($flagging_table);
    $this_month = $this->queryLeaderboard($flagging_table, strtotime('first day of this month midnight'));
    $monthly = $this->queryMonthlyTrend($flagging_table);
    $categories = $this->queryCategoryBreakdown($flagging_table);

    $html = $this->renderPage($all_time, $this_month, $monthly, $categories);

    return [
      '#markup' => \Drupal\Core\Render\Markup::create($html),
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
      '#cache' => [
        'max-age' => 600,
        'contexts' => ['user.roles'],
        'tags' => ['flagging_list'],
      ],
    ];
  }

  // ── Queries ──────────────────────────────────────────────────────────────

  /**
   * Returns top contributors, optionally filtered by a start timestamp.
   *
   * @return array[] Each row: ['uid', 'name', 'count', 'milestone']
   */
  protected function queryLeaderboard(string $table, ?int $since = NULL, int $limit = 15): array {
    $query = $this->database->select($table, 'f')
      ->fields('f', ['uid'])
      ->condition('f.flag_id', 'task_completed')
      ->groupBy('f.uid')
      ->orderBy('task_count', 'DESC')
      ->range(0, $limit);
    $query->addExpression('COUNT(*)', 'task_count');

    if ($since !== NULL) {
      $query->condition('f.created', $since, '>=');
    }

    $rows = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $user = User::load($row->uid);
      if (!$user) {
        continue;
      }
      $count = (int) $row->task_count;
      $rows[] = [
        'uid'       => (int) $row->uid,
        'name'      => $user->getDisplayName(),
        'count'     => $count,
        'milestone' => $this->milestoneLabel($count),
      ];
    }
    return $rows;
  }

  /**
   * Returns monthly task completion counts for the last $months months.
   *
   * @return array[] Each row: ['label', 'count'] (e.g. "Mar 2026", 14)
   */
  protected function queryMonthlyTrend(string $table, int $months = 12): array {
    $since = mktime(0, 0, 0, (int) date('n') - $months + 1, 1, (int) date('Y'));
    $query = $this->database->select($table, 'f')
      ->condition('f.flag_id', 'task_completed')
      ->condition('f.created', $since, '>=');
    $query->addExpression('YEAR(FROM_UNIXTIME(f.created))', 'yr');
    $query->addExpression('MONTH(FROM_UNIXTIME(f.created))', 'mo');
    $query->addExpression('COUNT(*)', 'task_count');
    $query->groupBy('yr')->groupBy('mo')->orderBy('yr')->orderBy('mo');

    $rows = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $rows[] = [
        'label' => date('M Y', mktime(0, 0, 0, (int) $row->mo, 1, (int) $row->yr)),
        'count' => (int) $row->task_count,
      ];
    }
    return $rows;
  }

  /**
   * Returns task completion counts grouped by category (list field value).
   *
   * @return array[] Each row: ['category', 'count']
   */
  protected function queryCategoryBreakdown(string $table): array {
    if (!$this->database->schema()->tableExists('node__field_task_category')) {
      return [];
    }
    $query = $this->database->select($table, 'f')
      ->condition('f.flag_id', 'task_completed');
    $query->join('node__field_task_category', 'c', 'c.entity_id = f.entity_id AND c.deleted = 0');
    $query->addExpression('c.field_task_category_value', 'category');
    $query->addExpression('COUNT(*)', 'task_count');
    $query->groupBy('c.field_task_category_value')->orderBy('task_count', 'DESC');

    $rows = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $rows[] = [
        'category' => ucwords(str_replace('_', ' ', $row->category)),
        'count'    => (int) $row->task_count,
      ];
    }
    return $rows;
  }

  // ── Rendering ─────────────────────────────────────────────────────────────

  protected function renderPage(array $all_time, array $this_month, array $monthly, array $categories): string {
    $out = '<div class="task-leaderboard-page">';

    // Page intro.
    $log_url = Url::fromRoute('makehaven_tasks.retroactive_log')->toString();
    $out .= '<div class="task-lb-intro">'
      . '<p>Every completed task keeps MakeHaven running. Here are the community members '
      . 'who\'ve contributed the most. <a href="' . $log_url . '">Log something you fixed</a> · '
      . '<a href="/tasks">Browse open tasks</a></p>'
      . '</div>';

    // Two-column: all-time + this month.
    $out .= '<div class="task-lb-columns">';
    $out .= '<div class="task-lb-col">';
    $out .= '<h2 class="task-lb-section-title">🏆 All-Time</h2>';
    $out .= $this->renderRankingTable($all_time);
    $out .= '</div>';

    $out .= '<div class="task-lb-col">';
    $out .= '<h2 class="task-lb-section-title">📅 This Month</h2>';
    if (empty($this_month)) {
      $out .= '<p class="task-lb-empty">No completions yet this month — be first!</p>';
    }
    else {
      $out .= $this->renderRankingTable($this_month, medals: FALSE);
    }
    $out .= '</div>';
    $out .= '</div>'; // .task-lb-columns

    // Monthly trend chart.
    if (!empty($monthly)) {
      $out .= '<div class="task-lb-chart-section">';
      $out .= '<h2 class="task-lb-section-title">📈 Completions Per Month</h2>';
      $out .= $this->renderBarChart($monthly, 'count', 'label');
      $out .= '</div>';
    }

    // Category breakdown.
    if (!empty($categories)) {
      $out .= '<div class="task-lb-chart-section">';
      $out .= '<h2 class="task-lb-section-title">🔧 By Category</h2>';
      $out .= $this->renderBarChart($categories, 'count', 'category');
      $out .= '</div>';
    }

    $out .= '</div>'; // .task-leaderboard-page
    return $out;
  }

  /**
   * Renders a ranked table with optional medal icons for top 3.
   */
  protected function renderRankingTable(array $rows, bool $medals = TRUE): string {
    if (empty($rows)) {
      return '<p class="task-lb-empty">No data yet.</p>';
    }

    $max = $rows[0]['count'];
    $out = '<ol class="task-lb-table">';
    foreach ($rows as $rank => $row) {
      $rank1 = $rank + 1;
      $medal = '';
      if ($medals) {
        $medal = match ($rank1) {
          1 => '<span class="task-lb-medal task-lb-medal--gold">🥇</span>',
          2 => '<span class="task-lb-medal task-lb-medal--silver">🥈</span>',
          3 => '<span class="task-lb-medal task-lb-medal--bronze">🥉</span>',
          default => "<span class=\"task-lb-rank\">#{$rank1}</span>",
        };
      }
      else {
        $medal = "<span class=\"task-lb-rank\">#{$rank1}</span>";
      }

      $pct = $max > 0 ? round(($row['count'] / $max) * 100) : 0;
      $profile_url = Url::fromRoute('entity.user.canonical', ['user' => $row['uid']])->toString();
      $milestone_html = $row['milestone']
        ? ' <span class="task-lb-milestone">' . htmlspecialchars($row['milestone'], ENT_QUOTES, 'UTF-8') . '</span>'
        : '';

      $out .= '<li class="task-lb-row' . ($rank1 <= 3 ? ' task-lb-row--top3' : '') . '">';
      $out .= '<div class="task-lb-row-header">';
      $out .= $medal;
      $out .= '<a href="' . $profile_url . '" class="task-lb-name">'
        . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</a>';
      $out .= $milestone_html;
      $out .= '<span class="task-lb-count">' . $row['count'] . '</span>';
      $out .= '</div>';
      $out .= '<div class="task-lb-bar-wrap"><div class="task-lb-bar" style="width:' . $pct . '%"></div></div>';
      $out .= '</li>';
    }
    $out .= '</ol>';
    return $out;
  }

  /**
   * Renders a horizontal CSS bar chart from an array of rows.
   */
  protected function renderBarChart(array $rows, string $value_key, string $label_key): string {
    if (empty($rows)) {
      return '';
    }
    $max = max(array_column($rows, $value_key));
    $out = '<ul class="task-lb-hbar-chart">';
    foreach ($rows as $row) {
      $pct = $max > 0 ? round(($row[$value_key] / $max) * 100) : 0;
      $label = htmlspecialchars((string) $row[$label_key], ENT_QUOTES, 'UTF-8');
      $out .= '<li class="task-lb-hbar-row">';
      $out .= '<span class="task-lb-hbar-label">' . $label . '</span>';
      $out .= '<div class="task-lb-hbar-wrap">'
        . '<div class="task-lb-hbar" style="width:' . $pct . '%"></div>'
        . '<span class="task-lb-hbar-val">' . $row[$value_key] . '</span>'
        . '</div>';
      $out .= '</li>';
    }
    $out .= '</ul>';
    return $out;
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  /**
   * Returns a milestone tier label for a given task count, or empty string.
   */
  public static function milestoneLabel(int $count): string {
    return match (TRUE) {
      $count >= 100 => '🏆 Champion',
      $count >= 50  => '🥇 Gold',
      $count >= 25  => '🥈 Silver',
      $count >= 10  => '🥉 Bronze',
      default       => '',
    };
  }

}
