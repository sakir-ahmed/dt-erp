# DT ERP product requirements

DT ERP serves one company per installation. The company manages its catalog and business operations from a bilingual web workspace. There are no branches.

## Initial business slice: product catalog

- Administrators can create, edit, and deactivate products. Staff can browse and search.
- A product has a unique SKU, name, unit, and nonnegative BDT sale price. SKUs are normalized to uppercase, and prices are stored to two decimal places.
- Search matches the product name or SKU. The directory is paginated, with a desktop table and mobile cards.
- Deactivation retains the record for future use in transaction history. Demo accounts use separate demo catalog records.

## Proposed delivery order

1. Stock movements and balances for catalog products.
2. Supplier and purchasing workflows that receive stock.
3. Customer and sales workflows that issue stock.
4. Payments, accounting, and operational reporting.

This order is provisional. Each workflow needs transaction and reversal rules, permissions, and acceptance criteria before it can be called complete. AI remains outside the initial catalog scope.
