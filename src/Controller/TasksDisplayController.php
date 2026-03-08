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
   * @param string $code_word
   *   The secret code word for access.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The rendered page.
   */
  public function displayPage(string $code_word) {
    $this->checkAccess($code_word);

    $refresh_url = "/display/tasks/fragment/{$code_word}";

    // CSS for Dark Mode / TV Display
    $css = "
      body {
        margin: 0;
        padding: 0;
        background-color: #0a0a0a;
        color: #f0f0f0;
        font-family: 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        overflow: hidden;
      }
      #tasks-container {
        height: 100vh;
        display: flex;
        flex-direction: column;
        padding: 30px;
        box-sizing: border-box;
      }
      
      .view-tasks {
        width: 100%;
      }
      
      /* Main Task List */
      .view-display-id-page_tasks_display .view-content {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
      }
      
      .view-display-id-page_tasks_display .views-row {
        background: linear-gradient(145deg, #1e1e1e, #161616);
        border: 1px solid #333;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 8px 16px rgba(0,0,0,0.5);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
      }
      
      .view-display-id-page_tasks_display .views-row::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background-color: #2196f3;
      }
      
      .view-display-id-page_tasks_display .views-field-title {
        font-size: 2.2rem;
        font-weight: 800;
        color: #fff;
        line-height: 1.2;
        margin-bottom: 15px;
      }
      
      .view-display-id-page_tasks_display .views-field-field-task-equipment {
        font-size: 1.1rem;
        color: #aaa;
        font-style: italic;
      }
      
      .badge {
        font-size: 0.9rem;
        padding: 6px 12px;
        border-radius: 20px;
        background-color: #333;
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: 10px;
        display: inline-block;
      }
      
      /* Recently Completed Section */
      .attachment-after {
        margin-top: auto;
        padding-top: 30px;
        border-top: 1px solid #333;
      }
      
      .attachment-after::before {
        content: 'Recently Completed';
        display: block;
        font-size: 1.8rem;
        font-weight: 700;
        color: #4caf50;
        margin-bottom: 20px;
        text-transform: uppercase;
        letter-spacing: 2px;
      }
      
      .view-display-id-attachment_recently_completed .view-content {
        display: flex;
        flex-direction: row;
        gap: 20px;
        overflow-x: auto;
      }
      
      .view-display-id-attachment_recently_completed .views-row {
        background-color: #161616;
        border: 1px solid #222;
        border-radius: 10px;
        padding: 15px;
        min-width: 250px;
        display: flex;
        align-items: center;
        gap: 15px;
      }
      
      .profile-photo img {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #4caf50;
      }
      
      .view-display-id-attachment_recently_completed .views-field-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #eee;
      }
      
      .view-display-id-attachment_recently_completed .views-field-uid {
        font-size: 0.9rem;
        color: #888;
      }

      /* Pager - Hide on signage */
      .pager, .pagination {
        display: none !important;
      }

      /* Loading indicator */
      #loading {
        position: fixed;
        bottom: 20px;
        right: 20px;
        color: #444;
        font-size: 0.7rem;
        opacity: 0;
        transition: opacity 0.5s;
        z-index: 100;
      }
      #loading.active {
        opacity: 1;
      }
    ";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tasks Display</title>
  <style>{$css}</style>
</head>
<body>
  <div id="loading">Updating...</div>
  <div id="tasks-container">
    <!-- Content will be injected here -->
    <div style="text-align:center; padding-top: 20%; color: #666;">
      <h2>Loading Tasks...</h2>
    </div>
  </div>

  <script>
    (function() {
      const container = document.getElementById('tasks-container');
      const loader = document.getElementById('loading');
      const url = '{$refresh_url}';

      async function updateContent() {
        loader.classList.add('active');
        try {
          const response = await fetch(url);
          if (response.ok) {
            const html = await response.text();
            container.innerHTML = html;
          } else {
            console.error('Failed to fetch tasks:', response.status);
          }
        } catch (error) {
          console.error('Error fetching tasks:', error);
        } finally {
          setTimeout(() => loader.classList.remove('active'), 500);
        }
      }

      // Initial load
      updateContent();

      // Refresh every 60 seconds
      setInterval(updateContent, 60000);
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
      
      // Render the render array to HTML string
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