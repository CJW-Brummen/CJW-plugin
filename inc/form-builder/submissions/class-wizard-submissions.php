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

    public function __construct()
    {
        $this->repository = new CJW_Wizard_Submission_Repository();
        $this->post_type = new CJW_Wizard_Submissions_Post_Type();
        $this->admin = new CJW_Wizard_Submission_Admin($this->repository);
        $this->exporter = new CJW_Wizard_Submission_Exporter($this->repository);
        $this->notifier = new CJW_Wizard_Submission_Notifier($this->repository);
    }

    public function init(): void
    {
        $this->post_type->init();
        $this->admin->init();
        $this->exporter->init();
    }

    public function save_submission(int $wizard_id, array $config, array $data): int
    {
        return $this->repository->save($wizard_id, $config, $data);
    }

    public function maybe_notify_admin(int $wizard_id, array $config, array $data): void
    {
        $this->notifier->maybe_notify_admin($wizard_id, $config, $data);
    }
}
