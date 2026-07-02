# UI Motion

This document defines the standard motion language for interactive UI state changes.

It is part of the UI contract for new and materially touched interfaces. Use it with
`docs/CONVENTIONS.md` and `docs/UI_DESIGN.md`.

---

## Principles

- Motion should clarify state changes, not decorate them.
- Use Tailwind utility classes only.
- Prefer explicit transition properties over broad `transition-all`.
- Keep motion calm and operational.
- Pair related motion together, such as a caret rotation and its disclosure panel.
- Respect reduced-motion users with `motion-reduce:transition-none` when movement is meaningful.

---

## Timing

Use these defaults unless the surrounding component already has a documented pattern.

- Small control feedback: `duration-150` or `duration-200`.
- Caret rotation: `transition-transform duration-300 ease-out`.
- Accordions and inline disclosures: `duration-300 ease-out`.
- Dropdown menus: `duration-200 ease-out` enter and `duration-75` to `duration-150 ease-in` leave.
- Slide-over panels: slower enter and shorter leave, such as `duration-500 ease-out` enter and `duration-300 ease-in` leave.
- Progress indicators: `duration-300 ease-out`.

Avoid durations above 500ms for app UI unless explicitly approved.

---

## Preferred Properties

Use the narrowest Tailwind transition that matches the state change.

- Color state: `transition-colors`.
- Opacity state: `transition-opacity`.
- Position or icon rotation: `transition-transform`.
- Multiple known properties: `transition-[grid-template-rows,opacity]` or `transition-[max-height,opacity]`.

Avoid `transition-all` for new work because it can animate unrelated layout and create unstable interactions.

---

## Accordions And Disclosures

Accordions, inline drawers, and expandable rows should use an explicit caret icon as
the toggle affordance.

Required behavior:

- Use a caret icon button with `aria-expanded` and `aria-controls`.
- Rotate the caret when open, usually with `rotate-180`.
- Animate the caret with `transition-transform duration-300 ease-out`.
- Animate the disclosed region with height-like motion plus opacity.
- Keep the element renderable while the close animation runs, then apply `hidden` after the transition.
- Do not instantly toggle `hidden` when the user should see a collapse animation.
- Use `motion-reduce:transition-none` on the moving pieces.

Preferred Tailwind pattern for content reveal:

```html
<div class="grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none">
    <div class="min-h-0 overflow-hidden">
        <!-- content -->
    </div>
</div>
```

Open state:

```html
<div class="grid grid-rows-[1fr] opacity-100 transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none">
    <div class="min-h-0 overflow-hidden">
        <!-- content -->
    </div>
</div>
```

---

## Slide-Overs

Right-side slide-overs should use `x-ui.slide-over`.

The panel should move with `transition-transform`; the overlay should fade independently.
Do not combine overlay and panel movement into one transition.

---

## Loading And Async State

- Loading placeholders may fade in, but should not block the full page unless the whole page is unavailable.
- Asynchronous disclosure content should open first with a lightweight loading row, then replace the content in place.
- Error states should appear inline inside the same animated region when possible.
