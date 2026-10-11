# GenPay defense note: retained facts and denormalization

The reviewed GenPay entities were normalized toward Third Normal Form. Repeating product/item groups became related rows; copied wallet identities were resolved through wallet links; locations use scoped identifiers; application details and rejection history use related tables; selected redundant calculated columns were removed.

Wallet balances are deliberate operating state maintained through CirculationEngine. The circulation cap remains an operating control used by minting and integrity checks. These values are retained because the system's accounting workflow depends on them.

Historical transaction fees, credited values, circulation snapshots, order-item prices, voucher terms, and lease contract facts describe their own events or agreements. They must not be recalculated from today's fees, balances, inventory prices, or stall labels. Such historical facts are not automatically normalization violations.

Availability and manual restriction flags express decisions about an inventory item. Reminder markers record notification progress. Their meanings differ from current stock levels or policy matches.

The completed scope is Groups A–D. Group E's three unused plaintext-password columns remain present and currently NULL; its proposed changes were not applied. Browser acceptance testing and the deferred POS scanner issue remain outstanding.

The database uses a 3NF-oriented design for the reviewed entities with documented operating state. This is not a formal certification that every functional dependency in all seventy tables satisfies strict 3NF.
