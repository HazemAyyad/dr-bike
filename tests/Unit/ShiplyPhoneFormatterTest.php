<?php

namespace Tests\Unit;

use App\Support\ShiplyPhoneFormatter;
use Tests\TestCase;

class ShiplyPhoneFormatterTest extends TestCase
{
    public function test_converts_plus_972_api_format_to_local_mobile(): void
    {
        $this->assertSame('0567555309', ShiplyPhoneFormatter::forParcel('+972 567555309'));
    }

    public function test_keeps_local_mobile_format(): void
    {
        $this->assertSame('0599123456', ShiplyPhoneFormatter::forParcel('0599123456'));
    }

    public function test_converts_nine_digit_mobile_without_leading_zero(): void
    {
        $this->assertSame('0599123456', ShiplyPhoneFormatter::forParcel('599123456'));
    }

    public function test_converts_plus_970_format(): void
    {
        $this->assertSame('0599123456', ShiplyPhoneFormatter::forParcel('+970 599123456'));
    }

    public function test_validates_palestine_mobile(): void
    {
        $this->assertTrue(ShiplyPhoneFormatter::isValidForParcel('+972 567555309'));
        $this->assertFalse(ShiplyPhoneFormatter::isValidForParcel('123'));
    }
}
