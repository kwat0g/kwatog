<?php
require 'api/vendor/autoload.php';
$app = require_once 'api/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;

$customer = Customer::first();
$products = Product::limit(2)->get();
echo "Customer: {$customer->name}\n";
echo "Product 1: {$products[0]->name}\n";
echo "Product 2: {$products[1]->name}\n";

