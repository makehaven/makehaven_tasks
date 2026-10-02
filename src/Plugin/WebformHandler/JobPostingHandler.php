<?php

namespace Drupal\makehaven_tasks\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Creates a job board posting from each completed Hire a Maker submission.
 *
 * @WebformHandler(
 *   id = "makehaven_job_posting",
 *   label = @Translation("Create a job board posting"),
 *   category = @Translation("MakeHaven"),
 *   description = @Translation("Turns the submission into a job_posting that staff approve at /admin/content/job-board; approving posts it to Slack #jobs."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class JobPostingHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Only the first completion: editing a submission later must not make a
    // second posting.
    if ($update || !$webform_submission->isCompleted()) {
      return;
    }
    try {
      \Drupal::service('makehaven_tasks.job_board')->createFromSubmission($webform_submission);
    }
    catch (\Throwable $e) {
      // Never lose the submission over this: it is saved, and the email to
      // info@ still goes out, so staff can post it by hand.
      $this->getLogger('makehaven_tasks')->error('Submission @sid did not become a posting: @error', [
        '@sid' => $webform_submission->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
