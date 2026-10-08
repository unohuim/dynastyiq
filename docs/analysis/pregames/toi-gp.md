# EV TOI/GP — Train, pregame context and Test

Research snapshot: 8 October 2026. Local DynastyIQ data, Sep2026 research.

**Metric:** EV minutes per game played. Decimal minutes: 15.50 means 15 minutes 30 seconds. No shot-bucket confidence filter applies to ice time.

**Train:** 2022–23 through 2024–25. **LSEASON:** 2024–25. **Test:** 2025–26 regular season.

## Performance

For every included Test game, the same lineup members supply Train, pregame and Test values. Lineups are sums of individual player minutes, not independently modeled teams. Recent windows update before every appearance, then average across the included Test games.

| Player / lineup | TRAIN | L5 | L10 | L20 | LSEASON | Q1 | Q2 | Q3 | Q4 | TEST | ET | ELS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 15.20 | 15.77 | 15.78 | 15.74 | 15.43 | 15.88 | 15.59 | 15.95 | 15.99 | 15.86 | +4.34% | +2.79% |
| McDavid | 17.86 | 18.21 | 18.15 | 18.22 | 18.75 | 18.85 | 17.71 | 18.36 | 18.42 | 18.34 | +2.69% | −2.19% |
| Pastrnak | 16.17 | 16.95 | 16.89 | 16.88 | 16.72 | 15.71 | 16.68 | 17.94 | 17.79 | 17.00 | +5.13% | +1.67% |
| Coleman | 14.31 | 13.89 | 13.90 | 13.92 | 14.26 | 13.50 | 14.13 | 13.99 | 13.99 | 13.89 | −2.94% | −2.59% |
| Matthews | 16.44 | 16.87 | 16.79 | 16.65 | 15.83 | 16.40 | 16.66 | 17.58 | — | 16.88 | +2.68% | +6.63% |
| League player average | 14.26 | 14.29 | 14.31 | 14.33 | 14.42 | 13.69 | 14.24 | 14.58 | 15.10 | 14.28 | +0.14% | −0.97% |
| CBJ lineup | 263.51 | 261.17 | 261.57 | 263.01 | 271.42 | 262.69 | 252.41 | 262.48 | 260.16 | 259.74 | −1.43% | −4.30% |
| CHI lineup | 266.21 | 253.97 | 256.14 | 260.47 | 270.64 | 251.04 | 253.31 | 249.14 | 262.86 | 251.86 | −5.39% | −6.94% |
| DAL lineup | 243.97 | 253.65 | 252.63 | 252.06 | 244.84 | 253.22 | 251.32 | 264.05 | 240.70 | 253.57 | +3.93% | +3.57% |
| FLA lineup | 252.65 | 247.16 | 250.12 | 254.04 | 257.76 | 241.24 | 265.97 | 253.90 | 249.82 | 244.54 | −3.21% | −5.13% |
| LAK lineup | 256.38 | 253.78 | 253.61 | 252.28 | 264.13 | 251.46 | 252.25 | 261.54 | — | 254.97 | −0.55% | −3.47% |
| PHI lineup | 251.17 | 258.20 | 257.93 | 258.43 | 255.50 | 256.33 | 247.72 | 241.60 | — | 252.72 | +0.62% | −1.09% |
| WPG lineup | — | — | — | — | — | — | — | — | — | — | — | — |
| WSH lineup | 253.19 | 251.97 | 252.56 | 253.31 | 250.56 | 249.81 | 257.49 | 252.02 | 249.01 | 252.48 | −0.28% | +0.77% |
| League lineup average | 256.84 | 254.89 | 255.48 | 256.89 | 259.91 | 252.47 | 253.08 | 256.36 | 255.74 | 253.96 | −1.12% | −2.29% |

## Player error thresholds

All eligible players, not just the five examples. Each player counts once. Use absolute ET/ELS for the strict <10% and <20% thresholds.

| Comparison | Eligible players | Players <10% error | Players <20% error |
| --- | ---: | ---: | ---: |
| ET | 791 | 65.36% | 88.37% |
| ELS | 791 | 63.21% | 87.99% |

ET: 517 of 791 below 10%; 699 below 20%. ELS: 500 of 791 below 10%; 696 below 20%. The matched sample contains 791 players and 44,064 appearances.

## Standard columns and calculations

- **TRAIN:** measured EV seconds across S1–S3 divided by measured appearances and 60.
- **L5, L10, L20:** EV minutes averaged over measured records within the preceding 5, 10 or 20 played appearances. Windows stop before the target game and follow players across team changes. Missing records are not replaced with older games.
- **LSEASON:** previous-season measured EV minutes per appearance.
- **Q1–Q4:** appearances/games 1–20, 21–40, 41–60 and 61–82. Player rows use player appearance numbers; lineup rows use team schedule game numbers. Exclusions do not renumber games.
- **TEST:** mean EV minutes across all included appearances/games, not an unweighted mean of Q1–Q4.
- **ET:** (TEST − TRAIN) / TRAIN × 100.
- **ELS:** (TEST − LSEASON) / LSEASON × 100.

