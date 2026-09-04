<?php

declare(strict_types=1);

/**
 * The parent's address as something the form declares rather than something
 * the plugin guesses.
 *
 * The exporter and the eraser look registrations up by the address stored at
 * submission time, so the guess made there decided whose data an AVG request
 * returned. Integration because the stored meta and the Site Health check both
 * go through WordPress.
 */
final class ParentEmailRoleTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    public function set_up(): void
    {
        parent::set_up();

        $this->repository = new CJW_Wizard_Submission_Repository();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function config(array $fields): array
    {
        return [ 'steps' => [ [ 'title' => 'Contact', 'fields' => $fields ] ] ];
    }

    private function form(array $config, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => $status,
            'post_title' => 'Inschrijfformulier',
        ]);

        update_post_meta($id, '_cjw_wizard_config', (string) wp_json_encode($config));

        return $id;
    }

    public function testTheStoredAddressIsTheOneTheFormMarksAsTheParents(): void
    {
        $config = $this->config([
            [ 'key' => 'noodcontact', 'type' => 'email', 'label' => 'Noodcontact' ],
            [ 'key' => 'ouder', 'type' => 'email', 'label' => 'Ouder', 'role' => 'parent_email' ],
        ]);
        $wizard = $this->form($config);

        $id = $this->repository->save($wizard, $config, [
            'noodcontact' => 'oma@example.test',
            'ouder' => 'ouder@example.test',
        ]);

        $this->assertSame('ouder@example.test', $this->repository->get_email($id));
        $this->assertCount(1, $this->repository->get_by_email('ouder@example.test'));
        $this->assertSame([], $this->repository->get_by_email('oma@example.test'), 'an AVG request from grandma returns nothing');
    }

    public function testSiteHealthNamesAPublishedFormWithoutTheRole(): void
    {
        $this->form($this->config([ [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ] ]));

        $result = (new CJW_Summer_Camp_Site_Health())->testParentEmailRole();

        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('Inschrijfformulier', $result['description']);
    }

    public function testSiteHealthIsGoodWhenEveryPublishedFormMarksTheField(): void
    {
        $this->form($this->config([ [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'role' => 'parent_email' ] ]));
        // A draft is somebody's work in progress, not a form parents can reach.
        $this->form($this->config([ [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mail' ] ]), 'draft');

        $result = (new CJW_Summer_Camp_Site_Health())->testParentEmailRole();

        $this->assertSame('good', $result['status']);
    }

    public function testTheNormaliserKeepsTheRole(): void
    {
        $normalized = CJW_Wizard_Config_Normalizer::normalize($this->config([
            [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mail', 'role' => 'parent_email' ],
        ]));

        $fields = array_values($normalized['steps'])[0]['fields'];

        $this->assertSame('parent_email', $fields[0]['role']);
    }
}
