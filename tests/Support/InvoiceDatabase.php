<?php

function invoiceIntegrationMigrations(): array
{
    return [
        '2025_10_02_192820_create_permission_tables.php',
        '2025_10_20_141010_create_equipment_table.php',
        '2025_12_15_160054_create_customers_table.php',
        '2026_01_08_142403_update_customers_table.php',
        '2025_12_15_160110_create_invoices_table.php',
        '2026_01_08_142527_update_invoices_table.php',
        '2026_01_08_172434_add_description_field_to_asset_table.php',
        '2026_01_14_090000_add_quickbooks_id_to_asset_table.php',
        '2026_01_15_010000_add_customer_quickbooks_id_to_work_orders_table.php',
        '2026_03_04_100000_create_installations_module_tables.php',
        '2026_10_13_140000_add_invoice_integration.php',
    ];
}
function invoiceIntegrationUp(): void
{
    foreach (invoiceIntegrationMigrations() as $file) {
        (require database_path('migrations/'.$file))->up();
    }
}
