<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class LocaleHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../app/Helpers/locale_helper.php';

        $config           = new OSPOS();
        $config->settings = [
            'dateformat'           => 'Y-m-d',
            'number_locale'        => 'en_US',
            'currency_symbol'      => '$',
            'currency_decimals'    => 2,
            'tax_decimals'         => 2,
            'quantity_decimals'    => 2,
            'thousands_separator'  => 1,
            'tax_included'         => false,
        ];
        Factories::injectMock('config', OSPOS::class, $config);
    }

    protected function tearDown(): void
    {
        Factories::reset();
        parent::tearDown();
    }

    public function testValidDateReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-06-10'));
    }

    public function testInvalidDateFormatReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('10/06/2024'));
    }

    public function testImpossibleDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-13-01'));
    }

    public function testPhpDateOverflowReturnsFalse(): void
    {
        // PHP silently overflows Feb 30 → Mar 1; the format()===candidate check catches this
        $this->assertFalse(isValidDate('2024-02-30'));
    }

    public function testEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(isValidDate(''));
    }

    public function testLeapDayValidReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-02-29'));
    }

    public function testLeapDayInvalidYearReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2023-02-29'));
    }

    public function testPartialDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-06'));
    }

    public function testCurrencyDisplaysInVietnameseFormatWithoutSymbol(): void
    {
        $this->assertSame('0', to_currency('0'));
        $this->assertSame('1.000', to_currency('1000'));
        $this->assertSame('1.000.000', to_currency('1000000'));
        $this->assertSame('-50.000', to_currency('-50000'));
        $this->assertSame('1.235', to_currency('1234.56'));

        $formatted = to_currency('1000');
        $this->assertStringNotContainsString('$', $formatted);
        $this->assertStringNotContainsString('₫', $formatted);
    }

    public function testCurrencyNoMoneyUsesSameVietnameseFormat(): void
    {
        $this->assertSame('1.000', to_currency_no_money('1000'));
        $this->assertStringNotContainsString('$', to_currency_no_money('1000'));
        $this->assertStringNotContainsString('₫', to_currency_no_money('1000'));
    }

    public function testParseDecimalsAcceptsVietnameseCurrencyInput(): void
    {
        $this->assertSame(10000.0, parse_decimals('10.000'));
        $this->assertSame(1234.56, parse_decimals('1.234,56'));
        $this->assertSame(1234.56, parse_decimals('1234.56'));
    }
}
