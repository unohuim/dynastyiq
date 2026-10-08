# EV G/SOG — Train, pregame context and Test

Research snapshot: 8 October 2026. Local DynastyIQ data, Sep2026 research.

**Metric:** goals per 100 shots on goal. Performance cells contain units per 100, not error percentages. Retain buckets with **confidence ≥50%**, including exactly 50%.

**Train:** 2022–23 through 2024–25. **LSEASON:** 2024–25. **Test:** 2025–26 regular season.

## Performance

For every included Test game, its exact lineup supplies Train, pregame and Test values. Recent windows update before every appearance. Lineups combine individual player rates; there is no independent team estimate.

| Player / lineup | TRAIN | L5 | L10 | L20 | LSEASON | Q1 | Q2 | Q3 | Q4 | TEST | ET | ELS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Forsberg | 11.62 | 13.80 | 13.46 | 12.39 | 8.87 | 14.33 | 14.36 | 9.41 | 19.13 | 14.15 | +21.77% | +59.53% |
| McDavid | 12.57 | 12.36 | 12.64 | 12.11 | 11.26 | 9.92 | 16.72 | 10.32 | 15.03 | 13.01 | +3.50% | +15.54% |
| Pastrnak | 12.82 | 10.71 | 11.91 | 11.91 | 12.97 | 12.56 | 7.04 | 8.18 | 9.78 | 9.41 | −26.60% | −27.45% |
| Coleman | 7.19 | 10.49 | 9.86 | 9.12 | 4.67 | 13.55 | 7.53 | 8.24 | 17.80 | 10.88 | +51.32% | +132.98% |
| Matthews | 12.71 | 11.89 | 12.52 | 12.66 | 9.60 | 13.64 | 11.91 | 6.84 | — | 10.85 | −14.63% | +13.02% |
| League player average | 9.25 | 9.88 | 9.87 | 9.89 | 9.44 | 9.56 | 9.78 | 10.24 | 10.45 | 9.93 | +7.35% | +5.19% |
| CBJ lineup | 9.87 | 9.52 | 9.60 | 9.65 | 10.40 | 9.28 | 8.83 | 9.93 | 9.11 | 9.30 | −5.78% | −10.58% |
| CHI lineup | 7.73 | 10.13 | 9.84 | 9.71 | 8.24 | 11.12 | 6.48 | 12.14 | 12.59 | 9.98 | +29.11% | +21.12% |
| DAL lineup | 9.76 | 12.16 | 11.70 | 10.76 | 9.49 | 17.80 | 12.37 | 8.34 | 12.93 | 12.34 | +26.43% | +30.03% |
| FLA lineup | 8.11 | 8.55 | 7.81 | 7.06 | 8.00 | 9.90 | 6.92 | 2.64 | 12.61 | 9.41 | +16.03% | +17.63% |
| LAK lineup | 8.89 | 9.04 | 9.21 | 9.33 | 9.32 | 8.86 | 8.41 | 8.02 | — | 8.45 | −4.95% | −9.33% |
| PHI lineup | 8.89 | 10.30 | 10.10 | 10.41 | 9.40 | 10.33 | 11.59 | 8.61 | — | 10.65 | +19.80% | +13.30% |
| WPG lineup | — | — | — | — | — | — | — | — | — | — | — | — |
| WSH lineup | 10.40 | 9.95 | 9.90 | 10.01 | 13.83 | 10.23 | 11.55 | 9.16 | 9.04 | 10.13 | −2.60% | −26.75% |
| League lineup average | 9.38 | 10.02 | 9.97 | 9.96 | 9.91 | 10.03 | 9.38 | 9.86 | 10.77 | 9.99 | +6.50% | +0.81% |

## Player error thresholds

All eligible players, not only the five named examples. Each player counts once; thresholds use absolute ET/ELS and strict <10% and <20% cutoffs.

| Comparison | Eligible players | Players <10% error | Players <20% error |
| --- | ---: | ---: | ---: |
| ET | 703 | 14.37% | 28.31% |
| ELS | 656 | 15.70% | 27.59% |

