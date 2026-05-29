<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\views\Views;

/**
 * Controller for the tasks display page.
 */
class TasksDisplayController extends ControllerBase {

  /**
   * Renders the tasks display page (full HTML).
   *
   * Designed for a portrait signage screen in the space. It frames the open
   * task list as a member call-to-action ("grab a task"), ranks tasks by
   * priority client-side, and auto-rotates through pages so every task gets
   * airtime. A compact "recently completed" strip is pinned at the bottom.
   *
   * @param string $code_word
   *   The secret code word for access.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The rendered page.
   */
  public function displayPage(string $code_word) {
    $this->checkAccess($code_word);

    $refresh_url = "/display/tasks/fragment/{$code_word}";

    // Brand-aligned palette. Values mirror the canonical design tokens
    // (STYLE.md / tokens.css); this standalone signage page cannot load the
    // theme stylesheet, so the values are inlined here intentionally. Do not
    // reintroduce Material green/blue (#4caf50 / #2196f3) — see STYLE.md.
    $css = <<<'CSS'
      :root {
        --mh-red: #8b1919;
        --mh-red-muted: #b41e1e;
        --mh-gold: #ffc658;
        --mh-warning: #ffb347;
        --mh-info: #4ea1d3;
        --mh-success: #1f8c5b;
        --bg: #0d0d0f;
        --surface: #17171a;
        --surface-2: #1f1f23;
        --border: #2c2c31;
        --text: #f4f4f5;
        --muted: #a1a1aa;
      }
      * { box-sizing: border-box; }
      html, body {
        margin: 0;
        padding: 0;
        height: 100%;
        background: var(--bg);
        color: var(--text);
        font-family: 'Montserrat', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
        overflow: hidden;
      }

      #stage {
        height: 100vh;
        display: flex;
        flex-direction: column;
      }

