# Customer opening-balance live update

This update does not require a database migration or a manual SQL change.
Existing sales are recalculated from the current customer opening balance,
sales, payments, and returns whenever the sales list or sale detail is loaded.

For customer phone 03168973169, the expected current values are:

- Opening balance: Rs 46,121.00
- Sale SL_5092 invoice due: Rs 221,400.00
- Customer total due: Rs 267,521.00

## Files to deploy

- app/Http/Controllers/ClientController.php
- app/Http/Controllers/SalesController.php
- app/Services/CustomerBalanceService.php
- resources/src/views/app/pages/sales/create_sale.vue
- resources/src/views/app/pages/sales/index_sale.vue
- resources/src/views/app/pages/sales/detail_sale.vue

The test file is optional on production:

- tests/Feature/CustomerSaleBalanceTest.php

## Deploy from Git

From the Laravel application directory on the live server, run:

    git pull
    php artisan optimize:clear
    npm ci
    npm run production
    php artisan optimize

No migration or seeder command is needed.

## If Node.js is not available on the live server

Deploy the PHP and Vue source files first. On a deployment machine using the
same commit and Node.js dependency versions, run:

    npm ci
    npm run production

Then upload the generated public JavaScript/CSS assets together with
public/mix-manifest.json to the live server. Finally run:

    php artisan optimize:clear
    php artisan optimize

## Verification

1. Log in and open Sales.
2. Search for SL_5092.
3. Confirm Customer Total Due is Rs 267,521.00.
4. Confirm the breakdown shows invoice due Rs 221,400.00 and opening balance
   Rs 46,121.00.
5. Open the sale detail and confirm the same breakdown.

The invoice total remains Rs 221,400.00; the new Customer Total Due is a
separate account-level value and does not rewrite the original invoice.