ET: 101 of 703 below 10%; 199 below 20%. ELS: 103 of 656 below 10%; 181 below 20%. The matched population contains 791 players and 44,064 appearances. Undefined ratios and zero reference values are excluded from the corresponding error denominator, not assigned zero error.

## Standard columns and calculations

- **TRAIN:** S1–S3 player rates, assembled from each Test game's actual lineup.
- **L5, L10, L20:** preceding measured appearances, updated before every Test game; history follows each player across teams.
- **LSEASON:** previous-season player rates for those same members.
- **Q1–Q4:** appearances/games 1–20, 21–40, 41–60 and 61–82. Player rows use player appearance numbers; lineup rows use schedule game numbers. Missing games are not renumbered.
- **TEST:** combined included Test appearances, not an unweighted mean of Q1–Q4.
- **ET:** (TEST − TRAIN) / TRAIN × 100.
- **ELS:** (TEST − LSEASON) / LSEASON × 100.

Positive means Test increased; negative means Test decreased. ET and ELS use the displayed two-decimal rates and the original reference as denominator. Apply player thresholds before rounding the resulting percentage.

**Ratio calculation:** 100 × sum(goals/60) ÷ sum(SOG/60). For a lineup, sum the exact members' numerator and denominator rates first; never add or average their individual percentages. For each column or block, sum its applicable game-level rates before dividing. This preserves the earlier lineup conversion analysis's rate-based weighting; it is not a pooled raw-count shooting percentage or an unweighted average of game percentages.

## Measurement and coverage

Use model 4's level-1 shot-type/distance buckets with confidence at least 0.5. Include only EV shot attempts; exclude shootouts and empty-net attempts. Both numerator and denominator use the same retained buckets. Goals come from is_goal on those shot facts; this is observed performance, not expected goals.

Player historical-window /60 rates pool counts and measured EV seconds. Actual game /60 rates use that game's EV seconds. The reference and actual rates then feed the ratio calculation above. Zero-attempt appearances remain in the sample; a zero denominator for a complete displayed ratio produces —. A valid zero numerator with a positive denominator remains 0.00.

Every included player appearance has Train, last-season, recent-window and actual EV data. Every member of an included lineup must satisfy these conditions; otherwise exclude that whole game from all columns. No missing player is silently dropped from one side. League player rows combine all eligible player-game rates; league lineup rows combine eligible lineup-game sums, not just the named rows.

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

TEST is the matched subset, not necessarily all 82 games. WPG has no fully matched lineup games. Florida's Q3 contains one eligible game. League block cohorts change as fewer players reach later appearances.

Related reports: [SAT/60](sat60.md), [TOI/GP](toi-gp.md), [SOG/SAT](sog-sat.md).

## Read-only calculation source

No predictions, profiles, jobs or database records were changed.

