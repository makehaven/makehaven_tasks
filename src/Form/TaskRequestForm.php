<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Provides a limited task-request form for authenticated members.
 */
final class TaskRequestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_request';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $equipment = NULL;
    $equipment_nid = $this->getRequest()->query->get('equipment');
    if (is_numeric($equipment_nid)) {
      $candidate = Node::load((int) $equipment_nid);
      if ($candidate instanceof NodeInterface && $candidate->bundle() === 'item') {
        $equipment = $candidate;
      }
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Request a public task that another member can volunteer to complete. For damage or a safety concern, use the staff issue report instead.') . '</p>',
    ];
    $form['equipment'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Tool or area'),
      '#target_type' => 'node',
      '#selection_handler' => 'default:node',
      '#selection_settings' => [
        'target_bundles' => ['item'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
      ],
      '#default_value' => $equipment,
      '#required' => TRUE,
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What needs to be done?'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#placeholder' => $this->t('e.g. Clean the table-saw dust collector'),
    ];
    $form['details'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Details'),
      '#required' => TRUE,
      '#rows' => 5,
      '#description' => $this->t('Describe the work and anything a volunteer should know.'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create maintenance task'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = Node::create([
      'type' => 'task',
      'title' => trim((string) $form_state->getValue('title')),
      'body' => [
        'value' => trim((string) $form_state->getValue('details')),
        'format' => 'plain_text',
      ],
      'status' => NodeInterface::PUBLISHED,
      'uid' => $this->currentUser()->id(),
      'field_task_equipment' => ['target_id' => (int) $form_state->getValue('equipment')],
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
      'field_task_audience' => 'members',
    ]);
    $node->save();

    $this->messenger()->addStatus($this->t('Created maintenance task “@title”.', [
      '@title' => $node->label(),
    ]));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', [
      'node' => $node->id(),
    ]));
  }

}
