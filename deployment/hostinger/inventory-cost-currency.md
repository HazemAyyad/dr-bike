# Inventory cost currency

Inventory unit costs and values are denominated in shekels. Stocktaking,
opening stock, cost initialization, cost revaluation, and missing-sale cost
corrections must write `شيكل`. The aliases `NIS`, `ILS`, `₪`, and `שקל` are
accepted and canonicalized. Summary labels such as `mixed`, unsupported values,
and foreign currencies are rejected as inventory cost inputs; they are never
silently converted.

## Existing mixed labels

`2026_11_08_130000_correct_mixed_inventory_cost_currency.php` corrects only rows
whose stored currency is `mixed` (ignoring surrounding spaces and case) in
inventory adjustments, balances, layers, and purchase price histories.
It updates the currency field only, including exhausted historical layers.
Quantities, costs, amounts, dates, invoices and payment records are preserved.

Before running this named migration on production, take and verify a database
backup. Deploy the backend and run the named migration together before resuming
stock changes. Then verify that no `mixed` rows remain in these four tables,
that their quantities and values match the backup, and that invoice #5181 can
be edited. A code rollback does not reintroduce invalid currency labels; use
the verified database backup if the data correction itself must be reversed.

Creating or committing this migration does not execute it. Do not run a general
migration command as part of this change: inspect pending migrations and run
only the named correction when a production release is authorized.
