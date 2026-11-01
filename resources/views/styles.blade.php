@once
    <style>
        .xi-card { border: 1px solid color-mix(in oklab, currentColor 14%, transparent); border-radius: .75rem; padding: .75rem 1rem; }
        .xi-source { display: flex; flex-direction: column; gap: .35rem; }
        .xi-header { font-weight: 600; font-size: .95rem; word-break: break-word; }
        .xi-samples { display: flex; flex-wrap: wrap; gap: .25rem; }
        .xi-chip { font-size: .75rem; padding: .05rem .5rem; border-radius: .4rem; background: color-mix(in oklab, currentColor 8%, transparent); max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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
        .xi-note { font-size: .85rem; opacity: .8; }
    </style>
@endonce
