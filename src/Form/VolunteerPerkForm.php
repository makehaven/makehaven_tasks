<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makehaven_tasks\Volunteer\Perks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One row's buttons on the thank-yous page: Given, Skip, or No-show.
 *
 * "Given" for a t-shirt or hoodie also takes one off the store's shelf count
 * (reason "Volunteer appreciation"). "No-show" takes that day out of every
 * count, including the hoodie tally.
 */
final class VolunteerPerkForm extends FormBase {

  /**
   * Which row this instance renders (the form is built once per row).
   */
  private string $rowKey = '';

  public function __construct(private readonly Perks $perks) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('makehaven_tasks.volunteer_perks'));
  }

  /**
   * Sets the row (so each row's form has its own id).
   */
  public function setRow(array $row): static {
    $this->rowKey = $row['uid'] . '-' . $row['perk'] . '-' . ($row['ref'] ?: 'once');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_volunteer_perk_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($this->rowKey));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $row = []): array {
    $form['#attributes']['class'][] = 'vol-perk-form';
    $form_state->set('row', $row);
    $placeholder = match ($row['perk'] ?? '') {
      Perks::TSHIRT, Perks::HOODIE => $this->t('Size'),
      Perks::LUNCH => $this->t('How (cash, Venmo...)'),
      default => $this->t('Note'),
    };
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note'),
      '#title_display' => 'invisible',
      '#size' => 14,
      '#maxlength' => 255,
      '#placeholder' => $placeholder,
    ];
    $form['given'] = [
      '#type' => 'submit',
      '#value' => $this->t('Given'),
      '#name' => 'given_' . $this->rowKey,
      '#button_type' => 'primary',
      '#submit' => ['::given'],
    ];
    $form['skip'] = [
      '#type' => 'submit',
      '#value' => $this->t('Skip'),
      '#name' => 'skip_' . $this->rowKey,
      '#submit' => ['::skip'],
    ];
    $form['no_show'] = [
      '#type' => 'submit',
      '#value' => $this->t("Didn't come"),
      '#name' => 'noshow_' . $this->rowKey,
      '#submit' => ['::noShow'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Handed out.
   */
  public function given(array &$form, FormStateInterface $form_state): void {
    $row = $form_state->get('row');
    $result = $this->perks->record((int) $row['uid'], (string) $row['perk'], (string) $row['ref'], (int) $row['nid'], $this->currentUser(), 'given', (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('Recorded: @perk given.', ['@perk' => Perks::label((string) $row['perk'])]) . ($result !== '' ? ' ' . $result : ''));
    $form_state->setRedirect('makehaven_tasks.perks');
  }

  /**
   * Not handing it out (already has one, declined it).
   */
  public function skip(array &$form, FormStateInterface $form_state): void {
    $row = $form_state->get('row');
    $this->perks->record((int) $row['uid'], (string) $row['perk'], (string) $row['ref'], (int) $row['nid'], $this->currentUser(), 'skipped', (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('Skipped.'));
    $form_state->setRedirect('makehaven_tasks.perks');
  }

  /**
   * They did not come that day.
   */
  public function noShow(array &$form, FormStateInterface $form_state): void {
    $row = $form_state->get('row');
    $this->perks->noShow((int) $row['uid'], (string) $row['day'], (int) $row['nid'], $this->currentUser());
    $this->messenger()->addStatus($this->t('Marked as a no-show for @day; that day no longer counts toward thank-yous.', ['@day' => $row['day']]));
    $form_state->setRedirect('makehaven_tasks.perks');
  }

}
