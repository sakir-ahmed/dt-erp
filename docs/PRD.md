# DT ERP product requirements

DT ERP serves one company per installation. The company manages its catalog and business operations from a bilingual web workspace. There are no branches.

## Initial business slice: product catalog

- Administrators can create, edit, and deactivate products. Staff can browse and search.
- A product has a unique SKU, name, unit, and nonnegative BDT sale price. SKUs are normalized to uppercase, and prices are stored to two decimal places.
- Search matches the product name or SKU. The directory is paginated, with a desktop table and mobile cards.
- Deactivation retains the record for future use in transaction history. Demo accounts use separate demo catalog records.

## Stock movements and balances

- Staff and administrators can view balances and movement history. Administrators can record receipts and issues with a required reason.
- A new product starts at zero. Quantities use the product unit and accept up to three decimal places. A stock issue cannot make the balance negative. Inactive products can issue existing stock and must be reactivated before receiving more.
- Every movement records the change, resulting balance, actor, and UTC timestamp. Corrections use a new compensating movement with an explanatory reason. Posted movements are kept in history.
- Posting a movement updates the balance and ledger together. A retry of the same submission applies the change once. Demo stock is isolated from regular stock.

## Proposed delivery order

1. Supplier and purchasing workflows that receive stock.
2. Customer and sales workflows that issue stock.
3. Payments, accounting, and operational reporting.

This order is provisional. Each workflow needs transaction and reversal rules, permissions, and acceptance criteria before it can be called complete. AI remains outside the initial catalog scope.
