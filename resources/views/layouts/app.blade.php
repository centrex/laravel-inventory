<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Inventory' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f4f1ea;
            --panel: #fffdf8;
            --ink: #1f2937;
            --muted: #6b7280;
            --accent: #0f766e;
            --accent-strong: #115e59;
            --line: #d6d3d1;
            --danger: #b91c1c;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; color: var(--ink); background: linear-gradient(180deg, #f1efe7 0%, #faf8f2 100%); }
        a { color: inherit; text-decoration: none; }
        .shell { max-width: 1280px; margin: 0 auto; padding: 24px; }
        .nav { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .brand { font-size: 24px; font-weight: 700; letter-spacing: -.03em; }
        .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-links a, .button { border: 1px solid var(--line); background: var(--panel); padding: 10px 14px; border-radius: 999px; font-size: 14px; cursor: pointer; }
        .button-primary { background: var(--accent); color: white; border-color: var(--accent); }
        .grid { display: grid; gap: 16px; }
        .card { background: var(--panel); border: 1px solid rgba(15, 23, 42, .08); border-radius: 18px; padding: 20px; box-shadow: 0 12px 30px rgba(15, 23, 42, .06); }
        .page-head { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 18px; }
        .title { margin: 0; font-size: 28px; letter-spacing: -.03em; }
        .muted { color: var(--muted); }
        .toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        input, select, textarea { width: 100%; border: 1px solid var(--line); background: white; border-radius: 12px; padding: 10px 12px; font-size: 14px; }
        textarea { min-height: 112px; resize: vertical; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid #ece7df; vertical-align: top; font-size: 14px; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .span-2 { grid-column: span 2; }
        .flash { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 14px; border-radius: 14px; margin-bottom: 16px; }
        .danger { color: var(--danger); }
        .section-title { margin: 24px 0 12px; font-size: 18px; }
        .stack { display: grid; gap: 12px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .pill { padding: 5px 10px; border-radius: 999px; background: #e7fffb; color: #115e59; display: inline-block; font-size: 12px; font-weight: 700; }
        .table-shell { overflow:auto; border: 1px solid #ece7df; border-radius: 16px; }
        .status-nav { display:flex; gap:10px; flex-wrap:wrap; }
        @media (max-width: 768px) {
            .shell { padding: 16px; }
            .page-head { flex-direction: column; align-items: flex-start; }
            .span-2 { grid-column: auto; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="nav">
            <a class="brand" href="{{ route('inventory.dashboard') }}">Inventory Control</a>
            <div class="status-nav">
                <x-tallui-button label="Dashboard" :link="route('inventory.dashboard')" class="btn-ghost btn-sm" />
                <x-tallui-button label="Purchase" :link="route('inventory.purchase-orders.create')" class="btn-ghost btn-sm" />
                <x-tallui-button label="Sale" :link="route('inventory.sale-orders.create')" class="btn-ghost btn-sm" />
                <x-tallui-button label="POS" :link="route('inventory.pos.index')" class="btn-ghost btn-sm" />
                <x-tallui-button label="Transfer" :link="route('inventory.transfers.create')" class="btn-ghost btn-sm" />
                <x-tallui-button label="Adjustment" :link="route('inventory.adjustments.create')" class="btn-ghost btn-sm" />
            </div>
        </div>

        @if (session('inventory.status'))
            <x-tallui-alert type="success" title="Saved" :dismissible="true">{{ session('inventory.status') }}</x-tallui-alert>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </div>

    @livewireScripts
</body>
</html>
