# Design rules

The interface decisions, in one place, so a new screen follows them instead of rediscovering them.
Adapted on 2026-11-19 from Reporion's `docs/design-rules.md` (the owner's decision): the rules that
fit a small monitoring app were kept, renamed to Tidewatch's own classes, and the rest are listed
under [Left out on purpose](#left-out-on-purpose). **Add a rule when the owner decides one** (date
it), and fix the rule, not just the screen, when the two disagree.

Sources: `public/assets/css/app.css` (all styles), `lib/view.php` (page shell, `flash()`, icons),
`public/assets/js/app.js` (confirm, busy).

## Principles

- **Tidewatch keeps its own look**: the tidal palette (sand and sea, deep night water), the system
  font stack, its inline SVG icons (`icon()` in `lib/view.php`) and unprefixed class names.
- **Server-rendered HTML; JavaScript only for charts, dashboards and live mode.** A form is a plain
  POST + redirect that works with JavaScript broken.
- **Do not invent colours.** Use the tokens on `:root` in `app.css`; a new shade is a new token,
  defined for light and dark. (The public hero's gradients are the one exception.)
- **One control, one look.** The same thing is drawn the same way everywhere, including in the
  markup the JavaScript builds (a live-mode or dashboard card has the same header as a server one).
- **No inline `style=`** except a value only the code knows (a dashboard panel's grid span).
  Everything else is a class in `app.css`.

## Page head

```html
<div class="page-head">
  <hgroup><h1>Devices</h1><p class="muted">3 configured</p></hgroup>
  <div class="actions"><a class="btn btn-sm" href="…">Add device</a></div>
</div>
```

- **One `<h1>` per page**, in an `<hgroup>` with its one-line lead (`<p class="muted">`), only when
  the page needs a lead. A device badge may come before the `<hgroup>`.
- **Actions on the page as a whole** go in `.actions` after the `<hgroup>`: right-aligned, wrapping
  to their own line when they no longer fit.

## Cards (panels)

A card is a raised surface (`--surface`, `--radius`, `--shadow`) that holds one topic: a chart, a
list, a form. Anatomy, top to bottom:

```html
<form class="card" method="post">
  <header class="card-head"><hgroup><h2>Title</h2><p class="muted">What this is for</p></hgroup>
    <div class="actions">…</div></header>
  … content …
  <footer><button class="btn">Save</button></footer>
</form>
```

- **Header**: `<header class="card-head">` with an `<hgroup>` holding an `<h2>` and an optional
  one-sentence `<p class="muted">`. The `<hgroup>` is there even for a title alone. A section's
  description goes here; a field's own help goes in the field's `<small>` (Forms).
- **Header actions** (a range picker, a filter, Clear) are a `.actions` sibling after the
  `<hgroup>`. They act on what the card *shows*; a form's own Save is in its footer, not here. A
  single link or count is bare (`<a class="small">`), not an action row.
- **Footer**: a card that ends with buttons ends with them in a `<footer>` inside the `<form>`
  they submit: a full-width strip with a divider above, buttons right-aligned. It holds actions
  only, never fields. A card with no action has no footer. A `.card.flush` (a table edge to edge)
  gets the same footer; its padding is set for it.
- **One footer per card.** A second action with fields of its own gets its own card; a destructive
  action that needs no input (Delete device) is an outline danger button in the same footer,
  submitting its own form through `form="…"`.
- A `<dialog>` has the same shape: its buttons in a `<footer>`.
- Cards in a grid or stack are `16px` apart; a card does not nest another card.

## Forms

- **Label**: a short name only. No `(optional)`, no `(e.g. …)`, no instructions in it. A unit that
  names the quantity may stay: `Poll interval (seconds)`.
- **Placeholder**: an example value (`192.168.1.1`, `Lab overview`), or a state (`unchanged`).
- **Help**: a `<small>` under the input saying what the field does or how to fill it; only when
  needed. `Optional` is help, not part of the label.
- **Input and `<small>` sit inside the `<label>`**: label text, input, small, in that order.
  ```html
  <div class="field" data-snmp><label>Host
    <input type="text" name="host" placeholder="192.168.1.1">
    <small>Name or address the poller can reach.</small></label></div>
  ```
  The `.field` wrapper keeps the show/hide hooks (`data-prom`, `data-snmp`, `data-v3`, `data-for`).
- **What stays outside a label**: checkbox rows (`label.check` already wraps its box).
- **A control with no wrapping label** (a table cell on the Metrics tab, the dialog's filter box)
  carries `aria-label`; the column header is its visible label.
- **Field error**: errors come back as a notice after the redirect (Notices); there is no per-field
  error yet.

## Notices

- A message to the user (saved, failed, a warning) is a **notice card**, `.notice` with a kind:
  `notice-info`, `notice-ok`, `notice-warn`, `notice-error`. `ok` and `info` have
  `role="status"`; `warn` and `error` have `role="alert"`.
- `flash($msg, $kind)` stores one, `flash()` prints it after the page head. `'err'` is accepted as
  an alias for `error`.
- **Not notices**: a pill in a table, the empty state of a list, a chart's "No data yet".
- A refused action says why in the user's terms (`A valid host/IP is required.`).

## Buttons

One `.btn` base plus a **role** and an optional **size**:

| Role | Class | Look | Use for |
|---|---|---|---|
| primary | `.btn` | accent fill | **the** action of a form, bar or card: Save, Add device, Create, Sign in. One per form |
| secondary | `.btn-ghost` | transparent, outlined | beside the primary, or several equal actions: Cancel, Edit, Back, Pause |
| quiet | `.btn-quiet` | no fill, no outline, muted | a row's icon action (✕ delete a metric) |
| danger, beside others | `.btn-ghost.btn-danger` | red outline and text, filled on hover | Delete device beside Save, Clear the event log, Delete a dashboard |
| danger, confirm page | `.btn-danger` | solid red | the submit of a page that exists to confirm a destructive act (none yet) |

- A form has at most one primary; a destructive act is never the primary of a form that also saves.
- **Destructive acts are confirmed** with `data-confirm="message"` on their form (`app.js`).
- **Icon-only** buttons carry their label as `title` and `aria-label`.
- **Order**: Cancel or the secondary first, the primary last (right).
- **Sizes**: `.btn` for a form's own actions; `.btn-sm` for card headers, page heads, table rows and
  toolbars. **Pair by height**: an input next to a small button is `.input-sm`.
- **A finger needs more**: on `pointer: coarse` small buttons, `.seg` options and the dashboard
  panel tools grow to at least 36 px.

## Action rows (`.actions`)

`.actions` is a row of buttons that act together: flex, 8 px apart, right-aligned. Where it sits
decides its size: in a page head or card header the buttons are `.btn-sm`.

## Badges and status

- **State is a pill** (`.pill-up|down|ok|warn|crit|accent`) tinted from the state tokens
  (`--ok`, `--warn`, `--crit` and their `-soft` shades); a plain `.pill` is a kind, not a state.
- **A device is drawn by `device_badge()` only**: its icon tile with the status dot.

## Breakpoints

Pico's scale, as in Reporion. A media query cannot read a custom property, so the numbers are
written out; **use only these**:

| Name | Screen | Write it as |
|---|---|---|
| sm | ≥ 576 px | `min-width: 576px` / `max-width: 575.98px` |
| md | ≥ 768 px | `min-width: 768px` / `max-width: 767.98px` |
| lg | ≥ 1024 px | `min-width: 1024px` / `max-width: 1023.98px` |
| xl | ≥ 1280 px | `min-width: 1280px` / `max-width: 1279.98px` |

- **Max queries end in `.98`**, so a min and a max query of the same step never both match.
- **What changes**: below `lg` the side nav becomes a drawer and dashboards go to two columns;
  below `xl` three-column grids become two; below `md` two-column grids, the stat row and the
  form grid become one or two columns.
- **Also respected**: `prefers-reduced-motion: reduce` (no pulse, no drawer slide, no gauge
  sweep) and `pointer: coarse` (Buttons).

## Tables and small screens

- **Test every page at 390 px and 768 px**, signed in and anonymous, and at 1400 px.
- **Must hold**: no horizontal page scroll; every action reachable.
- **Tables scroll inside their card** (`.table-wrap`); an ellipsis is allowed on names and
  descriptions.

## Typography and utilities

- **`.muted`** is secondary text (a description, a timestamp); never what the user must read to act.
- **`.mono`** is for what the user may copy or compare character by character: a host, an OID, a
  URL, a command.
- **`.small`** is a one-off size nudge. If the same nudge appears three times it becomes a class.

## States

- **Empty**: a single `<p class="empty">` in the place the list would be, saying what is missing
  and, if useful, how to add one. No icon, no card of its own. (A chart's overlay is
  `.chart .empty`, the same words in the chart's box.)
- **Busy**: a form whose submit takes time (saving a device polls it; discovery walks it; a custom
  OID is read once) carries `data-busy`: on submit its buttons are disabled and the submit shows
  `aria-busy` with a spinner (`app.js`).
- **Disabled**: 45% opacity, `cursor: default`. A disabled device's row is `.is-disabled`.

## Ordering and dashboards (2026-11-19)

- **Card order is dragged, not typed**: the overview's device cards and the Dashboards list are
  `data-sortable="<api action>"` grids (`sort.js`); the order is saved on drop in a `sort` column. A
  card that holds inputs is dragged by its `.grip` handle (`data-handle`).
- **A dashboard saves every change at once** (add, edit, move, resize, remove), with Undo in the page
  head and the save state in its lead. There is no edit mode; the panel tools show on hover (always on
  a touch screen).
- **Adding a panel starts from the metric**: a device, then a checklist with search; the type is
  guessed (one percentage → gauge, page counter → stat, otherwise graph) and sizes are presets
  (Small, Half, Wide, Full × Short, Medium, Tall). *Add from device* appends a device's starter panels.

## Setup wizard (2026-11-19)

- `public/setup.php` runs while no admin password exists, and resumes (signed in) until its last step
  is done; `app.php` and `login.php` send there. Steps: system check, admin password, basics, first
  device, cron. One card per step, a numbered `ol.steps` above it, plain POST + redirect.
- It is unguarded by the owner's choice; the password step says so in a `warn` notice.
- The first-device step reuses `device_form()` and `device_save()` (`lib/devices.php`), so it is the
  same form as Devices → Add device.

## Text and language

- English, sentence case, no trailing full stop on labels and buttons.
- Button words are verbs: Save, Add device, Delete, Run discovery.

## Left out on purpose

From Reporion's rules, not taken here (2026-11-19, owner's choice):

- **Language files** (`lang/en.php`, `*_hint` / `*_help` keys): strings stay in the templates; the
  label / placeholder / help split is kept all the same.
- **Look**: DM Sans and Manrope, Phosphor icons, Reporion's palettes and theme picker. Tidewatch
  keeps its own font stack, SVG icons and tidal palette.
- **The `wk-` prefix and Reporion's class names** (`wk-panel`, `wk-dim`, `btn-secondary`): the
  mapping is in Buttons above.
- **Icon-only buttons on a phone**: Tidewatch's buttons have no icons, so their words stay.
- **Tables becoming cards** (`table-cards`): tables scroll inside their card instead.
- **The mockup as visual contract, breadcrumbs, tabs-as-menu, switches, `.group`, print CSS, the
  editor and Notes rules**: Tidewatch has none of these.
