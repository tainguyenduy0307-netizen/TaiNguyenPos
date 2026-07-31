<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Dinner_table;
use App\Models\Item;
use App\Models\Item_kit_items;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class Sale_libPaymentTest extends CIUnitTestCase
{
    private Sale_lib $saleLib;

    protected function setUp(): void
    {
        parent::setUp();

        // Inject mock OSPOS config so Sale_lib constructor and helpers don't need real settings
        $ospos           = new OSPOS();
        $ospos->settings = [
            'cash_rounding_code' => '',
            'cash_decimals'      => 2,
            'currency_decimals'  => 2,
            'tax_decimals'       => 2,
            'quantity_decimals'  => 2,
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);

        // Inject stub models so model() calls in Sale_lib::__construct() skip DB
        $stubMethods = ['__construct'];
        foreach ([
            Attribute::class,
            Customer::class,
            Dinner_table::class,
            Item::class,
            Item_kit_items::class,
            Item_quantity::class,
            Item_taxes::class,
            Sale::class,
            Stock_location::class,
        ] as $modelClass) {
            $mock = $this->getMockBuilder($modelClass)
                ->disableOriginalConstructor()
                ->getMock();
            Factories::injectMock('models', $modelClass, $mock);
        }

        session()->destroy();
        $this->saleLib = new Sale_lib();
    }

    protected function tearDown(): void
    {
        Factories::reset();
        parent::tearDown();
    }

    // ========== getPayments / setPayments ==========

    public function testGetPaymentsReturnsEmptyArrayInitially(): void
    {
        $payments = $this->saleLib->getPayments();
        $this->assertIsArray($payments);
        $this->assertEmpty($payments);
    }

    public function testSetPaymentsPersistsToSession(): void
    {
        $data = [
            'cash' => [
                'payment_type'    => 'cash',
                'payment_amount'  => '10.00',
                'cash_refund'     => 0,
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'reference_code'  => null,
            ]
        ];
        $this->saleLib->setPayments($data);
        $this->assertSame($data, $this->saleLib->getPayments());
    }

    // ========== addPayment ==========

    public function testAddPaymentCreatesNewEntry(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'ABC123');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayHasKey('credit', $payments);
        $this->assertSame('credit', $payments['credit']['payment_type']);
        $this->assertSame('25.00', $payments['credit']['payment_amount']);
        $this->assertSame(0, $payments['credit']['cash_refund']);
        $this->assertSame(CASH_ADJUSTMENT_FALSE, $payments['credit']['cash_adjustment']);
    }

    public function testAddPaymentStoresReferenceCode(): void
    {
        $this->saleLib->addPayment('debit', '50.00', 'REF9876');

        $payments = $this->saleLib->getPayments();
        $this->assertSame('REF9876', $payments['debit']['reference_code']);
    }

    public function testAddPaymentNullReferenceCodeStoredAsNull(): void
    {
        $this->saleLib->addPayment('cash', '15.00');

        $payments = $this->saleLib->getPayments();
        $this->assertNull($payments['cash']['reference_code']);
    }

    public function testAddPaymentAccumulatesAmountForExistingId(): void
    {
        $this->saleLib->addPayment('credit', '10.00', 'REF001');
        $this->saleLib->addPayment('credit', '5.00', 'REF001');

        $payments = $this->saleLib->getPayments();
        $this->assertEqualsWithDelta(15.00, (float) $payments['credit']['payment_amount'], 0.001);
    }

    public function testAddPaymentCashAdjustmentFlagStored(): void
    {
        $this->saleLib->addPayment('cash_adjustment', '0.05', null, CASH_ADJUSTMENT_TRUE);

        $payments = $this->saleLib->getPayments();
        $this->assertSame(CASH_ADJUSTMENT_TRUE, $payments['cash_adjustment']['cash_adjustment']);
    }

    public function testAddPaymentMultipleDistinctTypesAllStored(): void
    {
        $this->saleLib->addPayment('credit', '30.00', 'REF1');
        $this->saleLib->addPayment('debit', '20.00', 'REF2');

        $payments = $this->saleLib->getPayments();
        $this->assertCount(2, $payments);
        $this->assertArrayHasKey('credit', $payments);
        $this->assertArrayHasKey('debit', $payments);
    }

    // ========== edit_payment ==========

    public function testEditPaymentUpdatesAmount(): void
    {
        $this->saleLib->addPayment('credit', '10.00', 'REF001');
        $result = $this->saleLib->edit_payment('credit', 99.99);

        $this->assertTrue($result);
        $payments = $this->saleLib->getPayments();
        $this->assertSame(99.99, $payments['credit']['payment_amount']);
    }

    public function testEditPaymentReturnsFalseForMissingId(): void
    {
        $result = $this->saleLib->edit_payment('nonexistent', 10.00);
        $this->assertFalse($result);
    }

    // ========== delete_payment ==========

    public function testDeletePaymentRemovesEntry(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'REF999');
        $this->saleLib->delete_payment('credit');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayNotHasKey('credit', $payments);
    }

    public function testDeletePaymentLeavesOtherEntriesIntact(): void
    {
        $this->saleLib->addPayment('credit', '25.00', 'REF1');
        $this->saleLib->addPayment('debit', '10.00', 'REF2');
        $this->saleLib->delete_payment('credit');

        $payments = $this->saleLib->getPayments();
        $this->assertArrayNotHasKey('credit', $payments);
        $this->assertArrayHasKey('debit', $payments);
    }

    public function testDeleteItemRemovesFirstLineByCartKey(): void
    {
        $this->saleLib->set_cart([
            1 => $this->cartItem(101, 1),
            2 => $this->cartItem(102, 2),
            3 => $this->cartItem(103, 3),
        ]);

        $this->assertTrue($this->saleLib->delete_item(1));

        $cart = $this->saleLib->get_cart();
        $this->assertArrayNotHasKey(1, $cart);
        $this->assertArrayHasKey(2, $cart);
        $this->assertArrayHasKey(3, $cart);
    }

    public function testDeleteItemRemovesMiddleLineWhenCartKeysAreNotContinuous(): void
    {
        $this->saleLib->set_cart([
            1 => $this->cartItem(101, 1),
            3 => $this->cartItem(103, 3),
            8 => $this->cartItem(108, 8),
        ]);

        $this->assertTrue($this->saleLib->delete_item(3));

        $cart = $this->saleLib->get_cart();
        $this->assertSame([1, 8], array_keys($cart));
        $this->assertSame(101, $cart[1]['item_id']);
        $this->assertSame(108, $cart[8]['item_id']);
    }

    public function testDeleteItemRemovesLastLineByCartKey(): void
    {
        $this->saleLib->set_cart([
            1 => $this->cartItem(101, 1),
            2 => $this->cartItem(102, 2),
            9 => $this->cartItem(109, 9),
        ]);

        $this->assertTrue($this->saleLib->delete_item(9));

        $cart = $this->saleLib->get_cart();
        $this->assertArrayHasKey(1, $cart);
        $this->assertArrayHasKey(2, $cart);
        $this->assertArrayNotHasKey(9, $cart);
    }

    public function testDeleteItemReturnsFalseForStaleLineAndLeavesCartUntouched(): void
    {
        $cart = [
            2 => $this->cartItem(102, 2),
            5 => $this->cartItem(105, 5),
        ];
        $this->saleLib->set_cart($cart);

        $this->assertFalse($this->saleLib->delete_item(1));
        $this->assertSame($cart, $this->saleLib->get_cart());
    }

    public function testDeleteItemCanBeCalledTwiceForSameLineWithoutError(): void
    {
        $this->saleLib->set_cart([
            1 => $this->cartItem(101, 1),
            4 => $this->cartItem(104, 4),
        ]);

        $this->assertTrue($this->saleLib->delete_item(1));
        $this->assertFalse($this->saleLib->delete_item(1));

        $cart = $this->saleLib->get_cart();
        $this->assertSame([4], array_keys($cart));
        $this->assertSame(104, $cart[4]['item_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cartItem(int $itemId, int $line): array
    {
        return [
            'item_id'   => $itemId,
            'line'      => $line,
            'item_type' => ITEM,
        ];
    }
}
