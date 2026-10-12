# GenPay CSV demo kit

Ten CSV files for evaluation and finals. Student names, emails, phone numbers, addresses, and restriction policies are fictional demo data. Emails use example.com. Importing them does not provide working email inboxes.

## Demo sequence and expected results

Use **Maintenance → Bulk Student Import** for files 01–08. These are student-account imports, not School Years rosters.

| File | Contents | Expected result |
| --- | --- | --- |
| 01_students_valid_batch_a.csv | Correct signed template; 6 new students | 6 ready to import on a fresh demo database |
| 02_students_valid_batch_b.csv | Correct signed template; 6 different new students | 6 ready to import on a fresh demo database |
| 03_students_duplicate_batch_a.csv | Exact copy of file 01 under a different filename | After importing 01, all 6 rows flagged as duplicate; no new accounts |
| 04_students_duplicate_batch_b.csv | Exact copy of file 02 under a different filename | After importing 02, all 6 rows flagged as duplicate; no new accounts |
| 05_students_wrong_template_renamed_columns.csv | Signed first line retained; student ID and first-name columns renamed; 3 records | Reject the file because column names do not match |
| 06_students_wrong_template_missing_columns.csv | Signed first line retained; optional template columns missing; 3 different records | Reject the file because the complete template is required |
| 07_students_valid_data_without_marker.csv | Valid student data and correct columns; signed first line missing; 3 records | Reject the file because it is not the downloaded template |
| 08_students_valid_data_reordered_columns.csv | Valid student data with values mapped to reversed column order; 3 different records | Reject the file because template column order changed |
| 09_restricted_products_demo.csv | 8 sample restrictions with product_name, category, match_type, and reason | Reference for manual restriction entry; there is currently no restricted-products CSV uploader |
| 10_student_fee_waiver_import.csv | Correct signed waiver template; 5 existing eligible student IDs and positive amounts | 5 ready to credit, provided their waiver credits are still empty |

1. Show file 01's import preview, then confirm its import. Repeat with file 02.
2. Upload files 03 and 04 after the originals have been imported. Show the duplicate counts.
3. Upload files 05 and 06 to demonstrate column-name and missing-column rejection.
4. Upload files 07 and 08 to demonstrate that valid student details still require the signed template and correct column order.
5. For restricted products, open the CSV and enter a sample restriction using **Maintenance → Restricted Products**. `contains` blocks matching product names; `exact` targets the exact sample name. These are example restrictions, not a statement of the school's actual policy.
6. Upload file 10 under **Maintenance → Fee Waiver Import**. Confirm only when you intend to issue the demo credits. The import credits existing students directly; it is not a waiver-document upload.

## Import details

- Files 01 and 02 contain different records. Files 03 and 04 are byte-identical to 01 and 02 respectively, with different filenames.
- Student IDs in the valid files were checked as unused when this kit was generated. They begin at GJC2026-8001. If you already imported a valid batch during practice, it will correctly show duplicates on the next run.
- All 17 student-template columns are present in valid files, including optional columns with blank values. Phone numbers retain their leading zero in the CSV.
- Do not delete or change the first `#genpay-template:` line of valid student or waiver files. It is required by the current importer.
- Signed templates belong to this configured GenPay instance. If finals use a different instance with a different signing secret, download its templates and copy only the data rows into them.
- The waiver CSV uses existing IDs GJC2026-0001 through GJC2026-0005. Their credits were empty when checked. After a successful waiver import, re-uploading correctly skips students whose credits are already claimed.
- Creating this kit did not import students, create restrictions, issue credits, change balances, or send emails.

## Verification completed

The kit was checked using the actual Maintenance parsers and validators. Both valid batches returned 6 ready rows with zero duplicates and errors. Both duplicate copies matched their originals by SHA-256 and returned 6 duplicates in a temporary-table simulation. Files 05–08 were rejected for the intended template issues. All 5 waiver rows were ready to credit. The restricted-products CSV follows the current manual form's field names and allowed category/match-type values.
