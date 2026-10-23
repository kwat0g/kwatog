# Capture runs — which numbers to trust

Every directory is `<label>/<role>/{*.png, report.json}`, produced by
[`spa/scripts/ui-ux-baseline-capture.mjs`](../../../spa/scripts/ui-ux-baseline-capture.mjs)
(`cd spa && npm run ui:capture -- --role admin --label baseline`).

| Label | Code state | Probe | Use for |
|---|---|---|---|
| `baseline/` | **unfixed** (first Phase 0 run) | first version | The historical Phase 0 baseline only. **Its `a11y.unnamedInteractive` fields are wrong** — see below. |
| `before2/` | unfixed | corrected | The "before" side of every Wave 1 fix. Compare against `after/`. |
| `after/` | fixed | corrected | The "after" side of every Wave 1 fix. |

## Do not read a11y numbers out of `baseline/`

The first probe computed accessible names by hand
(`aria-label ?? title ?? innerText ?? alt ?? placeholder`). That is not how a browser computes
a name — `innerText` excludes both an `<img>` alt and `display:none` text — so it reported the
topbar brand link as unnamed on every mobile capture. The 20 "unnamed interactive elements"
in the first draft of `00-discovery.md` were an artifact of that helper and have been withdrawn.

Chromium's own accessibility tree (`Accessibility.getFullAXTree` over CDP) reports **0** on the
same pages at the same viewports. `before2/` and `after/` use that.

Overflow numbers in `baseline/` are also inflated: the first probe counted `scrollWidth >
clientWidth`, which is also true of deliberate overlays (EmptyState's `DatumMark` is positioned
absolutely at 52–72px inside a 40px slot). `before2/` and `after/` measure **in-flow** overflow
via bounding rects, plus a leaf-text rule so a fixed-width label overflowing its own text is
still caught.

Full write-up: [`../FINDINGS.md`](../FINDINGS.md) → *Measurement corrections*.
