# Supplier Target Management — Implementation Report

**Prepared:** October 6, 2026  
**Module:** Supplier Targets, Branch Allocation, Achievement Tracking, and Further Discount Ledger  
**Status:** Implemented in source code and verified with automated tests

## 1. Business Requirement

The company may assign a sales target for a complete year, a calendar quarter, or one month. A target can be defined for a product or a complete product category.

Example requirements:

- Sell 2,000 refrigerators.
- Sell 100 air conditioners.
- Sell a defined quantity of washing machines.
- Divide each target among six branches.
- Tell each branch manager the branch target for the complete period and the calculated monthly target.
- Measure actual sales against the assigned target.
- Calculate the further discount earned when the agreed target is achieved.
- Record discount amounts posted or paid by the supplier company.
- Show the remaining discount receivable balance by supplier and product/category.

## 2. Implemented Target Periods

The system supports the following target periods:

| Period | Date behavior | Performance timeline |
|---|---|---|
| Annual | Start and end dates must be in the same calendar year | 12 monthly buckets |
| Quarterly | Start and end dates must be in the same calendar quarter | 3 monthly buckets |
| Monthly | Start and end dates must be in the same calendar month | Weekly buckets |

When Quarterly is selected in the target wizard, the selected date is automatically adjusted to the first and last dates of that calendar quarter.

Calendar-quarter definitions:

- Q1: January 1 to March 31
- Q2: April 1 to June 30
- Q3: July 1 to September 30
- Q4: October 1 to December 31

> A standard calendar quarter contains three months. A period of four months is not treated as a calendar quarter.

Quarterly is also available in the target list, dashboard, and report filters.

## 3. Target Creation Workflow

The target wizard is divided into three controlled steps.

### Step 1 — Target Details

The user selects:

- Supplier/company
- Target name
- Period type: Annual, Quarterly, or Monthly
- Start date and end date
- Description or notes

The measurement type is fixed as Quantity (Units).

The target is initially saved with Draft status. This allows the user to complete the setup before it affects live performance reporting.

### Step 2 — Product and Category Targets

The user adds one or more target lines. Each line contains:

- Product or category
- Target quantity
- Multiple named further discounts (percentage or fixed per item), added through the plus-button editor
- Unit of measurement

Only products and categories associated with the selected supplier are available. Duplicate lines and overlapping product/category coverage are rejected to prevent the same sale from being counted twice.

### Step 3 — Branch Allocation

Every product/category target is allocated to one or more accessible branches.

Three allocation methods are available:

1. **Allocate by Branch Sales (recommended)** — The system calculates each branch's share from historical net sales of the selected product/category.
2. **Distribute Equally** — The target is divided equally when management wants the same target for every branch.
3. **Manual Allocation** — The user can enter or adjust every branch quantity directly.

For sales-weighted allocation, the system uses the immediately preceding period with the same number of days as the new target. It counts completed sales and shipped ordered items, then subtracts completed returns. The branch share is calculated separately for every product/category target line.

**Branch sales share = Branch historical net quantity ÷ Total historical net quantity for all selected branches**

**Suggested branch target = Product/category target quantity × Branch sales share**

If no branch has sales history for a target line, that line automatically falls back to equal distribution. The allocation grid shows each branch's historical net quantity and sales-share percentage beside the suggestion. Every suggested value remains editable, so management can make a manual adjustment before saving.

The system validates that:

- The total branch allocation equals the complete supplier target.
- Each product/category line is fully allocated to branches.
- A branch cannot be repeated for the same target line.
- Negative allocations are not accepted.
- Users can allocate only to branches they are permitted to access.

Target quantities and branch allocations are stored as decimal values with three decimal places. Values such as 333.333, 27.778, and 10.125 are supported.

## 4. Monthly and Quarterly Planning

The system stores the approved quantity for each product/category and branch. It then derives the branch monthly plan from the target date range.

Calculation:

**Monthly branch target = Branch product/category allocation ÷ Number of months in the target period**

Quantities are calculated to three decimal places. Any rounding remainder is added safely across the generated periods so that the monthly values always total exactly to the original branch allocation.

### Worked Example — Annual Target Using Branch Sales

Assume:

- Refrigerator annual target: 2,000 units
- Number of branches: 6
- Historical net sales across all branches: 1,000 units

