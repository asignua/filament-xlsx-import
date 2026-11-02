@once
    <style>
        .xi-card { border: 1px solid color-mix(in oklab, currentColor 14%, transparent); border-radius: .75rem; padding: .75rem 1rem; }
        .xi-source { display: flex; flex-direction: column; gap: .35rem; }
        .xi-header { font-weight: 600; font-size: .95rem; word-break: break-word; }
        .xi-samples { display: flex; flex-wrap: wrap; gap: .25rem; }
        .xi-chip { font-size: .75rem; padding: .05rem .5rem; border-radius: .4rem; background: color-mix(in oklab, currentColor 8%, transparent); max-width: 100%; overflow-wrap: anywhere; white-space: normal; }
        .xi-badge { display: inline-flex; align-items: center; gap: .25rem; font-size: .7rem; font-weight: 600; padding: .1rem .55rem; border-radius: 999px; width: fit-content; }
        .xi-badge-rule { background: color-mix(in oklab, var(--primary-500, #f59e0b) 18%, transparent); color: var(--primary-700, #b45309); }
        .xi-badge-changed { background: color-mix(in oklab, #f59e0b 20%, transparent); color: #b45309; }
        .xi-badge-preset { background: color-mix(in oklab, #6366f1 16%, transparent); color: #4338ca; }
        .xi-badge-auto { background: color-mix(in oklab, #10b981 16%, transparent); color: #047857; }
        .dark .xi-badge-rule { color: var(--primary-300, #fcd34d); }
        .dark .xi-badge-changed { color: #fcd34d; }
        .dark .xi-badge-preset { color: #a5b4fc; }
        .dark .xi-badge-auto { color: #6ee7b7; }
        .xi-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
        @media (max-width: 640px) { .xi-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .xi-stat { border: 1px solid color-mix(in oklab, currentColor 14%, transparent); border-radius: .75rem; padding: .75rem 1rem; }
        .xi-stat-n { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .xi-stat-l { font-size: .75rem; opacity: .7; text-transform: uppercase; letter-spacing: .04em; }
        .xi-stat-created .xi-stat-n { color: #059669; } .xi-stat-updated .xi-stat-n { color: #2563eb; } .xi-stat-failed .xi-stat-n { color: #dc2626; }
        .dark .xi-stat-created .xi-stat-n { color: #34d399; } .dark .xi-stat-updated .xi-stat-n { color: #60a5fa; } .dark .xi-stat-failed .xi-stat-n { color: #f87171; }
        .xi-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .xi-table th, .xi-table td { text-align: left; padding: .3rem .5rem; border-bottom: 1px solid color-mix(in oklab, currentColor 10%, transparent); vertical-align: top; }
        .xi-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
        .xi-old { text-decoration: line-through; opacity: .55; }
        .xi-h { font-weight: 600; margin: 1rem 0 .4rem; }
        .xi-casts .fi-fo-repeater-item { position: relative; }
        .xi-casts .fi-fo-repeater-item-header { position: absolute; top: .4rem; right: .4rem; padding: 0; margin: 0; border: 0; background: none; z-index: 2; min-height: 0; }
        .xi-casts .fi-fo-repeater-item-content { padding: .6rem 2.4rem .6rem .75rem; }
        .xi-casts .fi-fo-repeater-items { gap: .5rem; }
        /* The sticky element is the grid cell that wraps the text (a child of the tall step container), not the text inside its own short wrapper. */
        .fi-grid-col:has(> .fi-sc-component > .xi-pending), .fi-grid-col:has(> .xi-pending) { position: sticky; top: 0; z-index: 5; }
        /* Opaque, so the cards scrolling under it never show through (light and dark). */
        .xi-sticky { padding: .4rem .75rem; border-radius: .5rem; font-weight: 600; background: #fff; box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .08); }
        .xi-pending { box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .08), 0 6px 10px -6px rgba(0, 0, 0, .25); }
        .dark .xi-sticky { background: #18181b; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .1); }
        .dark .xi-pending { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .1), 0 6px 10px -6px rgba(0, 0, 0, .6); }
        .xi-hidden { display: none !important; }
        .xi-wrap { overflow-wrap: anywhere; word-break: break-word; }
        .xi-sample { font-family: ui-monospace, monospace; font-size: .8rem; overflow-wrap: anywhere; }
        .xi-chip { overflow-wrap: anywhere; }
        .xi-note { font-size: .85rem; opacity: .8; }
    </style>
@endonce