Positive means Test increased; negative means Test decreased. Forsberg ET: (15.86 − 15.20) / 15.20 × 100 = +4.34%. Comparisons use the displayed two-decimal rates and the original reference as denominator. Thresholds use the unrounded percentage calculated from those rates.

Use the same standard columns as [SAT/60](sat60.md). Keep league averages as rows and coverage separate from performance cells.

## Measurement and coverage

Source: nhl_boxscores for actual played lineups; nhl_player_game_strength_summaries.toi for EV seconds; nhl_games for regular-season ordering. Skaters require positive total boxscore ice time. A stored EV zero remains zero; missing EV data is not zero. EV follows the stored strength classification, including contexts classified EV with an empty net.

Historical averages use measured EV appearances. Each displayed player appearance must have Train, last-season, recent-window and actual EV data. Every member of an included lineup must meet these requirements; otherwise exclude the entire lineup game from every comparison column. No independent team history is substituted.

Lineup values sum player EV minutes for each game, then average those sums. They are total skater-minutes, not elapsed team-clock minutes. League player rows average eligible player appearances; league lineup rows average eligible lineup-game sums. Neither league row is restricted to the named examples.

| Player / lineup | Q1 games | Q2 games | Q3 games | Q4 games | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 20 | 20 | 20 | 22 | 82 |
| McDavid | 20 | 20 | 20 | 22 | 82 |
| Pastrnak | 20 | 20 | 20 | 17 | 77 |
| Coleman | 20 | 20 | 20 | 9 | 69 |
| Matthews | 20 | 20 | 20 | 0 | 60 |
| League player average | 14183 | 12440 | 10569 | 6872 | 44064 |
| CBJ lineup | 19 | 16 | 19 | 22 | 76 |
| CHI lineup | 20 | 11 | 8 | 2 | 41 |
| DAL lineup | 6 | 18 | 9 | 4 | 37 |
| FLA lineup | 19 | 2 | 1 | 2 | 24 |
| LAK lineup | 20 | 20 | 19 | 0 | 59 |
| PHI lineup | 20 | 10 | 2 | 0 | 32 |
| WPG lineup | 0 | 0 | 0 | 0 | 0 |
| WSH lineup | 20 | 18 | 20 | 8 | 66 |
| League lineup average | 261 | 143 | 129 | 115 | 648 |

TEST represents the matched subset, not necessarily all 82 games. WPG has no fully matched lineup games. Florida Q3 contains one eligible game. Later league player blocks contain a different mix of players as fewer reach those appearances.

The previous [TOI report](../../testing/games2025-ev-toi.htm) fixed recent windows before the first game. This report updates them before every Test appearance. Rates are rounded directly from source precision; this can also produce a 0.01 difference from earlier rounded displays.

## Read-only calculation source

No predictions, jobs, profiles or database records were changed.

