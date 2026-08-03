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

/**
 * @internal
 */
final class Sale_libCashierOrdersTest extends CIUnitTestCase
{
    private Sale_lib $saleLib;

    protected function setUp(): void
    {
        parent::setUp();

        $ospos           = new OSPOS();
        $ospos->settings = [
            'cash_rounding_code'            => '',
            'cash_decimals'                 => 2,
            'currency_decimals'             => 2,
            'tax_decimals'                  => 2,
            'quantity_decimals'             => 2,
            'invoice_enable'                => false,
            'work_order_enable'             => false,
            'print_receipt_check_behaviour' => 'remember',
            'email_receipt_check_behaviour' => 'remember',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);

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

    public function testEnsureCashierOrdersStartsWithOneEmptyOrder(): void
    {
        $this->saleLib->ensureCashierOrders();

        $orders = $this->saleLib->getCashierOrders();
        $this->assertSame(['order_1'], array_keys($orders));
        $this->assertSame('order_1', $this->saleLib->getActiveCashierOrderId());
        $this->assertSame([], $orders['order_1']['cart']);
        $this->assertSame(NEW_ENTRY, $orders['order_1']['customer_id']);
        $this->assertSame([], $orders['order_1']['payments']);
    }

    public function testCreateCashierOrderCreatesIncrementingOrdersAndStopsAtFive(): void
    {
        $this->saleLib->ensureCashierOrders();

        $this->assertSame('order_2', $this->saleLib->createCashierOrder());
        $this->assertSame('order_3', $this->saleLib->createCashierOrder());
        $this->assertSame('order_4', $this->saleLib->createCashierOrder());
        $this->assertSame('order_5', $this->saleLib->createCashierOrder());
        $this->assertNull($this->saleLib->createCashierOrder());

        $this->assertSame(['order_1', 'order_2', 'order_3', 'order_4', 'order_5'], array_keys($this->saleLib->getCashierOrders()));
        $this->assertSame('order_5', $this->saleLib->getActiveCashierOrderId());
    }

    public function testOrdersKeepCartCustomerPaymentAndTenderedAmountIndependent(): void
    {
        $this->saleLib->ensureCashierOrders();
        $this->saleLib->set_cart([1 => $this->cartItem(101, 'Coca')]);
        $this->saleLib->set_customer(11);
        $this->saleLib->addPayment('cash', '100000');
        $this->saleLib->saveActiveCashierOrder('100.000');

        $this->assertSame('order_2', $this->saleLib->createCashierOrder());
        $this->saleLib->set_cart([1 => $this->cartItem(202, 'Pepsi')]);
        $this->saleLib->set_customer(22);
        $this->saleLib->addPayment('cash', '50000');
        $this->saleLib->saveActiveCashierOrder('50.000');

        $this->assertSame('order_3', $this->saleLib->createCashierOrder());

        $this->assertTrue($this->saleLib->restoreCashierOrder('order_1'));
        $this->assertSame('Coca', $this->saleLib->get_cart()[1]['name']);
        $this->assertSame(11, $this->saleLib->get_customer());
        $this->assertSame('100000', $this->saleLib->getPayments()['cash']['payment_amount']);

        $this->assertTrue($this->saleLib->restoreCashierOrder('order_2'));
        $this->assertSame('Pepsi', $this->saleLib->get_cart()[1]['name']);
        $this->assertSame(22, $this->saleLib->get_customer());
        $this->assertSame('50000', $this->saleLib->getPayments()['cash']['payment_amount']);

        $this->assertTrue($this->saleLib->restoreCashierOrder('order_3'));
        $this->assertSame([], $this->saleLib->get_cart());
        $this->assertSame(NEW_ENTRY, $this->saleLib->get_customer());
        $this->assertSame([], $this->saleLib->getPayments());

        $orders = $this->saleLib->getCashierOrders();
        $this->assertSame('100.000', $orders['order_1']['amount_tendered']);
        $this->assertSame('50.000', $orders['order_2']['amount_tendered']);
    }

    public function testCloseNonActiveOrderDoesNotAffectOtherOrders(): void
    {
        $this->seedThreeOrders();
        $this->assertTrue($this->saleLib->restoreCashierOrder('order_3'));

        $this->assertTrue($this->saleLib->closeCashierOrder('order_2'));

        $this->assertSame(['order_1', 'order_3'], array_keys($this->saleLib->getCashierOrders()));
        $this->assertSame('order_3', $this->saleLib->getActiveCashierOrderId());
        $this->assertSame([], $this->saleLib->get_cart());

        $this->assertTrue($this->saleLib->restoreCashierOrder('order_1'));
        $this->assertSame('Coca', $this->saleLib->get_cart()[1]['name']);
    }

    public function testCashierOrdersKeepSaleLevelDiscountStateSeparate(): void
    {
        $this->saleLib->ensureCashierOrders();
        $this->saleLib->set_cart([1 => $this->cartItem(101, 'Coca')]);
        $this->saleLib->set_order_discount(PERCENT, '10', 'ORDER1');
        $this->saleLib->saveActiveCashierOrder();

        $this->saleLib->createCashierOrder();
        $this->saleLib->set_cart([1 => $this->cartItem(202, 'Pepsi')]);
        $this->saleLib->set_order_discount(FIXED, '5000', 'ORDER2');
        $this->saleLib->saveActiveCashierOrder();

        $orders = $this->saleLib->getCashierOrders();
        $this->assertSame(PERCENT, (int) $orders['order_1']['sale_discount_type']);
        $this->assertSame('10', (string) $orders['order_1']['sale_discount_value']);
        $this->assertSame('ORDER1', $orders['order_1']['sale_discount_code']);
        $this->assertSame(FIXED, (int) $orders['order_2']['sale_discount_type']);
        $this->assertSame('5000', (string) $orders['order_2']['sale_discount_value']);
        $this->assertSame('ORDER2', $orders['order_2']['sale_discount_code']);

        $this->assertTrue($this->saleLib->restoreCashierOrder('order_1'));
        $this->assertSame(PERCENT, $this->saleLib->get_order_discount_type());
        $this->assertSame('10', $this->saleLib->get_order_discount_value());
        $this->assertSame('ORDER1', $this->saleLib->get_order_discount_code());
    }


    public function testCloseActiveOrderSwitchesToNearestOrderAndLastOrderResetsBlank(): void
    {
        $this->seedThreeOrders();
        $this->assertTrue($this->saleLib->restoreCashierOrder('order_2'));

        $this->assertTrue($this->saleLib->closeCashierOrder('order_2'));
        $this->assertSame('order_3', $this->saleLib->getActiveCashierOrderId());

        $this->assertTrue($this->saleLib->closeCashierOrder('order_3'));
        $this->assertSame('order_1', $this->saleLib->getActiveCashierOrderId());

        $this->assertTrue($this->saleLib->closeCashierOrder('order_1'));
        $orders = $this->saleLib->getCashierOrders();
        $this->assertSame(['order_1'], array_keys($orders));
        $this->assertSame([], $orders['order_1']['cart']);
        $this->assertSame(NEW_ENTRY, $orders['order_1']['customer_id']);
        $this->assertSame([], $orders['order_1']['payments']);
    }

    public function testCompleteActiveOrderRemovesOnlyTheActiveOrder(): void
    {
        $this->seedThreeOrders();
        $this->assertTrue($this->saleLib->restoreCashierOrder('order_1'));

        $this->saleLib->completeActiveCashierOrder();

        $this->assertSame(['order_2', 'order_3'], array_keys($this->saleLib->getCashierOrders()));
        $this->assertSame('order_2', $this->saleLib->getActiveCashierOrderId());
        $this->assertSame('Pepsi', $this->saleLib->get_cart()[1]['name']);
    }

    private function seedThreeOrders(): void
    {
        $this->saleLib->ensureCashierOrders();
        $this->saleLib->set_cart([1 => $this->cartItem(101, 'Coca')]);
        $this->saleLib->set_customer(11);
        $this->saleLib->addPayment('cash', '100000');
        $this->saleLib->saveActiveCashierOrder();

        $this->saleLib->createCashierOrder();
        $this->saleLib->set_cart([1 => $this->cartItem(202, 'Pepsi')]);
        $this->saleLib->set_customer(22);
        $this->saleLib->addPayment('cash', '50000');
        $this->saleLib->saveActiveCashierOrder();

        $this->saleLib->createCashierOrder();
    }

    /**
     * @return array<string, mixed>
     */
    private function cartItem(int $itemId, string $name): array
    {
        return [
            'item_id'          => $itemId,
            'name'             => $name,
            'line'             => 1,
            'item_type'        => ITEM,
            'stock_type'       => HAS_STOCK,
            'quantity'         => '1',
            'price'            => '0',
            'discount'         => '0',
            'discount_type'    => PERCENT,
            'discounted_total' => '0',
        ];
    }
}
