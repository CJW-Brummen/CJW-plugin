<?php

declare(strict_types=1);

/**
 * Guards the constraint that broke the submissions screen for the whole life of
 * the form builder: WordPress rejects a post type name longer than 20
 * characters, and register_post_type() reports it by returning a WP_Error that
 * nothing was checking.
 */
final class PostTypeNameTest extends CJW_TestCase
{
    public function testSubmissionPostTypeFitsWordPressLengthLimit(): void
    {
        $name = CJW_Wizard_Submission_Repository::POST_TYPE;

        $this->assertLessThanOrEqual(
            20,
            strlen($name),
            "Post type '{$name}' is " . strlen($name) . ' characters. WordPress caps'
            . ' post type names at 20 and register_post_type() fails silently above it.'
        );
    }

    public function testSubmissionPostTypeUsesTheCharactersWordPressAllows(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9_-]+$/',
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'Post type names may only contain lowercase letters, numbers, underscores and dashes.'
        );
    }

    public function testSubmissionPostTypeIsNotTheBrokenNameItReplaced(): void
    {
        $this->assertNotSame(
            'cjw_wizard_submission',
            CJW_Wizard_Submission_Repository::POST_TYPE,
            'cjw_wizard_submission is the 21-character name WordPress rejects.'
        );
    }
}
