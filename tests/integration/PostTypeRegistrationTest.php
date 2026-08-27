<?php

declare(strict_types=1);

/**
 * The bug that started all of this.
 *
 * `cjw_wizard_submission` is 21 characters. register_post_type() caps names at
 * 20, so it returned a WP_Error every single time and the submissions screen
 * simply did not exist -- while a green suite reported otherwise, because the
 * stub could not fail the way WordPress does.
 */
final class PostTypeRegistrationTest extends CJW_IntegrationTestCase
{
    public function testTheSubmissionsPostTypeActuallyRegistered(): void
    {
        $type = get_post_type_object(CJW_Wizard_Submission_Repository::POST_TYPE);

        $this->assertInstanceOf(WP_Post_Type::class, $type);
    }

    public function testRegisteringItAgainWouldNotBeRejectedByWordPress(): void
    {
        // Asks WordPress itself, rather than asserting the length and hoping
        // that is the only rule.
        $result = register_post_type('cjw_probe_' . substr(CJW_Wizard_Submission_Repository::POST_TYPE, 11), []);

        $this->assertNotWPError($result);

        // Rejecting the name is the point of the test, and WordPress announces
        // it with _doing_it_wrong() as well as a WP_Error. Undeclared, the
        // suite would fail the test for the very notice it is asserting.
        $this->setExpectedIncorrectUsage('register_post_type');

        $tooLong = register_post_type('cjw_wizard_submission', []);

        $this->assertWPError($tooLong);
        $this->assertSame('post_type_length_invalid', $tooLong->get_error_code());
    }

    public function testEveryCustomStatusIsRegistered(): void
    {
        foreach (array_keys(CJW_Wizard_Submission_Status::all()) as $status) {
            $this->assertInstanceOf(stdClass::class, get_post_status_object($status), $status);
        }
    }

    public function testARegistrationCannotBeCreatedByHand(): void
    {
        $this->actAsAdministrator();

        $type = get_post_type_object(CJW_Wizard_Submission_Repository::POST_TYPE);

        // A submission is what a parent filled in; there is nothing coherent to
        // create from an empty form.
        $this->assertFalse(current_user_can($type->cap->create_posts));
    }
}
