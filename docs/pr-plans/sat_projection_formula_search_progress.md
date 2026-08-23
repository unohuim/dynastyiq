SAT projection formula search started 2026-08-22 18:17:51
Loaded 674 entity rows from actual-skater-offense-aggregate-compare-60 (9).csv
Baselines:
current n=674 mae=1.2368 bias=-0.2226 mape=12.7% hit3=15.9% hit5=26.6% hit10=49.9%
train n=674 mae=1.2253 bias=-0.2507 mape=12.4% hit3=16.9% hit5=26.7% hit10=50.6%
last n=674 mae=1.2745 bias=-0.1502 mape=13.1% hit3=17.7% hit5=30.7% hit10=51.9%
s1 n=674 mae=1.6181 bias=-0.4408 mape=16.1% hit3=12.5% hit5=19.9% hit10=41.2%
avg_s1_s2 n=674 mae=1.2890 bias=-0.2955 mape=12.8% hit3=16.0% hit5=24.9% hit10=50.7%
train_last_50_50 n=674 mae=1.2077 bias=-0.2005 mape=12.2% hit3=18.4% hit5=29.2% hit10=53.6%
progress 100/1200 best_obj=1.2345 n=674 mae=1.2041 bias=-0.0408 mape=12.4% hit3=18.0% hit5=29.1% hit10=53.0%
progress 200/1200 best_obj=1.2248 n=674 mae=1.1971 bias=-0.0338 mape=12.4% hit3=17.1% hit5=28.3% hit10=52.7%
progress 300/1200 best_obj=1.2206 n=674 mae=1.1961 bias=+0.0147 mape=12.5% hit3=18.2% hit5=28.5% hit10=52.2%
progress 400/1200 best_obj=1.2206 n=674 mae=1.1961 bias=+0.0147 mape=12.5% hit3=18.2% hit5=28.5% hit10=52.2%
progress 500/1200 best_obj=1.2206 n=674 mae=1.1961 bias=+0.0147 mape=12.5% hit3=18.2% hit5=28.5% hit10=52.2%
progress 600/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 700/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 800/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 900/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 1000/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 1100/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%
progress 1200/1200 best_obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8%

