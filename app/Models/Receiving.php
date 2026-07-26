<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use Config\Services;
use ReflectionException;

/**
 * Receiving class
 */
class Receiving extends Model
{
    protected $table = 'receivings';
    protected $primaryKey = 'receiving_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'receiving_time',
        'supplier_id',
        'employee_id',
        'business_unit_id',
        'comment',
        'receiving_id',
        'payment_type',
        'reference'
    ];

    /**
     * @param int $receiving_id
     * @return ResultInterface
     */
    public function get_info(int $receiving_id): ResultInterface
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();
        $builder = $this->db->table('receivings');
        $builder->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
        $builder->join('suppliers', 'suppliers.person_id = receivings.supplier_id', 'LEFT');
        $builder->where('receiving_id', $receiving_id);
        $builder->where('business_unit_id', $businessUnitId);

        return $builder->get();
    }

    /**
     * @param string $reference
     * @return ResultInterface
     */
    public function get_receiving_by_reference(string $reference): ResultInterface
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();
        $builder = $this->db->table('receivings');
        $builder->where('reference', $reference);
        $builder->where('business_unit_id', $businessUnitId);

        return $builder->get();
    }

    /**
     * @param string $receipt_receiving_id
     * @return bool
     */
    public function is_valid_receipt(string $receipt_receiving_id): bool    // TODO: maybe receipt_receiving_id should be an array rather than a space delimited string
    {
        if (!empty($receipt_receiving_id)) {
            // RECV #
            $pieces = explode(' ', $receipt_receiving_id);

            if (count($pieces) == 2 && preg_match('/(RECV|KIT)/', $pieces[0])) {
                return $this->exists($pieces[1]);
            } else {
                return $this->get_receiving_by_reference($receipt_receiving_id)->getNumRows() > 0;
            }
        }

        return false;
    }

    /**
     * @param int $receiving_id
     * @return bool
     */
    public function exists(int $receiving_id): bool
    {
        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);
        $builder->where('business_unit_id', $this->getCurrentBusinessUnitId());

        return ($builder->get()->getNumRows() == 1);
    }

    /**
     * @param $receiving_id
     * @param $receiving_data
     * @return bool
     */
    public function update($receiving_id = null, $receiving_data = null): bool
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();

        if (!$this->receivingBelongsToBusinessUnit((int) $receiving_id, $businessUnitId)) {
            return false;
        }

        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);
        $builder->where('business_unit_id', $businessUnitId);
        $update_data = $receiving_data;
        unset($update_data['business_unit_id']);

        return $builder->update($update_data);
    }

    /**
     * @throws ReflectionException
     */
    public function save_value(array $items, int $supplier_id, int $employee_id, string $comment, string $reference, ?string $payment_type, int $receiving_id = NEW_ENTRY): int    // TODO: $receiving_id gets overwritten before it's evaluated. It doesn't make sense to pass this here.
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();
        $attribute = model(Attribute::class);
        $inventory = model('Inventory');
        $item = model(Item::class);
        $businessUnitInventory = Services::businessUnitInventory();
        $supplier = model(Supplier::class);

        if (count($items) == 0) {
            return -1;    // TODO: Replace -1 with a constant
        }

        $receivings_data = [
            'receiving_time' => date('Y-m-d H:i:s'),
            'supplier_id'    => $supplier->exists($supplier_id) ? $supplier_id : null,
            'employee_id'    => $employee_id,
            'business_unit_id' => $businessUnitId,
            'payment_type'   => $payment_type,
            'comment'        => $comment,
            'reference'      => $reference
        ];

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        $builder = $this->db->table('receivings');
        $builder->insert($receivings_data);
        $receiving_id = $this->db->insertID();

        $builder = $this->db->table('receivings_items');

        foreach ($items as $line => $item_data) {
            $config = config(OSPOS::class)->settings;
            $cur_item_info = $item->get_info($item_data['item_id']);

            $receivings_items_data = [
                'receiving_id'       => $receiving_id,
                'item_id'            => $item_data['item_id'],
                'line'               => $item_data['line'],
                'description'        => $item_data['description'],
                'serialnumber'       => $item_data['serialnumber'],
                'quantity_purchased' => $item_data['quantity'],
                'receiving_quantity' => $item_data['receiving_quantity'],
                'discount'           => $item_data['discount'],
                'discount_type'      => $item_data['discount_type'],
                'item_cost_price'    => $cur_item_info->cost_price,
                'item_unit_price'    => $item_data['price'],
                'item_location'      => $item_data['item_location']
            ];

            $builder->insert($receivings_items_data);

            $items_received = $item_data['receiving_quantity'] != 0 ? $item_data['quantity'] * $item_data['receiving_quantity'] : $item_data['quantity'];

            // Update cost price, if changed AND is set in config as wanted
            if ($cur_item_info->cost_price != $item_data['price'] && $config['receiving_calculate_average_price']) {
                $item->change_cost_price($item_data['item_id'], $items_received, $item_data['price'], $cur_item_info->cost_price);
            }

            // Update stock quantity
            $businessUnitInventory->changeCurrentQuantity($item_data['item_id'], $item_data['item_location'], $items_received);

            $recv_remarks = 'RECV ' . $receiving_id;
            $inv_data = [
                'trans_date'      => date('Y-m-d H:i:s'),
                'trans_items'     => $item_data['item_id'],
                'trans_user'      => $employee_id,
                'trans_location'  => $item_data['item_location'],
                'trans_comment'   => $recv_remarks,
                'trans_inventory' => $items_received,
                'business_unit_id' => $businessUnitId
            ];

            $inventory->insert($inv_data, false);
            $attribute->copy_attribute_links($item_data['item_id'], 'receiving_id', $receiving_id);
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $receiving_id : -1;
    }


    /**
     * @throws ReflectionException
     */
    public function delete_list(array $receiving_ids, int $employee_id, bool $update_inventory = true): bool
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();
        $success = true;

        // Start a transaction to assure data integrity
        $this->db->transStart();

        foreach ($receiving_ids as $receiving_id) {
            if (!$this->receivingBelongsToBusinessUnit((int) $receiving_id, $businessUnitId)) {
                $success = false;
                continue;
            }

            $success &= $this->delete_value($receiving_id, $employee_id, $update_inventory);
        }

        // Execute transaction
        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * @throws ReflectionException
     */
    public function delete_value(int $receiving_id, int $employee_id, bool $update_inventory = true): bool
    {
        $businessUnitId = $this->getCurrentBusinessUnitId();

        if (!$this->receivingBelongsToBusinessUnit($receiving_id, $businessUnitId)) {
            return false;
        }

        // Start a transaction to assure data integrity
        $this->db->transStart();

        if ($update_inventory) {
            // TODO: defect, not all item deletions will be undone? get array with all the items involved in the sale to update the inventory tracking
            $items = $this->get_receiving_items($receiving_id)->getResultArray();

            $inventory = model('Inventory');
            $businessUnitInventory = Services::businessUnitInventory();

            foreach ($items as $item) {
                // Create query to update inventory tracking
                $inv_data = [
                    'trans_date'      => date('Y-m-d H:i:s'),
                    'trans_items'     => $item['item_id'],
                    'trans_user'      => $employee_id,
                    'trans_comment'   => 'Deleting receiving ' . $receiving_id,
                    'trans_location'  => $item['item_location'],
                    'trans_inventory' => $item['quantity_purchased'] * (-$item['receiving_quantity']),
                    'business_unit_id' => $businessUnitId
                ];
                // Update inventory
                $inventory->insert($inv_data, false);

                // Update quantities
                $businessUnitInventory->changeCurrentQuantity($item['item_id'], $item['item_location'], $item['quantity_purchased'] * (-$item['receiving_quantity']));
            }
        }

        // Delete all items
        $builder = $this->db->table('receivings_items');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Delete sale itself
        $builder = $this->db->table('receivings');
        $builder->delete(['receiving_id' => $receiving_id]);

        // Execute transaction
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * @param int $receiving_id
     * @return ResultInterface
     */
    public function get_receiving_items(int $receiving_id): ResultInterface
    {
        $builder = $this->db->table('receivings_items');
        $builder->where('receiving_id', $receiving_id);

        if (!$this->receivingBelongsToBusinessUnit($receiving_id, $this->getCurrentBusinessUnitId())) {
            $builder->where('receiving_id', NEW_ENTRY);
        }

        return $builder->get();
    }

    /**
     * @param int $receiving_id
     * @return object
     */
    public function get_supplier(int $receiving_id): object
    {
        $supplier = model(Supplier::class);

        if (!$this->receivingBelongsToBusinessUnit($receiving_id, $this->getCurrentBusinessUnitId())) {
            return $supplier->get_info(null);
        }

        $builder = $this->db->table('receivings');
        $builder->where('receiving_id', $receiving_id);

        return $supplier->get_info($builder->get()->getRow()->supplier_id);
    }

    /**
     * @return array
     */
    public function get_payment_options(): array
    {
        return [
            lang('Sales.cash') => lang('Sales.cash'),
            lang('Sales.check') => lang('Sales.check'),
            lang('Sales.debit') => lang('Sales.debit'),
            lang('Sales.credit') => lang('Sales.credit'),
            lang('Sales.due') => lang('Sales.due'),
            lang('Sales.bank_transfer') => lang('Sales.bank_transfer'),
            lang('Sales.wallet') => lang('Sales.wallet')
        ];
    }

    /**
     * Create a temp table that allows us to do easy report/receiving queries
     */
    public function create_temp_table(array $inputs): void
    {
        $config = config(OSPOS::class)->settings;
        $db_prefix = $this->db->getPrefix();

        if (empty($inputs['receiving_id'])) {
            $where = empty($config['date_or_time_format'])
                ? 'DATE(`receiving_time`) BETWEEN ' . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date'])
                : 'receiving_time BETWEEN ' . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date']));
        } else {
            $where = 'receivings_items.receiving_id = ' . $this->db->escape($inputs['receiving_id']);
        }

        $builder = $this->db->table('receivings_items');
        $builder->select([
            'MAX(DATE(`receiving_time`)) AS receiving_date',
            'MAX(`receiving_time`) AS receiving_time',
            'receivings_items.receiving_id AS receiving_id',
            'MAX(`comment`) AS comment',
            'MAX(`item_location`) AS item_location',
            'MAX(`reference`) AS reference',
            'MAX(`payment_type`) AS payment_type',
            'MAX(`employee_id`) AS employee_id',
            'items.item_id AS item_id',
            'MAX(`' . $db_prefix . 'receivings`.`supplier_id`) AS supplier_id',
            'MAX(`quantity_purchased`) AS quantity_purchased',
            'MAX(`' . $db_prefix . 'receivings_items`.`receiving_quantity`) AS item_receiving_quantity',
            'MAX(`item_cost_price`) AS item_cost_price',
            'MAX(`item_unit_price`) AS item_unit_price',
            'MAX(`discount`) AS discount',
            'MAX(`discount_type`) AS discount_type',
            'receivings_items.line AS line',
            'MAX(`serialnumber`) AS serialnumber',
            'MAX(`' . $db_prefix . 'receivings_items`.`description`) AS description',
            'MAX(CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `discount` END) AS subtotal',
            'MAX(CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `discount` END) AS total',
            'MAX((CASE WHEN `' . $db_prefix . 'receivings_items`.`discount_type` = ' . PERCENT . ' THEN `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` * `discount` / 100 ELSE `item_unit_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` - discount END) - (`item_cost_price` * `quantity_purchased`)) AS profit',
            'MAX(`item_cost_price` * `quantity_purchased` * `' . $db_prefix . 'receivings_items`.`receiving_quantity` ) AS cost'
        ]);
        $builder->join('receivings', 'receivings_items.receiving_id = receivings.receiving_id', 'inner');
        $builder->join('items', 'receivings_items.item_id = items.item_id', 'inner');
        $builder->where($where);
        $builder->groupBy(['receivings_items.receiving_id', 'items.item_id', 'receivings_items.line']);
        $selectQuery = $builder->getCompiledSelect();

        // QueryBuilder does not support creating temporary tables.
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->prefixTable('receivings_items_temp') .
            ' (INDEX(receiving_date), INDEX(receiving_time), INDEX(receiving_id)) AS (' . $selectQuery . ')';

        $this->db->query($sql);
    }

    public function is_owned_by_current_business_unit(int $receiving_id): bool
    {
        return $this->receivingBelongsToBusinessUnit($receiving_id, $this->getCurrentBusinessUnitId());
    }

    private function getCurrentBusinessUnitId(): int
    {
        return Services::businessUnit()->requireCurrentBusinessUnitId();
    }

    private function receivingBelongsToBusinessUnit(int $receivingId, int $businessUnitId): bool
    {
        if ($receivingId == NEW_ENTRY) {
            return false;
        }

        return $this->db->table('receivings')
            ->where('receiving_id', $receivingId)
            ->where('business_unit_id', $businessUnitId)
            ->countAllResults() === 1;
    }
}