      /* ---- Header band ---- */
      #stage-header {
        flex: 0 0 auto;
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        padding: 22px 32px 18px;
        border-bottom: 4px solid var(--mh-red);
        background: linear-gradient(180deg, #161618, var(--bg));
      }
      #stage-header .title {
        font-family: 'Roboto Condensed', 'Montserrat', sans-serif;
        font-size: 2.4rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin: 0;
      }
      #stage-header .title small {
        display: block;
        font-size: 1.05rem;
        font-weight: 500;
        letter-spacing: 1px;
        color: var(--muted);
        text-transform: none;
        margin-top: 4px;
      }
      #stage-header .clock {
        text-align: right;
        color: var(--muted);
      }
      #stage-header .clock .time {
        font-size: 1.9rem;
        font-weight: 700;
        color: var(--text);
        font-variant-numeric: tabular-nums;
      }
      #stage-header .clock .date { font-size: 1rem; }

      /* ---- Task viewport ---- */
      #tasks-viewport {
        flex: 1 1 auto;
        overflow: hidden;
        padding: 20px 32px;
        position: relative;
      }
      #tasks-container { height: 100%; }
      .boot-msg {
        text-align: center;
        padding-top: 18%;
        color: #555;
        font-size: 1.6rem;
      }

      /* Hide the view's own chrome — we re-frame it ourselves. */
      .view-tasks .view-header,
      .view-tasks .view-footer,
      .view-tasks .view-empty,
      .pager, .pagination { display: none !important; }

      .view-display-id-page_tasks_display .view-content {
        display: flex;
        flex-direction: column;
        gap: 14px;
      }

      .view-display-id-page_tasks_display .views-row {
        background: linear-gradient(145deg, var(--surface-2), var(--surface));
        border: 1px solid var(--border);
        border-left: 6px solid var(--muted);
        border-radius: 12px;
        padding: 16px 22px;
        box-shadow: 0 6px 14px rgba(0,0,0,0.45);
      }
      /* Priority accent + chip color */
      .views-row.prio-1 { border-left-color: var(--mh-red-muted); }
      .views-row.prio-2 { border-left-color: var(--mh-warning); }
      .views-row.prio-3 { border-left-color: var(--mh-info); }
      .views-row.prio-4 { border-left-color: var(--mh-success); }
      .views-row.prio-none { border-left-color: var(--border); }

      .view-display-id-page_tasks_display .task-title {
        font-family: 'Roboto Condensed', 'Montserrat', sans-serif;
        font-size: 2.75rem;
        font-weight: 800;
        color: #fff;
        line-height: 1.1;
        margin: 0 0 10px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      .task-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 18px;
        font-size: 1.05rem;
        color: var(--muted);
      }
      .task-meta .chip {
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        padding: 4px 12px;
        border-radius: 999px;
        color: #0d0d0f;
        background: var(--muted);
      }
      .prio-1 .chip { background: var(--mh-red-muted); color: #fff; }
      .prio-2 .chip { background: var(--mh-warning); }
      .prio-3 .chip { background: var(--mh-info); color: #0d0d0f; }
      .prio-4 .chip { background: var(--mh-success); color: #fff; }
      .prio-none .chip { background: #3a3a40; color: var(--muted); }

      .task-meta .meta-item { display: inline-flex; align-items: center; gap: 6px; }
      .task-meta .meta-item .lbl { color: #6f6f78; }
      .task-meta .meta-item b { color: var(--text); font-weight: 600; }
      /* Drupal's per-field wrappers are flattened into .task-meta by JS,
         but hide any stragglers so a slow render never shows raw labels. */
      .view-display-id-page_tasks_display .views-row > .views-field { display: none; }

      /* ---- Page indicator ---- */
      #page-indicator {
        position: absolute;
        right: 32px;
        bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.95rem;
        color: var(--muted);
      }
      #page-indicator .dots { display: flex; gap: 6px; }
      #page-indicator .dot {
        width: 9px; height: 9px; border-radius: 50%;
        background: #3a3a40;
      }
      #page-indicator .dot.active { background: var(--mh-gold); }

      /* ---- Recently completed strip ---- */
      #completed-strip {
        flex: 0 0 auto;
        border-top: 1px solid var(--border);
        background: #121214;
        padding: 14px 32px 18px;
      }
      #completed-strip h3 {
        margin: 0 0 10px;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: var(--mh-success);
      }
      #completed-strip .strip-rows {
        display: flex;
        gap: 26px;
        overflow: hidden;
      }
      #completed-strip .views-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 0 0 auto;
        max-width: 320px;
      }
      #completed-strip .views-field { display: contents; }
      #completed-strip img {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--mh-success);
        flex: 0 0 auto;
      }
      #completed-strip .views-field-field-member-photo:empty + * ,
      #completed-strip .ct-text { display: block; min-width: 0; }
      #completed-strip .ct-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #eee;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      #completed-strip .ct-sub {
        font-size: 0.9rem;
        color: var(--muted);
      }
      #completed-strip.empty { display: none; }

      /* ---- Loading dot ---- */
      #loading {
        position: fixed;
        bottom: 10px;
        left: 14px;
        color: #3a3a40;
        font-size: 0.7rem;
        opacity: 0;
        transition: opacity 0.4s;
        z-index: 100;
      }
      #loading.active { opacity: 1; }
CSS;

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MakeHaven — Grab a Task</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;800&family=Roboto+Condensed:wght@700;800&display=swap" rel="stylesheet">
  <style>{$css}</style>
