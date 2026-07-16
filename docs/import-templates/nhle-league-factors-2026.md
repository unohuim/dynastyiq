# NHLe League Factors 2026

Seed source for NL Ice Data / Thibaud Chatel 2026 NHLe league factors.

## Source Metadata

| Field | Value |
| --- | --- |
| Source | NL Ice Data / Thibaud Chatel |
| Article | https://thibaudchatel.substack.com/p/nhle-update-for-2026 |
| Published | 2026-05-02 |
| Source version | 2026 |
| Model | Full-network NHLe |
| Model window | 2021-22 through 2025-26 |
| Era adjustment | 2026 scoring level |
| Factor columns | Win Shares NHLe, Points NHLe |
| Transcription status | Manual transcription from article table images |

## Seeder Notes

Seeder target table: `nhle_league_factors`.

Recommended fields:

| Field | Notes |
| --- | --- |
| `source` | Stable source slug, e.g. `nl_ice_data` |
| `source_version` | Version year, e.g. `2026` |
| `model_name` | e.g. `Full-network NHLe` |
| `model_window` | e.g. `2021-22 through 2025-26` |
| `source_league_name` | League label from source table |
| `mapped_league_codes` | DynastyIQ/import league abbreviations mapped to this source row |
| `points_factor` | Points NHLe factor |
| `win_shares_factor` | Win Shares NHLe factor |
| `source_url` | Article URL |
| `notes` | Optional mapping or transcription notes |

Import rules:

- Treat this markdown file as a seed input, not permanent architecture authority.
- Preserve source versions rather than overwriting old NHLe factors.
- Do not apply NHLe factors automatically unless a UI/API request explicitly asks for the NHLe lens.
- The seeder maps each source league name to itself and may add explicit aliases for imported `league_abbrev` values.
- Generic `NCAA` is currently mapped to the Independent NCAA row as a temporary fallback until conference-specific mapping is available.
- NHL remains in the table as the 1.00 baseline.

## League Factors

| Source League Name | Points NHLe | Win Shares NHLe | Mapped League Codes | Notes |
| --- | ---: | ---: | --- | --- |
| NHL | 1.00 | 1.00 |  | Baseline |
| SHL | 0.64 | 0.51 |  |  |
| KHL | 0.63 | 0.47 |  |  |
| AHL | 0.56 | 0.50 |  |  |
| NL | 0.54 | 0.36 |  | Swiss NL |
| Czech | 0.45 | 0.30 |  | Czech Extraliga |
| Liiga | 0.40 | 0.32 |  |  |
| Allsvenskan | 0.38 | 0.25 |  | HockeyAllsvenskan |
| VHL | 0.37 | 0.21 |  |  |
| DEL | 0.36 | 0.32 |  |  |
| Hockey East | 0.34 | 0.23 |  | NCAA division |
| Big Ten | 0.29 | 0.24 |  | NCAA division |
| Slovakia | 0.29 | 0.19 |  |  |
| ICEHL | 0.29 | 0.22 |  |  |
| NCHC | 0.29 | 0.23 |  | NCAA division |
| Belarus | 0.26 | 0.18 |  |  |
| ECHL | 0.26 | 0.19 |  |  |
| WCHA | 0.25 | 0.17 |  | NCAA division |
| Czech2 | 0.24 | 0.14 |  |  |
| USHL | 0.23 | 0.16 |  |  |
| CCHA | 0.23 | 0.15 |  | NCAA division |
| ECAC | 0.22 | 0.16 |  | NCAA division |
| EIHL | 0.22 | 0.16 |  |  |
| Atlantic Hockey | 0.21 | 0.15 |  | NCAA division |
| DEL2 | 0.21 | 0.14 |  |  |
| Independent | 0.21 | 0.14 |  | NCAA independents |
| OHL | 0.19 | 0.15 |  |  |
| Denmark | 0.19 | 0.14 |  |  |
| Norway | 0.19 | 0.13 |  |  |
| Mestis | 0.18 | 0.12 |  |  |
| SL | 0.18 | 0.11 |  |  |
| Magnus | 0.18 | 0.13 |  | France Ligue Magnus |
| Kazakhstan | 0.18 | 0.11 |  |  |
| SPHL | 0.17 | 0.10 |  |  |
| Poland | 0.17 | 0.11 |  |  |
| WHL | 0.17 | 0.12 |  |  |
| MHL | 0.16 | 0.10 |  |  |
| HockeyEttan | 0.15 | 0.09 |  |  |
| Erste Liga | 0.15 | 0.11 |  |  |
| QMJHL | 0.14 | 0.10 |  |  |
| USports | 0.14 | 0.09 |  |  |
| BCHL | 0.13 | 0.10 |  |  |
| Latvia | 0.13 | 0.09 |  |  |
| U20 Finland | 0.12 | 0.07 |  |  |
| J20 Nationell | 0.12 | 0.08 |  |  |
| AlpsHL | 0.11 | 0.07 |  |  |
| NAHL | 0.10 | 0.06 |  |  |
| MyHL | 0.10 | 0.05 |  |  |
| France2 | 0.10 | 0.06 |  |  |
| Slovakia2 | 0.10 | 0.08 |  |  |
| AJHL | 0.10 | 0.06 |  |  |
| NCAA III | 0.09 | 0.06 |  |  |
| Czech U20 | 0.08 | 0.05 |  |  |
| U20 Swiss | 0.08 | 0.04 |  |  |
| OJHL | 0.07 | 0.04 |  |  |
| France3 | 0.05 | 0.03 |  |  |
| DNL U20 | 0.05 | 0.03 |  |  |
| Slovakia U20 | 0.04 | 0.03 |  |  |
| U17 Swiss | 0.04 | 0.02 |  |  |
| France U20 | 0.04 | 0.02 |  |  |
| U20 Swiss Top | 0.03 | 0.01 |  |  |
| France U18 | 0.02 | 0.01 |  |  |
