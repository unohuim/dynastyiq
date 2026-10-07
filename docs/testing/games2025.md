# First ten Test games — EV SAT/60

All qualifying buckets combined: EV SAT/60, bucket confidence ≥50%.
G1–G10 are each player's first ten available Test appearances, not shared calendar dates.

- **Train:** pooled S1–S3 (2022–23, 2023–24, 2024–25).
- **Last 5 / Last 10 / Last 20:** pregame context immediately before G1, using earlier regular-season appearances. These columns stay fixed; they do not incorporate G1–G10.
- **Last season:** 2024–25 regular-season SAT/60.
- **G1–G10:** actual SAT/60 for each Test game in 2025–26.
- **Test:** pooled SAT/60 across G1–G10, not the entire Test season or a simple average of the ten rates.

## Player comparison

Fixed-width text preserves column alignment in both Markdown source and preview. Scroll horizontally if the table is wider than the window.

```text
Name            Train  Last 5  Last 10  Last 20  Last season     G1     G2     G3     G4     G5     G6     G7     G8     G9    G10   Test
--------------  -----  ------  -------  -------  -----------  -----  -----  -----  -----  -----  -----  -----  -----  -----  -----  -----
Forsberg        21.84   15.01    16.20    17.67        21.15  35.06  17.73   0.00  27.57  14.98  23.66  15.09  23.38  11.43  22.28  19.36
McDavid         14.18   13.00    11.54    12.63        13.33  12.63  31.24   9.30   9.37  10.81   8.96  10.50   8.03   0.00  21.58  11.79
Pastrnak        22.02   11.89    17.71    18.39        20.44  25.33  20.43  25.06  30.25  22.60  15.86  26.14  19.01  20.45   7.74  21.49
Demidov         21.24   21.24    21.24    21.24        21.24   9.65   5.37   7.26   9.47  23.97  12.69   5.07   9.33  17.01  18.62  12.13
Matthews        21.56   14.00    14.75    16.70        19.46   7.17  32.21  39.85  14.17   6.66  13.37  19.76  16.30  14.43  13.19  17.86
League average  11.73   11.62    11.57    11.62        11.73  11.60  10.98  11.21  10.80  11.36  11.29  11.53  11.28  11.83  11.23  11.31
```

## Reading notes

- League average pools available skaters, not just these five players. Game columns group the same appearance number; rates are weighted by EV exposure. Train and Last season use their respective league-wide source records.
- Demidov has only two prior NHL appearances before G1. His pregame windows use those available appearances.
- Positive-EV-exposure appearances with zero attempts remain included. Train and Last season were calculated from source records with those appearances included; this corrects the earlier inflated Train figures in the conversation, without changing saved profile-building logic.
- Confidence filtering uses expected-goals model #4's trained shot-bucket confidence, not a player's confidence or a forecast's accuracy.
- Train and Last season are pooled rates, not unweighted averages of seasonal or game rates.
- Test and the individual game results are retrospective comparisons, not pregame prediction inputs.
- This report combines buckets into player totals; it does not establish individual bucket repeatability.

Source: local database, Sep2026 model #1, next-game evaluation #2; extracted October 7, 2026. This is a saved analysis snapshot, not a live report or a change to predictions.