| Branch | Historical net sales | Sales share | Suggested annual target | Approximate monthly target |
|---|---:|---:|---:|---:|
| Branch A | 350.000 | 35% | 700.000 | 58.333 |
| Branch B | 250.000 | 25% | 500.000 | 41.667 |
| Branch C | 150.000 | 15% | 300.000 | 25.000 |
| Branch D | 100.000 | 10% | 200.000 | 16.667 |
| Branch E | 100.000 | 10% | 200.000 | 16.667 |
| Branch F | 50.000 | 5% | 100.000 | 8.333 |
| **Total** | **1,000.000** | **100%** | **2,000.000** | **166.667** |

Branch A receives the largest target because it historically sold the most refrigerators. Management can still change any suggested quantity manually.

The final stored values use remainder-safe decimal distribution, so the total across all branches and months remains exactly 2,000.000 units.

### Worked Example — Quarterly Target

If a quarterly refrigerator target is 2,000 units:

**2,000 ÷ 3 = approximately 666.667 units per month**

If Branch A has a 35 percent historical sales share:

**2,000 × 35% = 700.000 units for Branch A for the quarter**

Branch A's approximate monthly target is:

**700.000 ÷ 3 = approximately 233.333 units**

Other branches receive quantities according to their own sales shares. Equal distribution and manual assignment remain available.

## 5. Achievement Calculation

Achievement is calculated automatically from sales transactions within the target date range and selected branches.

The calculation includes:

- Completed sales.
- Ordered sales only when the relevant item has been shipped.
- Sales from branches assigned to the target.
- Sales for products directly selected in the target.
- Sales for products belonging to a selected category.

The calculation excludes:

- Cancelled sales.
- Deleted sales.
- Unshipped ordered items.
- Sales outside the target date range.
- Sales from branches not allocated to the target.

Completed sales returns reduce achieved quantity.

Main calculations:

- **Achieved quantity = Valid sales quantity − Valid returned quantity**
- **Remaining quantity = Maximum of Target quantity − Achieved quantity, or zero**
- **Achievement percentage = Achieved quantity ÷ Target quantity × 100**

The displayed performance status can be:

- Behind
- On Track
- Ahead
- Achieved
- Exceeded

The status compares actual achievement with the percentage of the target period already elapsed.

## 6. Branch Manager Visibility

Target information is restricted by warehouse access.

A branch-level user sees performance for permitted branches, including:

- Complete branch target
- Product/category target
- Monthly branch target
- Achieved quantity
- Remaining quantity
- Achievement percentage
- Current status

Administrators with all-warehouse permission can see and manage the complete company target.

## 7. Further Discount Calculation

Each product/category target line can contain multiple named supplier-company discounts. The target wizard uses a plus-button editor instead of a single discount input. Each entry has a category/name, a percentage or fixed-per-item type, and a value. These supplier incentives remain separate from the product's selling price and customer discount.

Example:

- Refrigerator target: 1,000 units
- Company further discount: 1,000 fixed per item

The discount accrues with every valid sold unit:

- After 1 unit: **1 × 1,000 = 1,000 accrued**
- After 250 units: **250 × 1,000 = 250,000 accrued**
- At 1,000 units: **1,000 × 1,000 = 1,000,000 accrued**

Accrual is capped at the target quantity. Units sold above the target do not increase this target's accrued discount. A completed sale adds to accrual immediately; an ordered sale adds after shipment. Completed returns reduce the achieved quantity and accrued discount automatically.

For each target line, the system calculates:

- **Accrued quantity = Minimum of achieved quantity and target quantity**
- **Discount per item = percentage discounts calculated from purchase price + fixed discounts**
- **Accrued discount = Sum of eligible sold quantities × applicable per-item discount**
- **Posted discount = Sum of supplier discount postings**
- **Discount balance = Accrued discount − Posted discount**

### Create Sale Visibility

When a product belongs to an active target for the selected branch and sale date, Create Sale displays:

- Supplier/company
- Target name
- Further discount rate per unit
- Discount accrued before the current sale
- Discount contribution from the current sale

This information is display-only. It is removed from the sale submission payload and does not change the unit price, item discount, tax, sale subtotal, grand total, customer ledger, payment, or accounting entries.

## 8. Supplier Further Discount Ledger

A dedicated ledger records discount amounts posted by the supplier company.

Each posting contains:

- Supplier target
- Product/category target line
- Posting date
- Amount
- Supplier reference or credit-note number
- Notes
- User who entered the posting

Example:

- Accrued discount: 3,600,000
- First company posting: 2,000,000
- Second company posting: 1,000,000
- Total posted: 3,000,000
- Remaining receivable balance: 600,000

The ledger can be reviewed by supplier/company and category. It shows accrued, posted, and outstanding amounts, allowing accounts staff to reconcile partial postings received after one, two, or several months.

Once discount postings exist, protected target-line changes are restricted so historical ledger records cannot silently become inconsistent.

