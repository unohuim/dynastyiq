# Integration Documentation

This directory indexes provider API contracts, observed response semantics, and integration-specific usage notes.

## CapWages

- `CAPWAGES_API.md`: Subscriber API contract, authentication, endpoints, parameters, response envelopes, payload examples, and error responses.

## CBS Sports

- `CBS_NHL.md`: Public NHL injury-table and general NHL RSS observations, parsing guidance, and source limitations.

## Fantrax

- `fantrax-api.md`: Fantrax API usage notes.
- `fantrax-responses/README.md`: Observed Fantrax response documentation.

## NHL

- `nhl-responses/README.md`: NHL endpoint inventory and response semantics.
- `gner8-nhl-season-stats.md`: Gner8-facing NHL season-stat integration contract.

## RotoWire

- `ROTOWIRE_NHL.md`: NHL player-news RSS and projected starting-goalie JSON observations, identity guidance, and source limitations.

## Other Providers

- `mlb-responses/README.md`: MLB response documentation.
- `odds-api-net.md`: Odds-API.net usage notes.
- `the-odds-api.md`: The Odds API usage notes.
- `yahoo-api.md`: Yahoo API usage notes.

Provider-specific implementation work must also follow the applicable canonical architecture files under `docs/architecture/`.
