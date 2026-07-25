<?php

namespace App\Models;

use CodeIgniter\Model;

class Business_unit extends Model
{
    protected $table = 'business_units';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'code',
        'name',
        'enabled',
    ];
}