Top 10 formulas:
#1 obj=1.2134 n=674 mae=1.1891 bias=+0.0036 mape=12.4% hit3=16.9% hit5=28.2% hit10=51.8% blend=1.0 active=0.1 toi=0 r={'f_high_g': 0, 'f_mid_g': -0.5, 'f_low_g': -0.75, 'd_high_g': -0.75, 'd_mid_g': 0.25, 'd_low_g': -0.75, 'other': 0} age_mult={'age_u25': 0.97, 'age_26_29': 0.985, 'age_30_33': 0.97, 'age_34p': 0.97, 'age_unknown': 0.97}
#2 obj=1.2206 n=674 mae=1.1961 bias=+0.0147 mape=12.5% hit3=18.2% hit5=28.5% hit10=52.2% blend=0.7 active=0.25 toi=0.1 r={'f_high_g': -0.75, 'f_mid_g': -0.25, 'f_low_g': -0.1, 'd_high_g': 0.1, 'd_mid_g': -0.35, 'd_low_g': -0.5, 'other': 0} age_mult={'age_u25': 0.97, 'age_26_29': 0.97, 'age_30_33': 0.985, 'age_34p': 0.97, 'age_unknown': 1.03}
#3 obj=1.2242 n=674 mae=1.2001 bias=-0.0138 mape=12.5% hit3=17.8% hit5=27.9% hit10=52.1% blend=0.5 active=0 toi=0 r={'f_high_g': -0.1, 'f_mid_g': -0.1, 'f_low_g': -0.35, 'd_high_g': -0.25, 'd_mid_g': -0.75, 'd_low_g': -0.25, 'other': -0.75} age_mult={'age_u25': 0.985, 'age_26_29': 0.97, 'age_30_33': 0.97, 'age_34p': 1.015, 'age_unknown': 0.985}
#4 obj=1.2248 n=674 mae=1.1971 bias=-0.0338 mape=12.4% hit3=17.1% hit5=28.3% hit10=52.7% blend=0.7 active=0 toi=-0.1 r={'f_high_g': 0.25, 'f_mid_g': 0.25, 'f_low_g': -0.35, 'd_high_g': -0.35, 'd_mid_g': -0.5, 'd_low_g': -0.25, 'other': 0} age_mult={'age_u25': 0.985, 'age_26_29': 0.97, 'age_30_33': 0.985, 'age_34p': 1.015, 'age_unknown': 1.015}
#5 obj=1.2306 n=674 mae=1.1909 bias=-0.1296 mape=12.2% hit3=18.1% hit5=29.7% hit10=53.6% blend=0.7 active=0.1 toi=0.1 r={'f_high_g': 0.25, 'f_mid_g': 0.25, 'f_low_g': 0.1, 'd_high_g': 0.1, 'd_mid_g': 0, 'd_low_g': -0.25, 'other': -0.35} age_mult={'age_u25': 1, 'age_26_29': 0.985, 'age_30_33': 0.985, 'age_34p': 1.015, 'age_unknown': 1.03}
#6 obj=1.2307 n=674 mae=1.2019 bias=-0.0430 mape=12.5% hit3=16.9% hit5=27.7% hit10=50.4% blend=1.0 active=-0.1 toi=0.1 r={'f_high_g': -0.35, 'f_mid_g': -0.1, 'f_low_g': -0.35, 'd_high_g': -0.5, 'd_mid_g': 0.25, 'd_low_g': -0.25, 'other': 0.25} age_mult={'age_u25': 0.985, 'age_26_29': 0.97, 'age_30_33': 0.97, 'age_34p': 1, 'age_unknown': 0.97}
#7 obj=1.2322 n=674 mae=1.1993 bias=-0.0626 mape=12.4% hit3=16.5% hit5=28.9% hit10=51.8% blend=1.0 active=-0.1 toi=0 r={'f_high_g': -0.25, 'f_mid_g': -0.75, 'f_low_g': 0.25, 'd_high_g': -0.1, 'd_mid_g': 0, 'd_low_g': -0.5, 'other': -0.25} age_mult={'age_u25': 0.97, 'age_26_29': 0.985, 'age_30_33': 0.985, 'age_34p': 0.985, 'age_unknown': 1}
#8 obj=1.2337 n=674 mae=1.2065 bias=-0.0177 mape=12.5% hit3=16.9% hit5=29.4% hit10=53.4% blend=0.7 active=-0.25 toi=-0.1 r={'f_high_g': -0.5, 'f_mid_g': 0.25, 'f_low_g': -0.75, 'd_high_g': -0.25, 'd_mid_g': -0.25, 'd_low_g': -0.1, 'other': 0} age_mult={'age_u25': 0.985, 'age_26_29': 0.97, 'age_30_33': 0.97, 'age_34p': 0.985, 'age_unknown': 0.97}
#9 obj=1.2345 n=674 mae=1.2041 bias=-0.0408 mape=12.4% hit3=18.0% hit5=29.1% hit10=53.0% blend=0.5 active=0 toi=0 r={'f_high_g': 0, 'f_mid_g': 0, 'f_low_g': 0, 'd_high_g': -0.75, 'd_mid_g': -0.75, 'd_low_g': 0, 'other': -0.25} age_mult={'age_u25': 0.97, 'age_26_29': 1, 'age_30_33': 0.985, 'age_34p': 0.985, 'age_unknown': 1.03}
#10 obj=1.2351 n=674 mae=1.2065 bias=-0.0603 mape=12.5% hit3=17.8% hit5=28.2% hit10=53.1% blend=0.3 active=0 toi=0 r={'f_high_g': 0.1, 'f_mid_g': 0, 'f_low_g': -0.35, 'd_high_g': 0.25, 'd_mid_g': -0.35, 'd_low_g': -0.5, 'other': -0.1} age_mult={'age_u25': 0.97, 'age_26_29': 1, 'age_30_33': 0.97, 'age_34p': 1.03, 'age_unknown': 1.03}

Best formula group MAE:
d_high_g 0.6571
d_low_g 1.0215
d_mid_g 0.9428
f_high_g 0.9236
f_low_g 1.4489
f_mid_g 1.2437

Improvement vs current MAE: 1.2368 -> 1.1891 (3.9% better)

