<?php

declare(strict_types=1);

/**
 * The rental inventory's readers.
 *
 * Every one of these fields ends up in a sum on the verhuurpagina -- 20 x 8
 * plus 4 x 12 is the "208 slaapplaatsen" line, and the footprint drawings are
 * scaled straight off the centimetres. A field that reads back as a string, or
 * as a negative, or as a category the theme has no branch for, is a wrong
 * number on the page rather than a visible error, so the readers clamp and the
 * tests hold them to it.
 */
final class RentalInventoryTest extends CJW_TestCase
{
    private const int ITEM = 4100;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_post_meta'] = [];
    }

    public function testNumberReadsAStoredInteger(): void
    {
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_COUNT, 20);

        $this->assertSame(
            20,
            CJW_Summer_Camp_Rental_Post_Type::number(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_COUNT)
        );
    }

    public function testNumberReadsANumericStringAsAnInteger(): void
    {
        // The meta table stores strings; a value typed into the screen and read
        // straight back would otherwise multiply as '8' and concatenate.
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_CAPACITY, '8');

        $this->assertSame(
            8,
            CJW_Summer_Camp_Rental_Post_Type::number(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_CAPACITY)
        );
    }

    public function testNumberAnswersZeroForAnUnsetField(): void
    {
        $this->assertSame(
            0,
            CJW_Summer_Camp_Rental_Post_Type::number(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_LENGTH)
        );
    }

    public function testNumberAnswersZeroForTextThatIsNotANumber(): void
    {
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_WIDTH, 'ongeveer vier meter');

        $this->assertSame(
            0,
            CJW_Summer_Camp_Rental_Post_Type::number(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_WIDTH)
        );
    }

    public function testNumberClampsANegativeToZero(): void
    {
        // A negative length would draw a footprint rectangle inside out.
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_LENGTH, -300);

        $this->assertSame(
            0,
            CJW_Summer_Camp_Rental_Post_Type::number(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_LENGTH)
        );
    }

    public function testCategoryReadsAKnownSlug(): void
    {
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_CATEGORY, 'groepstent');

        $this->assertSame('groepstent', CJW_Summer_Camp_Rental_Post_Type::category(self::ITEM));
    }

    public function testCategoryFallsBackToOverigForAnUnknownSlug(): void
    {
        update_post_meta(self::ITEM, CJW_Summer_Camp_Rental_Post_Type::META_CATEGORY, 'kampvuur');

        $this->assertSame('overig', CJW_Summer_Camp_Rental_Post_Type::category(self::ITEM));
    }

    public function testCategoryFallsBackToOverigWhenUnset(): void
    {
        $this->assertSame('overig', CJW_Summer_Camp_Rental_Post_Type::category(self::ITEM));
    }

    public function testEveryCategoryIsAValidMetaValue(): void
    {
        foreach (array_keys(CJW_Summer_Camp_Rental_Post_Type::categories()) as $slug) {
            $this->assertSame(
                $slug,
                sanitize_key($slug),
                "Category '{$slug}' does not survive sanitize_key(), which the save handler runs it through."
            );
        }
    }

    public function testNoteAnswersAnEmptyStringWhenUnset(): void
    {
        $this->assertSame('', CJW_Summer_Camp_Rental_Post_Type::note(self::ITEM));
    }

    public function testPostTypeFitsWordPressLengthLimit(): void
    {
        $name = CJW_Summer_Camp_Rental_Post_Type::POST_TYPE;

        $this->assertLessThanOrEqual(
            20,
            strlen($name),
            "Post type '{$name}' is " . strlen($name) . ' characters; WordPress caps the name at 20.'
        );
    }
}
