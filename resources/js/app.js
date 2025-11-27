import "./bootstrap";
import "./echo";
import Alpine from "alpinejs";

// import { PlayerStatsPage } from './components/PlayerStatsPage/player-stats-page.js';
import { StatsPage } from "./components/StatsPage/stats-page.js";
import "./leagues-hub.js";
import "./community-hub.js";
import "./components/community-members-store";

// import "./components/RangeSlider/range-slider.css";
// import { RangeSlider } from "./components/RangeSlider/range-slider.js";
// window.RangeSlider = RangeSlider;

window.Alpine = Alpine;
Alpine.start();
