# Test fixtures — SYNTHETIC TEST DATA

These files are **not** PhysioNet data and are never loaded into the real database.
They are small hand-written files in the BIDMC numerics format, used only by
`tests/run.php` against the separate `orbitcare_test` database. Record IDs 90–92
do not exist in the real BIDMC dataset (which has records 01–53).

| File | Purpose |
|---|---|
| `bidmc_90_Numerics.csv` | 40 s. SpO2 is 88 % (RED range) from t = 5 to t = 19, then 97 %. PULSE is `NaN` (missing) at t = 20–39. Used to test alert creation/resolution and missing data. |
| `bidmc_91_Numerics.csv` | Contains impossible or malformed values (SpO2 150 %, text, negative time) that must be rejected. |
| `bidmc_92_Numerics.csv` | Wrong header — must be refused as an invalid dataset. |

Any alert created from these fixtures is stored with `is_test_fixture = 1`.
