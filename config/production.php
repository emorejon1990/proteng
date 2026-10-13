<?php

return [
    // Optional IDs must be verified against the target database, never copied from seeders.
    'catalog_ids' => [
        'production_type' => env('PRODUCTION_TYPE_ID'),
        'distribution_type' => env('PRODUCTION_DISTRIBUTION_TYPE_ID'),
        'distribution_location' => env('PRODUCTION_DISTRIBUTION_LOCATION_ID'),
        'assemble_center' => env('PRODUCTION_ASSEMBLE_CENTER_ID'),
        'production_location' => env('PRODUCTION_LOCATION_ID'),
        'review_type' => env('PRODUCTION_REVIEW_TYPE_ID'),
        'review_center' => env('PRODUCTION_REVIEW_CENTER_ID'),
        'review_location' => env('PRODUCTION_REVIEW_LOCATION_ID'),
        'recycle_location' => env('PRODUCTION_RECYCLE_LOCATION_ID'),
        'recycled_product_status' => env('PRODUCTION_RECYCLED_PRODUCT_STATUS_ID'),
        'new_order_status' => env('PRODUCTION_NEW_ORDER_STATUS_ID'),
        'approved_order_status' => env('PRODUCTION_APPROVED_ORDER_STATUS_ID'),
        'in_process_status' => env('PRODUCTION_IN_PROCESS_STATUS_ID'),
        'filling_center' => env('PRODUCTION_FILLING_CENTER_ID'),
        'waiting_center' => env('PRODUCTION_WAITING_CENTER_ID'),
        'waiting_location' => env('PRODUCTION_WAITING_LOCATION_ID'),
        'quality_center' => env('PRODUCTION_QUALITY_CENTER_ID'),
        'quality_location' => env('PRODUCTION_QUALITY_LOCATION_ID'),
        'stock_location' => env('PRODUCTION_STOCK_LOCATION_ID'),
        'ready_order_status' => env('PRODUCTION_READY_ORDER_STATUS_ID'),
        'ready_product_status' => env('PRODUCTION_READY_PRODUCT_STATUS_ID'),
        'in_process_product_status' => env('PRODUCTION_IN_PROCESS_PRODUCT_STATUS_ID'),
    ],
];