Biggest current-error improvements:
Daemon Hunt D d_low_g test 6.52 current 15.99 best 9.28 cur_err -9.48 best_err -2.76 s1 8.44 s2 22.45 train 9.56
Riley Tufte L f_mid_g test 13.63 current 7.15 best 10.80 cur_err +6.48 best_err +2.83 s1 13.20 s2 4.06 train 10.96
David Jiricek D d_low_g test 6.83 current 10.03 best 7.76 cur_err -3.20 best_err -0.94 s1 7.85 s2 10.31 train 8.26
Jaycob Megna D d_low_g test 5.45 current 7.75 best 5.32 cur_err -2.31 best_err +0.13 s1 5.15 s2 8.01 train 5.48
Olle Lycksell R f_low_g test 15.08 current 9.49 best 11.65 cur_err +5.59 best_err +3.44 s1 15.96 s2 8.32 train 11.83
Arthur Kaliyev R f_low_g test 14.14 current 11.72 best 14.58 cur_err +2.42 best_err -0.44 s1 15.99 s2 11.18 train 15.03
Jason Polin R f_low_g test 9.80 current 14.18 best 12.29 cur_err -4.38 best_err -2.49 s1 11.13 s2 17.36 train 12.47
Walker Duehr R f_low_g test 16.03 current 10.49 best 12.09 cur_err +5.54 best_err +3.94 s1 13.69 s2 10.10 train 12.27
Jacob Bernard-Docker D d_mid_g test 6.86 current 8.47 best 6.90 cur_err -1.60 best_err -0.04 s1 6.31 s2 8.62 train 7.12
Mathew Dumba D d_low_g test 6.90 current 8.54 best 7.01 cur_err -1.65 best_err -0.11 s1 6.64 s2 8.33 train 7.30
Nick Perbix D d_low_g test 5.60 current 8.33 best 6.81 cur_err -2.74 best_err -1.21 s1 5.53 s2 8.46 train 6.91
Chris Tanev D d_low_g test 3.97 current 5.86 best 4.44 cur_err -1.89 best_err -0.48 s1 4.50 s2 4.68 train 4.58
Declan Carlile D d_high_g test 7.05 current 9.42 best 8.07 cur_err -2.37 best_err -1.02 s1 5.24 s2 9.23 train 8.32
Martin Fehérváry D d_mid_g test 5.39 current 7.03 best 5.72 cur_err -1.65 best_err -0.34 s1 5.35 s2 6.36 train 5.90
Owen Power D d_mid_g test 6.47 current 7.94 best 6.65 cur_err -1.47 best_err -0.19 s1 6.03 s2 7.70 train 6.86

Biggest best-error misses:
Zack MacEwen C f_low_g test 25.02 best 14.11 err +10.91 s1 12.98 s2 15.62 train 14.33 active_delta +0.00 toi_delta +0.16
Mikael Pyyhtia L f_low_g test 16.39 best 8.07 err +8.33 s1 8.53 s2 8.19 train 8.32 active_delta +0.00 toi_delta -0.12
Raphael Lavoie C f_low_g test 7.00 best 14.50 err -7.49 s1 12.13 s2 16.28 train 14.95 active_delta +0.00 toi_delta +0.41
Bradly Nadeau L f_low_g test 11.31 best 17.97 err -6.66 s1 15.68 s2 20.90 train 18.53 active_delta +0.00 toi_delta -0.40
Connor Mackey D d_low_g test 9.67 best 3.64 err +6.03 s1 3.65 s2 3.72 train 3.70 active_delta +0.00 toi_delta -0.02
Bokondji Imama L f_low_g test 18.65 best 13.29 err +5.36 s1 15.39 s2 12.83 train 13.49 active_delta +0.00 toi_delta -0.04
Marc McLaughlin C f_mid_g test 6.33 best 11.68 err -5.35 s1 9.48 s2 12.18 train 11.86 active_delta +0.00 toi_delta -0.26
Sonny Milano L f_mid_g test 13.24 best 7.98 err +5.27 s1 7.97 s2 11.09 train 8.10 active_delta +0.00 toi_delta -0.44
Dylan Coghlan D d_low_g test 6.64 best 11.81 err -5.17 s1 13.35 s2 11.72 train 11.99 active_delta +0.00 toi_delta -0.15
Liam Foudy C f_low_g test 5.32 best 10.33 err -5.02 s1 10.85 s2 8.53 train 10.65 active_delta +0.00 toi_delta -0.27
John Beecher C f_low_g test 12.45 best 7.74 err +4.71 s1 7.88 s2 8.04 train 7.98 active_delta +0.00 toi_delta +0.02
Daniil Miromanov D d_mid_g test 3.37 best 7.92 err -4.55 s1 9.14 s2 7.21 train 8.04 active_delta +0.00 toi_delta -0.13
Carl Grundstrom R f_low_g test 10.77 best 15.08 err -4.31 s1 15.44 s2 15.16 train 15.31 active_delta +0.00 toi_delta -0.11
Brennan Othmann L f_low_g test 9.79 best 14.03 err -4.25 s1 21.02 s2 13.64 train 14.47 active_delta +0.00 toi_delta -0.01
Darren Raddysh D d_mid_g test 12.23 best 8.01 err +4.22 s1 7.09 s2 9.32 train 8.13 active_delta +0.00 toi_delta -0.08
Finished in 3.8s
