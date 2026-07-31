<?php

namespace Tests\Controllers;

use App\Controllers\Sales;
use CodeIgniter\Test\CIUnitTestCase;

class SalesQuickProductsTest extends CIUnitTestCase
{
    public function testQuickProductTotalPagesUsesTwelveItemsPerPage(): void
    {
        $this->assertSame(1, Sales::calculateQuickProductTotalPages(4));
        $this->assertSame(1, Sales::calculateQuickProductTotalPages(12));
        $this->assertSame(2, Sales::calculateQuickProductTotalPages(13));
        $this->assertSame(15, Sales::calculateQuickProductTotalPages(178));
    }

    public function testQuickProductTotalPagesNeverReturnsLessThanOne(): void
    {
        $this->assertSame(1, Sales::calculateQuickProductTotalPages(0));
        $this->assertSame(1, Sales::calculateQuickProductTotalPages(10, 0));
    }
}
