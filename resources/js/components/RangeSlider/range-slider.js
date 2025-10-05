// range-slider.js
export class RangeSlider {
    /**
     * @param {HTMLElement} rootEl  Container to render into.
     * @param {{
     *   id?: string,
     *   key?: string,
     *   label: string,
     *   type?: 'single'|'dual',
     *   min: number,
     *   max: number,
     *   step?: number,
     *   decimals?: number,
     *   value?: number,        // for single
     *   minValue?: number,     // for dual
     *   maxValue?: number,     // for dual
     *   debounceMs?: number
     * }} cfg
     */
    constructor(rootEl, cfg) {
        if (!(rootEl instanceof HTMLElement))
            throw new Error("RangeSlider: rootEl must be an HTMLElement");
        this.root = rootEl;

        const integerRange =
            Number.isInteger(cfg.min) && Number.isInteger(cfg.max);
        const stepFromDecimals =
            typeof cfg.decimals === "number" && cfg.decimals >= 0
                ? Number((1 / Math.pow(10, cfg.decimals)).toFixed(cfg.decimals))
                : undefined;

        this.cfg = {
            id: cfg.id ?? cfg.key ?? "",
            key: cfg.key ?? cfg.id ?? "",
            label: cfg.label ?? "",
            type: cfg.type === "single" ? "single" : "dual",
            min: Number(cfg.min),
            max: Number(cfg.max),
            step: cfg.step ?? stepFromDecimals ?? (integerRange ? 1 : 0.1),
            value: cfg.value ?? cfg.min,
            minValue: cfg.minValue ?? cfg.min,
            maxValue: cfg.maxValue ?? cfg.max,
            debounceMs: cfg.debounceMs ?? 120,
        };

        // initial state for reset()
        this._initial = {
            value: this.cfg.value,
            minValue: this.cfg.minValue,
            maxValue: this.cfg.maxValue,
        };

        // build
        this._render();
        this._bind();
        this._updateUI();
    }

    // ---------- public API ----------
    reset() {
        if (this.cfg.type === "single") {
            this._setSingle(this._initial.value, false);
        } else {
            this._setDual(
                this._initial.minValue,
                this._initial.maxValue,
                false
            );
        }
        this._updateUI();
    }

    value() {
        return this.cfg.type === "single"
            ? { value: this._clamp(this.cfg.value) }
            : { minValue: this.cfg.minValue, maxValue: this.cfg.maxValue };
    }

    destroy() {
        this._unBind();
        this.root.innerHTML = "";
    }
    // --------------------------------

    _render() {
        this.root.classList.add("rs");

        // Head
        const row = document.createElement("div");
        row.className = "rs-row";
        const label = document.createElement("div");
        label.className = "rs-label";
        label.textContent = this.cfg.label;
        const rangeTxt = document.createElement("div");
        rangeTxt.className = "rs-range";
        rangeTxt.textContent = `${this.cfg.min} – ${this.cfg.max}`;
        row.append(label, rangeTxt);

        // Body
        const body = document.createElement("div");
        body.className = "rs-body";
        const rail = document.createElement("div");
        rail.className = "rs-rail";
        const fill = document.createElement("div");
        fill.className = "rs-fill";
        rail.appendChild(fill);

        const inputA = document.createElement("input");
        inputA.type = "range";
        inputA.className = "rs-input rs-input--a";
        inputA.min = String(this.cfg.min);
        inputA.max = String(this.cfg.max);
        inputA.step = String(this.cfg.step);
        inputA.setAttribute(
            "aria-label",
            `${this.cfg.label} ${
                this.cfg.type === "dual" ? "minimum" : "value"
            }`
        );

        const inputB = document.createElement("input");
        inputB.type = "range";
        inputB.className = "rs-input rs-input--b";
        inputB.min = String(this.cfg.min);
        inputB.max = String(this.cfg.max);
        inputB.step = String(this.cfg.step);
        inputB.setAttribute("aria-label", `${this.cfg.label} maximum`);

        body.append(rail, inputA);
        if (this.cfg.type === "dual") body.append(inputB);

        this.root.replaceChildren(row, body);

        // refs
        this._els = { row, label, rangeTxt, body, rail, fill, inputA, inputB };
    }

