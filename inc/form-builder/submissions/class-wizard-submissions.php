<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Submissions
{
    private CJW_Wizard_Submissions_Post_Type $post_type;
    private CJW_Wizard_Submission_Repository $repository;
    private CJW_Wizard_Submission_Admin $admin;
    private CJW_Wizard_Submission_Exporter $exporter;
    private CJW_Wizard_Submission_Notifier $notifier;
    private CJW_Wizard_Submission_Privacy $privacy;
    private CJW_Wizard_Submission_Retention $retention;
    private CJW_Wizard_Year_Rollover $rollover;
    private CJW_Wizard_Submission_Digest $digest;
    private CJW_Wizard_Birthday_Mailer $birthdays;
    private CJW_Wizard_Submission_Status $status;
    private CJW_Wizard_Submission_Confirmation $confirmation;
    private CJW_Wizard_Children_Page $children;

    public function __construct()
    {
        $this->repository = new CJW_Wizard_Submission_Repository();
        $this->post_type = new CJW_Wizard_Submissions_Post_Type();
        $this->admin = new CJW_Wizard_Submission_Admin($this->repository);
        $this->exporter = new CJW_Wizard_Submission_Exporter($this->repository);
        $this->notifier = new CJW_Wizard_Submission_Notifier();
        $this->privacy = new CJW_Wizard_Submission_Privacy($this->repository);
        $this->retention = new CJW_Wizard_Submission_Retention($this->repository);
        $this->rollover = new CJW_Wizard_Year_Rollover($this->repository);
        $this->digest = new CJW_Wizard_Submission_Digest($this->repository);
        $this->birthdays = new CJW_Wizard_Birthday_Mailer($this->repository);
        $this->status = new CJW_Wizard_Submission_Status();
        $this->confirmation = new CJW_Wizard_Submission_Confirmation();
        $this->children = new CJW_Wizard_Children_Page($this->repository);
    }

    public function init(): void
    {
        $this->post_type->init();
        $this->admin->init();
        $this->exporter->init();
        $this->privacy->init();
        $this->retention->init();
        $this->rollover->init();
        $this->digest->init();
        $this->birthdays->init();
        $this->status->init();
        $this->children->init();
    }

    /**
     * Stores a completed registration.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function save_submission(int $wizard_id, array $config, array $data): int
    {
        return $this->repository->save($wizard_id, $config, $data);
    }

    /**
     * Tells the organiser a registration arrived, if the form asks for it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function maybe_notify_admin(int $wizard_id, array $config, array $data, int $submission_id = 0): void
    {
        $this->notifier->maybe_notify_admin($wizard_id, $config, $data, $submission_id);
    }

    /**
     * Confirms the registration to the parent, if the form asks for it.
     *
     * @param int   $submission_id Submission post id.
     * @param int   $wizard_id     Form post id.
     * @param array $config        Form configuration.
     * @param array $data          Submitted payload.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data
     */
    public function maybe_confirm_to_parent(int $submission_id, int $wizard_id, array $config, array $data): string
    {
        if ($submission_id < 1) {
            return '';
        }

        return $this->confirmation->maybe_send($submission_id, $wizard_id, $config, $data);
    }
}