## 9. Target Lifecycle and Controls

Target statuses:

1. **Draft** — Details, target lines, and allocations can be prepared.
2. **Active** — The complete validated target contributes to live achievement reporting.
3. **Completed** — The target period or business process has been closed.
4. **Cancelled** — The target is no longer active.

Before activation, the system checks:

- Target quantity is greater than zero.
- Total allocations exactly match the target.
- Every product/category is fully allocated by branch.
- No active target exists for the same supplier, overlapping dates, and overlapping products.

Changes and lifecycle actions are stored in target history records with the responsible user and old/new values.

## 10. Permissions

The module uses separate permissions for:

| Permission | Purpose |
|---|---|
| targets.view | View targets |
| targets.create | Create target drafts |
| targets.edit | Edit eligible targets |
| targets.activate | Activate and complete targets |
| targets.cancel | Cancel targets |
| targets.delete | Delete drafts |
| targets.reports | View target reports |
| targets.export | Export reports |
| targets.view_all_warehouses | View all branch data |
| targets.discount_ledger | View the further discount ledger |
| targets.discount_postings | Add or remove supplier discount postings |

Completed and cancelled targets cannot be edited. Only drafts can be deleted.

## 11. Screens and Reports

The implementation provides:

- Target dashboard with target, achieved, remaining, and percentage cards
- Annual, quarterly, and monthly filters
- Supplier and warehouse filters
- Target list with lifecycle actions
- Three-step target wizard
- Target detail and performance screen
- Product/category performance
- Warehouse performance
- Monthly branch plan
- Quarter summaries
- Further discount ledger
- Print report
- PDF export
- Excel export

## 12. Main Data Records

| Record | Responsibility |
|---|---|
| supplier_targets | Supplier, period, dates, status, and target header |
| supplier_target_lines | Product/category quantity and named percentage/fixed discount list |
| supplier_target_allocations | Overall target allocation by branch |
| supplier_target_line_allocations | Product/category allocation by branch |
| supplier_target_discount_postings | Supplier discount credits/payments received |
| supplier_target_histories | Audit trail of target changes and lifecycle actions |

## 13. Pricing-Level Further Discount Stack

The pricing-level margin modal imports multiple named supplier discounts from the matching active target for each product or variant. Target-imported discounts are displayed above margins as read-only source values. A discount can be a percentage or a fixed amount. This covers arrangements such as:

- Payment Clearance: 5%
- Target: 3%
- Per Item: 1,400 fixed

Each percentage is calculated from the original purchase price. The discount amounts are added together and deducted from that original price. All pricing margins are then calculated from the resulting **Further Discounted Price**.

Example for a purchase price of 45,000:

- 5% = 2,250
- 3% = 1,350
- Fixed per-item discount = 1,400
- Total further discount = 5,000
- Further Discounted Price = **40,000**

The original purchase price remains **45,000**. It is not overwritten. When the pricing user clicks **Apply to Price Row**, the imported target discounts become the row's saved pricing discount stack and margins use the discounted base. Pricing tables show a separate **Further Discounted Price** column immediately after Purchase Price, and pricing history keeps both values and the named discount breakdown. The system rejects invalid percentages and any discount stack whose total exceeds the purchase price.

## 14. Verification Performed

The supplier target feature test suite completed successfully:

- **52 tests passed**
- **87 assertions passed**

The tests cover:

- Annual, quarterly, and monthly periods
- Quarterly three-month charts and plans
- Date-period validation
- Sales and returns
- Shipped and unshipped ordered sales
- Per-sale further-discount accrual capped at target quantity
- Create Sale target-discount visibility metadata
- Isolation from sale pricing and ledger values
- Warehouse access
- Product/category counting
- Exact allocation totals and rounding
- Decimal quantities with three-place precision
- Sales-weighted branch allocation
- Equal fallback when sales history is unavailable
- Manual adjustment compatibility
- Activation controls
- Discount earning and partial postings
- Permissions
- Audit history

PHP syntax validation and Vue source/template parsing also passed.

The pricing calculation and persistence suites also completed successfully:

- **15 pricing tests passed**
- **64 pricing assertions passed**
- Multiple percentage and fixed discounts verified
- Original purchase-price preservation verified
- Product, variant, current-price, and historical snapshot persistence verified

## 15. Deployment Note

The source-code implementation is complete. No frontend production build was generated, following the instruction not to create a build.

The pricing discount database migration was applied successfully in the current environment. The new frontend behavior will appear in the deployed browser application only after an authorized frontend build and deployment is performed through the project's normal release process. Required database migrations must also be run in every other deployment environment.