    _bind() {
        const onInputA = (e) => {
            const v = Number(e.target.value);
            if (this.cfg.type === "single") {
                this._setSingle(v, true);
            } else {
                const minAllowed = this.cfg.min;
                const maxAllowed = Math.max(
                    this.cfg.min,
                    this.cfg.maxValue - this.cfg.step
                );
                const clamped = Math.min(Math.max(v, minAllowed), maxAllowed);
                this._setDual(clamped, this.cfg.maxValue, true);
            }
        };
        const onInputB = (e) => {
            const v = Number(e.target.value);
            const maxAllowed = this.cfg.max;
            const minAllowed = Math.min(
                this.cfg.max,
                this.cfg.minValue + this.cfg.step
            );
            const clamped = Math.max(Math.min(v, maxAllowed), minAllowed);
            this._setDual(this.cfg.minValue, clamped, true);
        };

        this._handlers = {
            onInputA,
            onInputB,
            onResize: () => this._updateUI(),
        };

        this._els.inputA.addEventListener("input", onInputA);
        if (this.cfg.type === "dual")
            this._els.inputB.addEventListener("input", onInputB);
        window.addEventListener("resize", this._handlers.onResize, {
            passive: true,
        });

        // Debounced dispatcher
        this._debouncedEmit = this._debounce(
            () => this._emitChange(),
            this.cfg.debounceMs
        );
    }

    _unBind() {
        if (!this._handlers) return;
        this._els.inputA?.removeEventListener("input", this._handlers.onInputA);
        this._els.inputB?.removeEventListener("input", this._handlers.onInputB);
        window.removeEventListener("resize", this._handlers.onResize);
        this._handlers = null;
    }

    _setSingle(v, emit) {
        this.cfg.value = this._clamp(this._snap(v));
        this._els.inputA.value = String(this.cfg.value);
        this._updateUI();
        if (emit) this._debouncedEmit();
    }

    _setDual(minV, maxV, emit) {
        this.cfg.minValue = this._clamp(this._snap(minV));
        this.cfg.maxValue = this._clamp(this._snap(maxV));
        // enforce no crossing
        if (this.cfg.minValue > this.cfg.maxValue) {
            const mid = (this.cfg.minValue + this.cfg.maxValue) / 2;
            this.cfg.minValue = Math.min(
                mid,
                this.cfg.maxValue - this.cfg.step
            );
            this.cfg.maxValue = Math.max(
                mid,
                this.cfg.minValue + this.cfg.step
            );
        }
        this._els.inputA.value = String(this.cfg.minValue);
        if (this._els.inputB)
            this._els.inputB.value = String(this.cfg.maxValue);
        this._updateUI();
        if (emit) this._debouncedEmit();
    }

    _updateUI() {
        const { min, max } = this.cfg;
        const range = max - min || 1;

        if (this.cfg.type === "single") {
            const pct = ((this.cfg.value - min) / range) * 100;
            this._els.fill.style.left = "0%";
            this._els.fill.style.width = `${pct}%`;
        } else {
            const left = ((this.cfg.minValue - min) / range) * 100;
            const right = ((this.cfg.maxValue - min) / range) * 100;
            this._els.fill.style.left = `${left}%`;
            this._els.fill.style.width = `${Math.max(0, right - left)}%`;
        }
    }

    _emitChange() {
        const detail = {
            id: this.cfg.id,
            key: this.cfg.key,
            type: this.cfg.type,
            min: this.cfg.min,
            max: this.cfg.max,
            step: this.cfg.step,
            value: this.cfg.type === "single" ? this.cfg.value : undefined,
            minValue: this.cfg.type === "dual" ? this.cfg.minValue : undefined,
            maxValue: this.cfg.type === "dual" ? this.cfg.maxValue : undefined,
        };
        this.root.dispatchEvent(
            new CustomEvent("range-slider:change", {
                detail,
                bubbles: true,
                composed: true,
            })
        );
    }

    _clamp(v) {
        return Math.min(this.cfg.max, Math.max(this.cfg.min, v));
    }

    _snap(v) {
        const { step, min } = this.cfg;
        const snapped = Math.round((v - min) / step) * step + min;
        // fix floating errors
        const s = step.toString();
        const decimals = s.includes(".") ? s.length - s.indexOf(".") - 1 : 0;
        return Number(snapped.toFixed(decimals));
    }

    _debounce(fn, wait) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), wait);
        };
    }
}
