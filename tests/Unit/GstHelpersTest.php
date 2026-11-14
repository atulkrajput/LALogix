<?php

namespace Tests\Unit;

use App\Services\Billing\InvoiceCalculator;
use App\Support\AmountInWords;
use App\Support\Gstin;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class GstHelpersTest extends TestCase
{
    public function test_gstin_check_digit_validation(): void
    {
        $this->assertTrue(Gstin::isValid('27AAPFU0939F1ZV'));          // widely published sample GSTIN
        $this->assertTrue(Gstin::isValid(' 27aapfu0939f1zv '));        // normalised
        $this->assertFalse(Gstin::isValid('27AAPFU0939F1ZA'));         // wrong check digit
        $this->assertFalse(Gstin::isValid('99AAPFU0939F1Z'.Gstin::checkDigit('99AAPFU0939F1Z'))); // unknown state
        $this->assertFalse(Gstin::isValid('27AAPFU0939F1Z'));          // too short
        $this->assertSame('27', Gstin::stateCode('27AAPFU0939F1ZV'));
        $this->assertSame('AAPFU0939F', Gstin::pan('27AAPFU0939F1ZV'));
    }

    public function test_amount_in_words_uses_indian_system(): void
    {
        $this->assertSame('Indian Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty Paise Only', AmountInWords::rupees(123456.50));
        $this->assertSame('Indian Rupees Two Crore Five Lakh Only', AmountInWords::rupees(20500000));
        $this->assertSame('Indian Rupees Zero Only', AmountInWords::rupees(0));
        $this->assertSame('Indian Rupees One Thousand One Hundred Eighty Only', AmountInWords::rupees(1180));
    }

    public function test_intra_state_splits_cgst_and_sgst(): void
    {
        $r = InvoiceCalculator::calculate([['quantity' => 1, 'rate' => 1000, 'gst_rate' => 18]], 'intra');
        $this->assertSame(90.0, $r['totals']['cgst_total']);
        $this->assertSame(90.0, $r['totals']['sgst_total']);
        $this->assertSame(0.0, $r['totals']['igst_total']);
        $this->assertSame(1180.0, $r['totals']['grand_total']);
    }

    public function test_inter_state_charges_igst_and_rounds_total(): void
    {
        $r = InvoiceCalculator::calculate([
            ['quantity' => 1, 'rate' => 999.99, 'gst_rate' => 18],
            ['quantity' => 2.5, 'rate' => 100, 'discount' => 50, 'gst_rate' => 18],
        ], 'inter');
        // 999.99 + (250 - 50) = 1199.99 taxable; IGST 180.00 + 36.00
        $this->assertSame(1199.99, $r['totals']['taxable_total']);
        $this->assertSame(216.0, $r['totals']['igst_total']);
        $this->assertSame(0.01, $r['totals']['round_off']);
        $this->assertSame(1416.0, $r['totals']['grand_total']);
        $this->assertSame(200.0, $r['lines'][1]['taxable_value']);
    }

    public function test_export_under_lut_is_zero_rated(): void
    {
        $lut = InvoiceCalculator::calculate([['quantity' => 1, 'rate' => 500, 'gst_rate' => 18]], 'export', true);
        $this->assertSame(0.0, $lut['totals']['igst_total']);
        $this->assertSame(500.0, $lut['totals']['grand_total']);

        $withTax = InvoiceCalculator::calculate([['quantity' => 1, 'rate' => 500, 'gst_rate' => 18]], 'export', false);
        $this->assertSame(90.0, $withTax['totals']['igst_total']);
    }

    public function test_no_round_off_keeps_paise(): void
    {
        $r = InvoiceCalculator::calculate([['quantity' => 1, 'rate' => 99.99, 'gst_rate' => 18]], 'inter', false, false);
        $this->assertSame(0.0, $r['totals']['round_off']);
        $this->assertSame(117.99, $r['totals']['grand_total']);
    }

    public function test_financial_year_and_supply_type(): void
    {
        $this->assertSame('2026-27', InvoiceCalculator::financialYear(new DateTimeImmutable('2027-03-31')));
        $this->assertSame('2027-28', InvoiceCalculator::financialYear(new DateTimeImmutable('2027-04-01')));
        $this->assertSame('intra', InvoiceCalculator::supplyType('29', '29'));
        $this->assertSame('inter', InvoiceCalculator::supplyType('29', '27'));
        $this->assertSame('export', InvoiceCalculator::supplyType('29', '96'));
    }
}
