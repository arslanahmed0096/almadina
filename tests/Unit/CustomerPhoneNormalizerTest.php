<?php

namespace Tests\Unit;

use App\Support\CustomerPhoneNormalizer;
use PHPUnit\Framework\TestCase;

class CustomerPhoneNormalizerTest extends TestCase
{
    public function test_it_adds_only_a_missing_pakistani_mobile_leading_zero(): void
    {
        $this->assertSame('03123456789', CustomerPhoneNormalizer::normalize('3123456789'));
        $this->assertSame('03123456789', CustomerPhoneNormalizer::normalize('312-3456789'));
        $this->assertSame('03123456789', CustomerPhoneNormalizer::normalize('0312 345 6789'));
        $this->assertSame('+923123456789', CustomerPhoneNormalizer::normalize('+923123456789'));
        $this->assertSame('+3123456789', CustomerPhoneNormalizer::normalize('+3123456789'));
        $this->assertSame('0421234567', CustomerPhoneNormalizer::normalize('0421234567'));
        $this->assertNull(CustomerPhoneNormalizer::normalize(null));
    }

    public function test_it_treats_local_and_international_pakistani_mobiles_as_the_same_identity(): void
    {
        $this->assertSame('3123456789', CustomerPhoneNormalizer::identityKey('03123456789'));
        $this->assertSame('3123456789', CustomerPhoneNormalizer::identityKey('3123456789'));
        $this->assertSame('3123456789', CustomerPhoneNormalizer::identityKey('+923123456789'));
        $this->assertSame('3123456789', CustomerPhoneNormalizer::identityKey('00923123456789'));
        $this->assertNull(CustomerPhoneNormalizer::identityKey('+3123456789'));
    }
}
