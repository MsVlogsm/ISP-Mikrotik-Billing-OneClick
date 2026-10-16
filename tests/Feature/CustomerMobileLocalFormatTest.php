<?php

namespace Tests\Feature;

use App\Rules\ValidPhoneDigits;
use Tests\TestCase;

class CustomerMobileLocalFormatTest extends TestCase
{
    public function test_bangladesh_mobile_can_be_validated_without_country_code(): void
    {
        $errors = [];

        (new ValidPhoneDigits())->validate(
            'mobile',
            '01712345678',
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        $this->assertSame([], $errors);
    }
}