```sql
WITH base AS MATERIALIZED (
SELECT b.nhl_player_id,b.player_name,b.nhl_team_id,g.nhl_game_id,g.season_id,g.start_time_utc,s.toi::numeric/60 minutes
FROM nhl_boxscores b JOIN nhl_games g USING(nhl_game_id)
LEFT JOIN nhl_player_game_strength_summaries s ON s.nhl_game_id=b.nhl_game_id AND s.nhl_player_id=b.nhl_player_id AND s.strength='EV'
WHERE g.game_type=2 AND g.game_state IN ('OFF','FINAL') AND g.season_id IN ('20222023','20232024','20242025','20252026') AND b.position<>'G' AND b.toi_seconds>0
), train AS (
SELECT nhl_player_id,avg(minutes) train,avg(minutes) FILTER(WHERE season_id='20242025') ls FROM base WHERE season_id<>'20252026' GROUP BY 1
), history AS MATERIALIZED (
SELECT *,avg(minutes) OVER w5 l5,avg(minutes) OVER w10 l10,avg(minutes) OVER w20 l20,max(start_time_utc) OVER w20 latest
FROM base WINDOW w5 AS(PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 5 PRECEDING AND 1 PRECEDING),
w10 AS(PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 10 PRECEDING AND 1 PRECEDING),
w20 AS(PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING)
), appearances AS (
SELECT h.*,t.train,t.ls,row_number() OVER(PARTITION BY h.nhl_player_id ORDER BY start_time_utc,nhl_game_id) n FROM history h LEFT JOIN train t USING(nhl_player_id) WHERE season_id='20252026'
), eligible AS MATERIALIZED (
SELECT * FROM appearances WHERE n<=82 AND minutes IS NOT NULL AND train IS NOT NULL AND ls IS NOT NULL AND l5 IS NOT NULL AND l10 IS NOT NULL AND l20 IS NOT NULL AND latest<start_time_utc
), schedule AS (
SELECT g.nhl_game_id,v.team_id,v.abbrev,row_number() OVER(PARTITION BY v.team_id ORDER BY g.start_time_utc,g.nhl_game_id) n
FROM nhl_games g CROSS JOIN LATERAL(VALUES(g.home_team_id,g.home_team_abbrev),(g.away_team_id,g.away_team_abbrev)) v(team_id,abbrev)
WHERE season_id='20252026' AND game_type=2 AND game_state IN ('OFF','FINAL')
), lineups AS MATERIALIZED (
SELECT s.team_id,s.abbrev,s.nhl_game_id,s.n,sum(e.train) train,sum(e.ls) ls,sum(e.l5) l5,sum(e.l10) l10,sum(e.l20) l20,sum(e.minutes) minutes
FROM schedule s JOIN appearances a ON a.nhl_game_id=s.nhl_game_id AND a.nhl_team_id=s.team_id
LEFT JOIN eligible e ON e.nhl_game_id=a.nhl_game_id AND e.nhl_player_id=a.nhl_player_id
WHERE s.n<=82 GROUP BY 1,2,3,4 HAVING count(*)=count(e.nhl_player_id) AND count(*)=count(DISTINCT a.nhl_player_id)
), units AS (
SELECT 'player' kind,nhl_player_id::text id,min(player_name) name,
round(avg(train),2) train,round(avg(l5),2) l5,round(avg(l10),2) l10,round(avg(l20),2) l20,round(avg(ls),2) ls,
round(avg(minutes) FILTER(WHERE n<=20),2) q1,round(avg(minutes) FILTER(WHERE n BETWEEN 21 AND 40),2) q2,round(avg(minutes) FILTER(WHERE n BETWEEN 41 AND 60),2) q3,round(avg(minutes) FILTER(WHERE n>60),2) q4,round(avg(minutes),2) test,
count(*) FILTER(WHERE n<=20) c1,count(*) FILTER(WHERE n BETWEEN 21 AND 40) c2,count(*) FILTER(WHERE n BETWEEN 41 AND 60) c3,count(*) FILTER(WHERE n>60) c4,count(*) total
FROM eligible GROUP BY nhl_player_id
UNION ALL
SELECT 'lineup',team_id::text,abbrev,round(avg(train),2),round(avg(l5),2),round(avg(l10),2),round(avg(l20),2),round(avg(ls),2),
round(avg(minutes) FILTER(WHERE n<=20),2),round(avg(minutes) FILTER(WHERE n BETWEEN 21 AND 40),2),round(avg(minutes) FILTER(WHERE n BETWEEN 41 AND 60),2),round(avg(minutes) FILTER(WHERE n>60),2),round(avg(minutes),2),
count(*) FILTER(WHERE n<=20),count(*) FILTER(WHERE n BETWEEN 21 AND 40),count(*) FILTER(WHERE n BETWEEN 41 AND 60),count(*) FILTER(WHERE n>60),count(*)
FROM lineups GROUP BY team_id,abbrev
UNION ALL
SELECT 'league',kind,kind,round(avg(train),2),round(avg(l5),2),round(avg(l10),2),round(avg(l20),2),round(avg(ls),2),
round(avg(minutes) FILTER(WHERE n<=20),2),round(avg(minutes) FILTER(WHERE n BETWEEN 21 AND 40),2),round(avg(minutes) FILTER(WHERE n BETWEEN 41 AND 60),2),round(avg(minutes) FILTER(WHERE n>60),2),round(avg(minutes),2),
count(*) FILTER(WHERE n<=20),count(*) FILTER(WHERE n BETWEEN 21 AND 40),count(*) FILTER(WHERE n BETWEEN 41 AND 60),count(*) FILTER(WHERE n>60),count(*)
FROM (SELECT 'player' kind,train,l5,l10,l20,ls,minutes,n FROM eligible UNION ALL SELECT 'lineup',train,l5,l10,l20,ls,minutes,n FROM lineups) x GROUP BY kind
), errors AS (SELECT *,100*(test-train)/nullif(train,0) et,100*(test-ls)/nullif(ls,0) els FROM units)
SELECT json_build_object('rows',(SELECT json_agg(errors) FROM errors WHERE kind='league' OR (kind='player' AND id IN ('8476887','8478402','8477956','8476399','8479318')) OR (kind='lineup' AND name IN ('CBJ','CHI','DAL','FLA','LAK','PHI','WPG','WSH'))),
'thresholds',(SELECT json_build_object('players',count(*),'et_n',count(et),'et10',count(*) FILTER(WHERE abs(et)<10),'et20',count(*) FILTER(WHERE abs(et)<20),'els_n',count(els),'els10',count(*) FILTER(WHERE abs(els)<10),'els20',count(*) FILTER(WHERE abs(els)<20)) FROM errors WHERE kind='player'));
```

