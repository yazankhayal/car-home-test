<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        // TODO: This endpoint becomes slow with many orders. Investigate query count.
        $orders = Order::query()
            ->select(['id', 'customer_id', 'status', 'total'])
            ->with([
                'customer:id,name,email',
                'items:id,order_id,product_name,sku,quantity,unit_price',
            ])
            ->latest('id')
            ->get();

        return response()->json($orders);
    }
}
