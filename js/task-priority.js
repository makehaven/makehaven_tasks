(function () {
  'use strict';

  // Priority color tagging.
  function tagPriority(el) {
    var t = (el.textContent || '').trim();
    if (t.charAt(0) === '1') el.setAttribute('data-priority', '1');
    else if (t.charAt(0) === '2') el.setAttribute('data-priority', '2');
    else if (t.charAt(0) === '3') el.setAttribute('data-priority', '3');
    else if (t.charAt(0) === '4') el.setAttribute('data-priority', '4');
    else if (t.toUpperCase().indexOf('N/A') !== -1) el.setAttribute('data-priority', 'na');
  }
  document.querySelectorAll('.views-field-field-task-priority, .task-badge--priority').forEach(tagPriority);

  // Card claim / status badges (injected from drupalSettings).
  var meta = (window.drupalSettings || {}).makehavenTasks;
  if (!meta || !meta.cardMeta) return;

  var rows = document.querySelectorAll('.view-tasks table tbody tr');
  rows.forEach(function (tr, index) {
    var data = meta.cardMeta[index];
    if (!data) return;

    // Thumbnail: prepend as a <td> so the existing .views-field-task-card-image
    // CSS rules (order, banner sizing, category tints) apply cleanly.
    if (data.image_html && !tr.querySelector('.views-field-task-card-image')) {
      var thumbTd = document.createElement('td');
      thumbTd.className = 'views-field views-field-task-card-image';
      thumbTd.innerHTML = data.image_html;
      tr.insertBefore(thumbTd, tr.firstChild);
    }

    var titleTd = tr.querySelector('.views-field-title');
    if (!titleTd) return;

    var html = '';
    if (data.status === 'in_progress' && data.claimer) {
      html += '<div class="task-card-claim">'
        + '<a href="' + data.claimer_url + '" class="task-face task-face--sm">'
        + '<span class="task-face__circle"><span class="task-face__initials">' + (data.initials || '') + '</span></span>'
        + '<span class="task-face__meta">'
        + '<span class="task-face__label">Claimed</span>'
        + '<span class="task-face__name">' + data.claimer + '</span>'
        + '</span></a></div>';
    } else if (data.status === 'incomplete') {
      html += '<div class="task-card-claim">'
        + '<span class="task-card-badge task-card-incomplete">⚠ Needs attention</span>'
        + '</div>';
    }

    if (data.est) {
      html += '<span class="task-card-badge task-card-time">⏱ ~' + data.est + '</span>';
    }

    if (html) {
      var div = document.createElement('div');
      div.className = 'task-card-extra';
      div.innerHTML = html;
      titleTd.appendChild(div);
    }
  });
})();
