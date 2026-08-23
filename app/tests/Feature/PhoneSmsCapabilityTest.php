<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Dutch landline passes every "is this a valid phone number" check but can
 * never receive an SMS. Student #221's sheet value (703603984) is one leading
 * zero away from +31703603984 — a The Hague landline — so the obvious "fix" of
 * adding the missing 0 would have created a recipient the provider bills for
 * and no parent ever hears from, with nothing on screen explaining it.
 */
class PhoneSmsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dutch_mobile_is_sms_capable(): void
    {
        $this->assertTrue(PhoneNormalizer::isSmsCapable('+31616535560'));
        $this->assertTrue(PhoneNormalizer::isSmsCapable('+31622825650'));
    }

    public function test_dutch_landline_is_not_sms_capable(): void
    {
        $this->assertFalse(PhoneNormalizer::isSmsCapable('+31703603984'),
            'A 070 The Hague landline must never be treated as an SMS recipient.');
    }

    public function test_empty_or_malformed_is_not_sms_capable(): void
    {
        $this->assertFalse(PhoneNormalizer::isSmsCapable(null));
        $this->assertFalse(PhoneNormalizer::isSmsCapable(''));
        $this->assertFalse(PhoneNormalizer::isSmsCapable('703603984'), 'missing country/leading zero');
    }

    public function test_student_on_a_landline_is_skipped_with_a_clear_reason(): void
    {
        $s = Student::create([
            'name' => 'Landline Kid',
            'phone_primary_e164' => '+31703603984',
            'allow_sms' => true,
        ]);

        $this->assertSame(__('skip.not_mobile'), $s->skipReason());
        $this->assertFalse($s->canReceiveMessages(),
            'A landline must not be messaged, not even by a single send.');
    }

    public function test_student_on_a_mobile_is_messageable(): void
    {
        $s = Student::create([
            'name' => 'Mobile Kid',
            'phone_primary_e164' => '+31616535560',
            'allow_sms' => true,
        ]);

        $this->assertNull($s->skipReason());
        $this->assertTrue($s->canReceiveMessages());
    }

    public function test_the_leading_zero_fix_produces_a_landline_not_a_mobile(): void
    {
        // Documents exactly why #221 cannot be repaired by guessing.
        $normalized = PhoneNormalizer::normalize('0703603984');
        $this->assertSame('+31703603984', $normalized);
        $this->assertTrue(PhoneNormalizer::isValid($normalized), 'it looks valid…');
        $this->assertFalse(PhoneNormalizer::isSmsCapable($normalized), '…but it cannot receive SMS');
    }
}
