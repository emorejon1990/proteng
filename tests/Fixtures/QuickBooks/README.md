# Capturas sanitizadas de facturas QuickBooks

Origen: `C:\Users\Lokillo\Downloads\quickbooks_invoice_debug.zip`, proporcionado por el usuario. El ZIP original se leyó fuera del repositorio y no se copió aquí.

Las dos capturas originales tienen los mismos Invoice IDs (`1982`, `11241`, `11990`), entorno production, realm y minorversion 75.

- `invoices-raw.sanitized.json`: proyección del objeto Invoice del cuerpo HTTP original.
- `invoices-sdk.sanitized.json`: proyección del grafo de tipos/propiedades de la captura SDK. `tests/Support/QuickBooksCapturedFixtures.php` reconstruye objetos de las clases instaladas y usa la misma conversión `QuickBooksReadGateway::payload()` que la importación general.

Se conservaron IDs de factura, IDs numéricos de Items/líneas, tipos de línea, orden, cantidades, precios, importes, totales, impuestos, descuentos, referencias especiales y representaciones de detalles vacíos. Se eliminaron nombres, descripciones, direcciones, correos, teléfonos, notas y otras propiedades no necesarias. El CustomerRef se sustituyó por `10` y DocNumber por un valor sintético. No se almacena el realm real.

Las respuestas de Items no están en el ZIP. Los mocks de las pruebas asignan tipos Inventory/Service y mappings de Assets exclusivamente para escenarios aislados de integridad operativa; no afirman el tipo ni mapping de los Items reales.

Casos confirmados:

| Factura | Índice desde cero | JSON original | SDK |
| --- | --- | --- | --- |
| 1982 | 17 | SalesItem con ItemRef `SHIPPING_ITEM_ID`, Amount 800 | Referencia escalar `SHIPPING_ITEM_ID` |
| 1982 | 15 | SubTotalLineDetail `{}`, Amount 20968 | Detalle `""`, Amount `"20968.00"` |
| 11241 | 1 | SubTotalLineDetail `{}`, Amount 6490 | Detalle `""`, Amount `"6490.00"` |
| 11990 | 42 | SubTotalLineDetail `{}`, Amount 238649 | Detalle `""`, Amount `"238649.00"` |

Prueba selectiva, usando solo estas capturas y SQLite en memoria:

```shell
php vendor/bin/pest tests/Unit/InvoiceRealPayloadTest.php --compact
```

No requiere el ZIP original para ejecutarse. No llama a QuickBooks ni ejecuta la sincronización general. `QuickBooksService::ds()` y las consultas de listados están prohibidas por mocks en esta suite. Las pruebas llaman al mismo `InvoiceSyncService::import()` de producción y prueban ambas representaciones, cambios, relaciones y rollback.
