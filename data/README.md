# data/

| Folder | Contents |
|---|---|
| `raw/` | Files downloaded unchanged from PhysioNet by `cli/download-bidmc.php` (`bidmc_NN_Numerics.csv`, `bidmc_NN_Fix.txt`). Never edited. |
| `processed/` | One ingestion report per record (`bidmc_NN_ingestion.json`): rows read, values stored, missing, rejected (with reasons). |

Source: BIDMC PPG and Respiration Dataset v1.0.0, https://physionet.org/content/bidmc/1.0.0/ —
see `docs/DATA_PROVENANCE.md` for the column mapping, units and citation note.
This folder is blocked from the web by the root `.htaccess`.
