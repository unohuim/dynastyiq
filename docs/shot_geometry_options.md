# Shot geometry implementation options

Context: add angle (degrees) and distance (feet) from the shooter's location to the defending goalie net for all shot-attempt events during NHL play-by-play imports, using rink coordinates (`xCoord`, `yCoord`) and `homeTeamDefendingSide` to infer the target net. Missing or ambiguous data should be inferred when possible and otherwise left null.

## Option A: compute and store during import
- Add nullable `angle` (degrees) and `distance` (feet) columns to `play_by_plays` via migration.
- Extend the importer to derive the defending net per play using `homeTeamDefendingSide`, period parity, and `eventOwnerTeamId`, then compute distance (Euclidean) and angle (atan2 relative to the goal line toward the net) when coordinates are present.
- Store results immediately with the imported row; leave fields null only when inference is impossible.
- Pros: freshest data, no post-processing; aligns with "import only" constraint. Cons: importer grows more complex; requires migration deployment coordination.

## Option B: shared geometry helper + backfill/import hook
- Add the two columns in the existing play-by-play migration, encapsulate net-side resolution and geometry math in a dedicated helper/service.
- Use the helper from a CLI backfill command for existing rows and wire it into import paths as needed without duplicating inference logic.
- Pros: keeps importer thinner, centralizes rules for side inference and angle math, and gives a reusable backfill path. Cons: slight indirection; requires touching the original migration and maintaining the helper API.

## Option C: lazy compute with caching on read
- Add columns and create model accessors that compute angle/distance when first accessed, persisting the values for future reads.
- Use the same helper logic for net-side inference; importer remains unchanged initially.
- Pros: minimal importer changes; backfills opportunistically. Cons: first read incurs compute/write, and values remain null until accessed; more database writes during read paths.
