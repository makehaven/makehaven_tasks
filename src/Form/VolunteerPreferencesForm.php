<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Volunteer\Preferences;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "How would you like to help?" (/tasks/preferences).
 *
 * Replaces the old outreach volunteer webform. The answers mark matching
 * opportunities "For you" on the board, optionally email the person when a
 * matching one is posted, and list them in the staff volunteer pool for the
 * outreach that is not a shift (press, sponsors, flyers).
 */
final class VolunteerPreferencesForm extends FormBase {

  public function __construct(private readonly Preferences $preferences) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('makehaven_tasks.volunteer_preferences'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_volunteer_preferences';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'makehaven_tasks/task_actions';
    $prefs = $this->preferences->get((int) $this->currentUser()->id());
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Tell us what you enjoy and we will point out opportunities that suit you on the <a href=":board">volunteer board</a>. Nothing here signs you up for anything.', [':board' => Url::fromRoute('view.tasks.page_tasks_interactive')->toString()]) . '</p>',
    ];
    $form['ways'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('I would like to'),
      '#options' => Preferences::ways(),
      '#default_value' => $prefs['ways'],
    ];
    $form['availability'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('I am usually free'),
      '#options' => Preferences::availability(),
      '#default_value' => $prefs['availability'],
    ];
    $form['connections'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Groups, businesses or people you could connect us with (optional)'),
      '#description' => $this->t('A neighborhood group, your workplace, a reporter, a possible sponsor or funder, a meetup topic you would host.'),
      '#rows' => 3,
      '#default_value' => $prefs['connections'],
    ];
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when a new opportunity matches what I ticked'),
      '#description' => $this->t('One email per opportunity, only for tours, tabling, events, fixing or cleaning. Untick any time.'),
      '#default_value' => $prefs['notify'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->preferences->set((int) $this->currentUser()->id(), [
      'ways' => array_keys(array_filter((array) $form_state->getValue('ways'))),
      'availability' => array_keys(array_filter((array) $form_state->getValue('availability'))),
      'connections' => (string) $form_state->getValue('connections'),
      'notify' => (bool) $form_state->getValue('notify'),
    ]);
    $this->messenger()->addStatus($this->t('Thanks! Opportunities that suit you are marked ★ For you on the board.'));
    $form_state->setRedirect('view.tasks.page_tasks_interactive');
  }

}
