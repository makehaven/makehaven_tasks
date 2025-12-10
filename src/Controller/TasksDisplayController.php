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
        padding: 20px;
        background-color: #121212;
        color: #e0e0e0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        overflow: hidden; /* Hide scrollbars for clean display */
      }
      #tasks-container {
        height: 100vh;
        display: flex;
        flex-direction: column;
      }
      
      /* View specific overrides */
      .view-tasks {
        width: 100%;
      }
      
      /* Headers */
      h1, h2, h3 {
        color: #ffffff;
        margin-top: 0;
      }
      
      /* Task List Styles */
      .views-row {
        background-color: #1e1e1e;
        border: 1px solid #333;
        border-radius: 8px;
        margin-bottom: 15px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.3);
      }
      
      .views-field-title {
        font-size: 1.8rem;
        font-weight: bold;
        color: #90caf9;
        margin-bottom: 10px;
      }
      
      .views-field-body {
        font-size: 1.2rem;
        color: #b0bec5;
        line-height: 1.5;
      }
      
      /* Priorities */
      .views-field-field-task-priority .field-content {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 4px;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 0.9rem;
      }
      
      /* Custom badge colors could be added here if classes exist */
      
      /* Footer / Recently Completed */
      .attachment-after {
        margin-top: 40px;
        border-top: 2px solid #444;
        padding-top: 20px;
      }
      
      .attachment-after h2 {
        color: #81c784; /* Green for completed */
      }

      /* Loading indicator */
      #loading {
        position: fixed;
        top: 20px;
        right: 20px;
        color: #666;
        font-size: 0.8rem;
        opacity: 0;
        transition: opacity 0.5s;
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

    $view->setDisplay('page_tasks_display');
    $render_array = $view->render();
    
    // Render the render array to HTML string
    $html = \Drupal::service('renderer')->renderRoot($render_array);

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