</head>
<body>
  <div id="loading">Updating…</div>
  <div id="stage">
    <header id="stage-header">
      <h1 class="title">Help Out — Grab a Task<small>See something you can do? Find the lead and jump in.</small></h1>
      <div class="clock"><div class="time" id="clock-time">—</div><div class="date" id="clock-date"></div></div>
    </header>
    <main id="tasks-viewport">
      <div id="tasks-container"><div class="boot-msg">Loading tasks…</div></div>
      <div id="page-indicator" hidden><span id="page-label"></span><span class="dots" id="page-dots"></span></div>
    </main>
    <footer id="completed-strip" class="empty">
      <h3>Recently Completed — Thank You</h3>
      <div class="strip-rows" id="completed-rows"></div>
    </footer>
  </div>

  <script>
  (function () {
    var container = document.getElementById('tasks-container');
    var viewport = document.getElementById('tasks-viewport');
    var loader = document.getElementById('loading');
    var indicator = document.getElementById('page-indicator');
    var pageLabel = document.getElementById('page-label');
    var pageDots = document.getElementById('page-dots');
    var completedStrip = document.getElementById('completed-strip');
    var completedRows = document.getElementById('completed-rows');
    var url = '{$refresh_url}';

    var PRIO_LABEL = { 1: 'Urgent', 2: 'Medium', 3: 'Low', 4: 'Someday' };
    var ROTATE_MS = 20000;
    var REFRESH_MS = 60000;

    var pages = [];
    var pageIndex = 0;
    var rotateTimer = null;

    function clock() {
      var now = new Date();
      var t = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      var d = now.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
      document.getElementById('clock-time').textContent = t;
      document.getElementById('clock-date').textContent = d;
    }

    function textOf(row, sel) {
      var el = row.querySelector(sel);
      return el ? el.textContent.trim().replace(/\s+/g, ' ') : '';
    }

    // Rebuild a verbose Drupal row into a compact card.
    function reframe(row) {
      var title = textOf(row, '.views-field-title');
      var prioRaw = textOf(row, '.views-field-field-task-priority');
      var equip = textOf(row, '.views-field-field-task-equipment');
      var lead = textOf(row, '.views-field-field-task-lead');
      var hoursRaw = textOf(row, '.views-field-field-task-estimated-hours');

      var pm = prioRaw.match(/[1-4]/);
      var prio = pm ? parseInt(pm[0], 10) : 0;
      row.dataset.prio = prio || 99;
      row.className = 'views-row ' + (prio ? 'prio-' + prio : 'prio-none');

      var meta = [];
      meta.push('<span class="chip">' + (prio ? PRIO_LABEL[prio] : 'Unprioritized') + '</span>');
      if (equip) {
        meta.push('<span class="meta-item"><span class="lbl">On</span> <b>' + esc(equip) + '</b></span>');
      }
      var hrs = parseFloat(hoursRaw);
      if (!isNaN(hrs) && hrs > 0) {
        var h = hrs % 1 === 0 ? hrs.toFixed(0) : hrs.toFixed(1);
        meta.push('<span class="meta-item"><span class="lbl">~</span> <b>' + h + ' hr' + (hrs == 1 ? '' : 's') + '</b></span>');
      }
      if (lead) {
        meta.push('<span class="meta-item"><span class="lbl">Talk to</span> <b>' + esc(lead) + '</b></span>');
      }

      row.innerHTML =
        '<h2 class="task-title">' + esc(title) + '</h2>' +
        '<div class="task-meta">' + meta.join('') + '</div>';
    }

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    function rebuildCompleted(scope) {
      var src = scope.querySelector('.attachment-after, .view-display-id-attachment_recently_completed');
      completedRows.innerHTML = '';
      var rows = src ? src.querySelectorAll('.views-row') : [];
      if (!rows.length) {
        completedStrip.classList.add('empty');
        return;
      }
      Array.prototype.forEach.call(rows, function (r) {
        var img = r.querySelector('img');
        var task = textOf(r, '.views-field-title');
        var who = textOf(r, '.views-field-uid');
        var ago = textOf(r, '.views-field-timestamp');
        var item = document.createElement('div');
        item.className = 'views-row';
        item.innerHTML =
          (img ? '<img src="' + img.getAttribute('src') + '" alt="">' : '') +
          '<div class="ct-text"><div class="ct-title">' + esc(task) + '</div>' +
          '<div class="ct-sub">' + esc(who) + (ago ? ' · ' + esc(ago) : '') + '</div></div>';
        completedRows.appendChild(item);
      });
      completedStrip.classList.remove('empty');
    }

    // Split sorted rows into pages that fit the viewport height.
    function paginate(content) {
      var rows = Array.prototype.slice.call(content.querySelectorAll('.views-row'));
      pages = [];
      if (!rows.length) {
        indicator.hidden = true;
        return;
      }
      // Show all briefly to measure, then chunk by cumulative height.
      rows.forEach(function (r) { r.style.display = ''; });
      var avail = viewport.clientHeight - 24;
      var gap = 14;
      var page = [];
      var used = 0;
      rows.forEach(function (r) {
        var h = r.getBoundingClientRect().height + gap;
        if (page.length && used + h > avail) {
          pages.push(page);
          page = [];
          used = 0;
        }
        page.push(r);
        used += h;
      });
      if (page.length) pages.push(page);

      pageDots.innerHTML = '';
      pages.forEach(function () {
        var d = document.createElement('span');
        d.className = 'dot';
        pageDots.appendChild(d);
      });
      indicator.hidden = pages.length < 2;
      if (pageIndex >= pages.length) pageIndex = 0;
      showPage(pageIndex);
    }

    function showPage(i) {
      if (!pages.length) return;
      pageIndex = (i + pages.length) % pages.length;
      pages.forEach(function (pg, idx) {
        pg.forEach(function (r) { r.style.display = idx === pageIndex ? '' : 'none'; });
      });
      var total = pages.reduce(function (n, p) { return n + p.length; }, 0);
      var start = 0;
      for (var k = 0; k < pageIndex; k++) start += pages[k].length;
      pageLabel.textContent = 'Tasks ' + (start + 1) + '–' + (start + pages[pageIndex].length) +
        ' of ' + total + '  ·  auto-rotating';
      Array.prototype.forEach.call(pageDots.children, function (d, idx) {
        d.className = 'dot' + (idx === pageIndex ? ' active' : '');
      });
    }

    function startRotation() {
      if (rotateTimer) clearInterval(rotateTimer);
      if (pages.length < 2) return;
      rotateTimer = setInterval(function () { showPage(pageIndex + 1); }, ROTATE_MS);
    }

    function process(html) {
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      rebuildCompleted(tmp);
      var pageDisplay = tmp.querySelector('.view-display-id-page_tasks_display');
      var contentSrc = pageDisplay
        ? pageDisplay.querySelector('.view-content')
        : tmp.querySelector('.view-content');

      container.innerHTML = '';
      if (!contentSrc || !contentSrc.querySelector('.views-row')) {
        container.innerHTML = '<div class="boot-msg">No open tasks right now. Nice work! 🎉</div>';
        indicator.hidden = true;
        return;
      }
      var wrap = document.createElement('div');
      wrap.className = 'view-tasks view-display-id-page_tasks_display';
      var content = document.createElement('div');
      content.className = 'view-content';
      wrap.appendChild(content);
      container.appendChild(wrap);

      var rows = Array.prototype.slice.call(contentSrc.querySelectorAll('.views-row'));
      rows.forEach(reframe);
      rows.sort(function (a, b) {
        return (parseInt(a.dataset.prio, 10)) - (parseInt(b.dataset.prio, 10));
      });
      rows.forEach(function (r) { content.appendChild(r); });

      paginate(content);
      startRotation();
    }

    function update() {
      loader.classList.add('active');
      fetch(url)
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(function (html) { process(html); })
        .catch(function (e) { console.error('Tasks display fetch failed:', e); })
        .finally(function () {
          setTimeout(function () { loader.classList.remove('active'); }, 500);
        });
    }

    clock();
    setInterval(clock, 30000);
    update();
    setInterval(update, REFRESH_MS);
    // Re-paginate on resize / orientation change.
    var rz;
    window.addEventListener('resize', function () {
      clearTimeout(rz);
      rz = setTimeout(function () {
        var content = container.querySelector('.view-content');
        if (content) { paginate(content); startRotation(); }
      }, 400);
    });
  })();
  </script>
</body>
</html>
HTML;

    return new Response($html);
  }

  /**
   * Returns the rendered HTML of the view (fragment).
   *
   * @param string $code_word
   *   The secret code word.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTML response.
   */
  public function displayFragment(string $code_word) {
    $this->checkAccess($code_word);

    $view = Views::getView('tasks');
    if (!$view) {
      return new Response('View not found.', 404);
    }

    // Switch to admin account to ensure all data (like photos) is visible.
    $accountSwitcher = \Drupal::service('account_switcher');
    $admin_user = \Drupal\user\Entity\User::load(1);
    $accountSwitcher->switchTo($admin_user);

    try {
      $view->setDisplay('page_tasks_display');
      $render_array = $view->render();

      // Render the render array to HTML string.
      $html = \Drupal::service('renderer')->renderRoot($render_array);
    }
    finally {
      // Always switch back.
      $accountSwitcher->switchBack();
    }

    return new Response($html);
  }

  /**
   * Helper to check code word access.
   */
  protected function checkAccess($code_word) {
    $config_code_word = $this->config('makehaven_tasks.settings')->get('code_word');
    if (!$config_code_word || $code_word !== $config_code_word) {
      throw new AccessDeniedHttpException();
    }
  }

}
