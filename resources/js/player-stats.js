// player-stats.js

const teamGradients = {
  ANA: 'linear-gradient(to bottom, #FF6F00, #000000)',
  ARI: 'linear-gradient(to bottom, #8C2633, #000000)',
  BOS: 'linear-gradient(to bottom, #FFB81C, #000000)',
  BUF: 'linear-gradient(to bottom, #002654, #FDBB2F)',
  CGY: 'linear-gradient(to bottom, #C8102E, #F1BE48)',
  CAR: 'linear-gradient(to bottom, #CC0000, #000000)',
  CHI: 'linear-gradient(to bottom, #CF0A2C, #000000)',
  COL: 'linear-gradient(to bottom, #6F263D, #236192)',
  CBJ: 'linear-gradient(to bottom, #002654, #A6A6A6)',
  DAL: 'linear-gradient(to bottom, #006847, #000000)',
  DET: 'linear-gradient(to bottom, #CE1126, #FFFFFF)',
  EDM: 'linear-gradient(to bottom, #FF4C00, #041E42)',
  FLA: 'linear-gradient(to bottom, #041E42, #C8102E)',
  LAK: 'linear-gradient(to bottom, #A2AAAD, #000000)',
  MIN: 'linear-gradient(to bottom, #154734, #A6192E)',
  MTL: 'linear-gradient(to bottom, #AF1E2D, #192168)',
  NSH: 'linear-gradient(to bottom, #FFB81C, #041E42)',
  NJD: 'linear-gradient(to bottom, #CE1126, #000000)',
  NYI: 'linear-gradient(to bottom, #00539B, #F47D30)',
  NYR: 'linear-gradient(to bottom, #0038A8, #CE1126)',
  OTT: 'linear-gradient(to bottom, #E31837, #000000)',
  PHI: 'linear-gradient(to bottom, #FA4616, #000000)',
  PIT: 'linear-gradient(to bottom, #FFB81C, #000000)',
  SEA: 'linear-gradient(to bottom, #001628, #99D9D9)',
  SJS: 'linear-gradient(to bottom, #006D75, #000000)',
  STL: 'linear-gradient(to bottom, #002F87, #FDB827)',
  TBL: 'linear-gradient(to bottom, #002868, #00529B)',
  TOR: 'linear-gradient(to bottom, #00205B, #003E7E)',
  VAN: 'linear-gradient(to bottom, #00205B, #00843D)',
  VGK: 'linear-gradient(to bottom, #B4975A, #333F48)',
  WSH: 'linear-gradient(to bottom, #C8102E, #041E42)',
  WPG: 'linear-gradient(to bottom, #041E42, #7B303D)',
};

export function registerPlayerStatsStore() {
  document.addEventListener('alpine:init', () => {
    Alpine.store('playerStats', {
      query: '',
      sortField: 'PTS',
      sortDirection: 'desc',
      sortOpen: false,
      expanded: [],
      visibleCount: 50,
      players: [],

      labelMap: {
        PTS: 'PTS',
        G: 'G',
        A: 'A',
        GP: 'GP',
        age: 'Age',
        AVGPTS: 'P',
        avgPTSpGP: 'P/GP',
        AVGTOI: 'TOI',
        shooting_percentage: 'SH%',
        pos_type: 'POS',
        player_name: 'Player',
      },


      init(el) {
        this.players = window.__playerStats || [];
        this.visibleCount = 100;
        this.loading = false;

        const waitForSentinel = () => {
          const sentinels = document.querySelectorAll('[x-ref="sentinel"]');
          const visibleSentinel = Array.from(sentinels).find(s => s.offsetParent !== null); // Only visible one

          if (!visibleSentinel) {
            console.warn('⏳ Waiting for visible sentinel...');
            setTimeout(waitForSentinel, 10);
          } else {
            console.log('✅ Visible sentinel found:', visibleSentinel);
            this.createObserver({ querySelector: () => visibleSentinel });
          }
        };


        waitForSentinel();
      },

      get filteredAndSorted() {
        const q = this.query.toLowerCase();
        let result = this.players.filter(p => p.player_name?.toLowerCase().includes(q));

        result.sort((a, b) => {
          const aVal = this.sortField === 'age' ? a.age : a[this.sortField];
          const bVal = this.sortField === 'age' ? b.age : b[this.sortField];
          return this.sortDirection === 'asc'
            ? (aVal > bVal ? 1 : -1)
            : (aVal < bVal ? 1 : -1);
        });

        return result.slice(0, this.visibleCount);
      },

      toggleExpand(id) {
        const i = this.expanded.indexOf(id);
        if (i === -1) this.expanded.push(id);
        else this.expanded.splice(i, 1);
      },

      toggleSort(field) {
        if (this.sortField === field) {
          this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
          this.sortField = field;
          this.sortDirection = 'desc';
        }
      },

      createObserver(el) {
        const sentinel = el.querySelector('[x-ref="sentinel"]');
        if (!sentinel) {
          console.warn('Sentinel not found');
          return;
        }

        console.log('✅ Sentinel found:', sentinel);

        this.observer = new IntersectionObserver(
          entries => {
            entries.forEach(entry => {
              if (entry.isIntersecting && !this.loading) {
                console.log('⚡ Sentinel intersected – loading more...');
                this.loading = true;
                this.visibleCount += 100;
                this.loading = false;
              }
            });
          },
          {
            root: null, // 👈 Use null for the viewport
            rootMargin: '0px 0px 800px 0px',
            threshold: 0.01
          }
        );

        this.observer.observe(sentinel);
      },




      teamBg(teamAbbrev) {
        return teamGradients[teamAbbrev] || 'linear-gradient(to bottom, #e5e7eb, #9ca3af)';
      },
    });
  });
}