```sql
WITH qualified AS MATERIALIZED (
SELECT bucket_key FROM nhl_expected_goals_model_buckets WHERE expected_goals_model_id=4 AND fallback_level=1 AND confidence_score>=.5
), counts AS MATERIALIZED (
SELECT f.nhl_game_id,f.shooter_player_id nhl_player_id,count(*) sat,
count(*) FILTER(WHERE f.is_shot_on_goal) sog,count(*) FILTER(WHERE f.is_goal) goals,sum(coalesce(gb.smoothed_goal_probability,league.smoothed_goal_probability)) xg
FROM nhl_shot_attempts_facts f JOIN qualified q ON q.bucket_key='L01|shot_type_group='||COALESCE(NULLIF(f.shot_type_bucket,''),'unknown')||'|distance_group='||CASE WHEN f.shot_distance IS NULL THEN 'unknown' WHEN f.shot_distance<60 THEN 'd_'||lpad((floor(GREATEST(0,f.shot_distance)/5)*5)::int::text,3,'0')||'_'||lpad((floor(GREATEST(0,f.shot_distance)/5)*5+5)::int::text,3,'0') ELSE 'd_060_plus' END
LEFT JOIN nhl_expected_goals_model_buckets gb ON gb.expected_goals_model_id=3 AND gb.bucket_key='L01|distance_group='||CASE WHEN f.shot_distance IS NULL THEN 'unknown' WHEN f.shot_distance<60 THEN 'd_'||lpad((floor(GREATEST(0,f.shot_distance)/5)*5)::int::text,3,'0')||'_'||lpad((floor(GREATEST(0,f.shot_distance)/5)*5+5)::int::text,3,'0') ELSE 'd_060_plus' END||'|angle_group='||CASE WHEN f.abs_shot_angle IS NULL THEN 'unknown' WHEN f.abs_shot_angle<=90 THEN 'a_'||lpad((least(floor(f.abs_shot_angle/10),8)*10)::int::text,3,'0')||'_'||lpad((least(floor(f.abs_shot_angle/10),8)*10+10)::int::text,3,'0') ELSE 'invalid_gt_90' END
CROSS JOIN (SELECT smoothed_goal_probability FROM nhl_expected_goals_model_buckets WHERE expected_goals_model_id=3 AND fallback_level=99) league
WHERE f.season_id IN ('20222023','20232024','20242025','20252026')
AND f.is_shot_attempt AND LOWER(COALESCE(NULLIF(f.strength_bucket,''),f.strength))='ev'
AND COALESCE(f.period_type,'')<>'SO' AND NOT COALESCE(f.is_empty_net,false)
GROUP BY 1,2
), base AS MATERIALIZED (
SELECT b.nhl_player_id,g.nhl_game_id,g.season_id,g.start_time_utc,s.toi,
coalesce(c.sat,0) sat,coalesce(c.sog,0) sog,coalesce(c.goals,0) goals,coalesce(c.xg,0) xg
FROM nhl_boxscores b JOIN nhl_games g USING(nhl_game_id)
JOIN nhl_player_game_strength_summaries s ON s.nhl_game_id=b.nhl_game_id AND s.nhl_player_id=b.nhl_player_id AND s.strength='EV'
LEFT JOIN counts c ON c.nhl_game_id=b.nhl_game_id AND c.nhl_player_id=b.nhl_player_id
WHERE g.game_type=2 AND g.game_state IN ('OFF','FINAL') AND g.season_id IN ('20222023','20232024','20242025','20252026')
AND b.position<>'G' AND b.toi_seconds>0 AND s.toi>0
AND EXISTS(SELECT 1 FROM nhl_shot_attempts_facts f WHERE f.nhl_game_id=g.nhl_game_id)
), player_train AS (
SELECT nhl_player_id,sum(sat)*3600.0/nullif(sum(toi),0) train_sat,sum(sog)*3600.0/nullif(sum(toi),0) train_sog,sum(goals)*3600.0/nullif(sum(toi),0) train_goals,
sum(sat) FILTER(WHERE season_id='20242025')*3600.0/nullif(sum(toi) FILTER(WHERE season_id='20242025'),0) ls_sat,
sum(sog) FILTER(WHERE season_id='20242025')*3600.0/nullif(sum(toi) FILTER(WHERE season_id='20242025'),0) ls_sog,sum(goals) FILTER(WHERE season_id='20242025')*3600.0/nullif(sum(toi) FILTER(WHERE season_id='20242025'),0) ls_goals
FROM base WHERE season_id<>'20252026' GROUP BY nhl_player_id
), history AS MATERIALIZED (
SELECT *,
sum(sat) OVER w5*3600.0/nullif(sum(toi) OVER w5,0) l5_sat,sum(sog) OVER w5*3600.0/nullif(sum(toi) OVER w5,0) l5_sog,sum(goals) OVER w5*3600.0/nullif(sum(toi) OVER w5,0) l5_goals,
sum(sat) OVER w10*3600.0/nullif(sum(toi) OVER w10,0) l10_sat,sum(sog) OVER w10*3600.0/nullif(sum(toi) OVER w10,0) l10_sog,sum(goals) OVER w10*3600.0/nullif(sum(toi) OVER w10,0) l10_goals,
sum(sat) OVER w20*3600.0/nullif(sum(toi) OVER w20,0) l20_sat,sum(sog) OVER w20*3600.0/nullif(sum(toi) OVER w20,0) l20_sog,sum(goals) OVER w20*3600.0/nullif(sum(toi) OVER w20,0) l20_goals,
max(start_time_utc) OVER w20 latest_context_time
FROM base WINDOW w5 AS (PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 5 PRECEDING AND 1 PRECEDING),
w10 AS (PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 10 PRECEDING AND 1 PRECEDING),
w20 AS (PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING)
), schedule AS (
SELECT g.nhl_game_id,g.start_time_utc,t.team_id,t.abbrev,row_number() OVER(PARTITION BY t.team_id ORDER BY g.start_time_utc,g.nhl_game_id) n
FROM nhl_games g CROSS JOIN LATERAL (VALUES(g.home_team_id,g.home_team_abbrev),(g.away_team_id,g.away_team_abbrev)) t(team_id,abbrev)
WHERE g.season_id='20252026' AND g.game_type=2 AND g.game_state IN ('OFF','FINAL')
), paired_players AS MATERIALIZED (
SELECT s.*,b.nhl_player_id,b.player_name,
t.train_sat,t.train_sog,t.train_goals,t.ls_sat,t.ls_sog,t.ls_goals,h.l5_sat,h.l5_sog,h.l10_sat,h.l10_sog,h.l20_sat,h.l20_sog,h.l5_goals,h.l10_goals,h.l20_goals,
h.sat*3600.0/nullif(h.toi,0) actual_sat,h.sog*3600.0/nullif(h.toi,0) actual_sog,h.goals*3600.0/nullif(h.toi,0) actual_goals,
h.latest_context_time
FROM schedule s JOIN nhl_boxscores b ON b.nhl_game_id=s.nhl_game_id AND b.nhl_team_id=s.team_id AND b.position<>'G' AND b.toi_seconds>0
LEFT JOIN history h ON h.nhl_game_id=b.nhl_game_id AND h.nhl_player_id=b.nhl_player_id
LEFT JOIN player_train t ON t.nhl_player_id=b.nhl_player_id
WHERE s.n<=82
), lineup_games AS MATERIALIZED (
SELECT team_id,min(abbrev) abbrev,nhl_game_id,min(n) n,
count(*) members,count(DISTINCT nhl_player_id) unique_members,
count(*) FILTER(WHERE train_sat IS NULL) no_train,
count(*) FILTER(WHERE ls_sat IS NULL) no_lastseason,
count(*) FILTER(WHERE l5_sat IS NULL OR l10_sat IS NULL OR l20_sat IS NULL) no_recent,
count(*) FILTER(WHERE actual_sat IS NULL) no_test,
count(*) FILTER(WHERE latest_context_time>=start_time_utc) future_leak,
sum(train_sat) train_sat,sum(train_sog) train_sog,sum(ls_sat) ls_sat,sum(ls_sog) ls_sog,
sum(l5_sat) l5_sat,sum(l5_sog) l5_sog,sum(l10_sat) l10_sat,sum(l10_sog) l10_sog,sum(l20_sat) l20_sat,sum(l20_sog) l20_sog,
sum(actual_sat) actual_sat,sum(actual_sog) actual_sog,sum(train_goals) train_goals,sum(ls_goals) ls_goals,sum(l5_goals) l5_goals,sum(l10_goals) l10_goals,sum(l20_goals) l20_goals,sum(actual_goals) actual_goals
FROM paired_players GROUP BY team_id,nhl_game_id
), paired_games AS (
SELECT * FROM lineup_games WHERE no_train=0 AND no_lastseason=0 AND no_recent=0 AND no_test=0 AND members=unique_members AND future_leak=0
), numbered AS (
SELECT *,row_number() OVER(PARTITION BY nhl_player_id ORDER BY start_time_utc,nhl_game_id) pn FROM paired_players
), eligible AS MATERIALIZED (
SELECT * FROM numbered WHERE pn<=82 AND train_sat IS NOT NULL AND ls_sat IS NOT NULL AND l5_sat IS NOT NULL AND l10_sat IS NOT NULL AND l20_sat IS NOT NULL AND actual_sat IS NOT NULL AND latest_context_time<start_time_utc
), samples AS (
SELECT 'player' kind,nhl_player_id::text id,player_name name,pn block_n,train_sat,train_sog,train_goals,l5_sat,l5_sog,l5_goals,l10_sat,l10_sog,l10_goals,l20_sat,l20_sog,l20_goals,ls_sat,ls_sog,ls_goals,actual_sat,actual_sog,actual_goals FROM eligible
UNION ALL SELECT 'lineup',team_id::text,abbrev,n,train_sat,train_sog,train_goals,l5_sat,l5_sog,l5_goals,l10_sat,l10_sog,l10_goals,l20_sat,l20_sog,l20_goals,ls_sat,ls_sog,ls_goals,actual_sat,actual_sog,actual_goals FROM paired_games
), aggregated AS (
SELECT CASE WHEN grouping(id)=1 THEN 'league' ELSE kind END kind,CASE WHEN grouping(id)=1 THEN kind ELSE id END id,CASE WHEN grouping(id)=1 THEN kind ELSE min(name) END name,
round(100*sum(train_goals) /nullif(sum(train_sog) ,0),2) train,round(100*sum(l5_goals) /nullif(sum(l5_sog) ,0),2) l5,round(100*sum(l10_goals) /nullif(sum(l10_sog) ,0),2) l10,round(100*sum(l20_goals) /nullif(sum(l20_sog) ,0),2) l20,round(100*sum(ls_goals) /nullif(sum(ls_sog) ,0),2) ls,
round(100*sum(actual_goals) FILTER(WHERE block_n<=20)/nullif(sum(actual_sog) FILTER(WHERE block_n<=20),0),2) q1,round(100*sum(actual_goals) FILTER(WHERE block_n BETWEEN 21 AND 40)/nullif(sum(actual_sog) FILTER(WHERE block_n BETWEEN 21 AND 40),0),2) q2,round(100*sum(actual_goals) FILTER(WHERE block_n BETWEEN 41 AND 60)/nullif(sum(actual_sog) FILTER(WHERE block_n BETWEEN 41 AND 60),0),2) q3,round(100*sum(actual_goals) FILTER(WHERE block_n BETWEEN 61 AND 82)/nullif(sum(actual_sog) FILTER(WHERE block_n BETWEEN 61 AND 82),0),2) q4,
round(100*sum(actual_goals) /nullif(sum(actual_sog) ,0),2) test,
count(*) FILTER(WHERE block_n<=20) c1,count(*) FILTER(WHERE block_n BETWEEN 21 AND 40) c2,count(*) FILTER(WHERE block_n BETWEEN 41 AND 60) c3,count(*) FILTER(WHERE block_n>60) c4,count(*) total
FROM samples GROUP BY GROUPING SETS ((kind,id),(kind))
), errors AS (
SELECT *,100*(test-train)/nullif(train,0) et,100*(test-ls)/nullif(ls,0) els FROM aggregated
)
SELECT json_build_object('rows',(SELECT json_agg(errors) FROM errors WHERE kind='league' OR (kind='player' AND id IN ('8476887','8478402','8477956','8476399','8479318')) OR (kind='lineup' AND name IN ('CBJ','CHI','DAL','FLA','LAK','PHI','WPG','WSH'))),
'thresholds',(SELECT json_build_object('players',count(*),'et_n',count(et),'et10',count(*) FILTER(WHERE abs(et)<10),'et20',count(*) FILTER(WHERE abs(et)<20),'els_n',count(els),'els10',count(*) FILTER(WHERE abs(els)<10),'els20',count(*) FILTER(WHERE abs(els)<20)) FROM errors WHERE kind='player'));
```

