<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} API Status</title>
    <style>
        :root { color-scheme: dark; font: 16px/1.6 system-ui, sans-serif; background: #0e1520; color: #f3f6fc; }
        body { margin: 0; }
        main { max-width: 52rem; margin: 5rem auto; padding: 0 1.25rem; }
        h1 { font-size: clamp(2rem, 6vw, 3.5rem); line-height: 1.1; margin: .25rem 0 1.75rem; letter-spacing: -.045em; }
        h2 { margin: 0 0 .75rem; font-size: 1.125rem; }
        section { border: 1px solid #324259; border-radius: .85rem; background: #151f2e; padding: 1.25rem; margin: 1rem 0; }
        dl { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .6rem 1rem; margin: 0; }
        dd { margin: 0; overflow-wrap: anywhere; }
        p { margin: .5rem 0; color: #b9c7db; }
        ul { margin: 0; padding-left: 1.25rem; }
        a { color: #92ccff; }
        code { font-size: .88em; overflow-wrap: anywhere; }
        [data-state="operational"] { color: #79e1ab; }
        [data-state="degraded"], [data-state="unavailable"] { color: #ffb995; }
    </style>
</head>
<body>
    <main>
        <header>
            <p>{{ config('app.name') }} · API v1</p>
            <h1>API status</h1>
        </header>

        <section aria-labelledby="status-heading">
            <h2 id="status-heading">Service health</h2>
            <div aria-live="polite">
                <dl>
                    <dt>Overall</dt><dd id="overall">Checking…</dd>
                    <dt>API</dt><dd id="api">Checking…</dd>
                    <dt>Database connection</dt><dd id="database">Checking…</dd>
                </dl>
                <p id="checked-at"></p>
            </div>
            <p><a href="{{ route('api.v1.status', absolute: false) }}">JSON status</a> · <a href="{{ url('/up') }}">Liveness probe</a></p>
        </section>

        <section aria-labelledby="auth-heading">
            <h2 id="auth-heading">Authentication</h2>
            <ul>
                <li><code>POST {{ route('api.v1.auth.login', absolute: false) }}</code> issues a bearer token. Send <code>email</code>, <code>password</code> and optional <code>device_name</code> as JSON.</li>
                <li><code>GET {{ route('api.v1.auth.me', absolute: false) }}</code> returns your profile and roles.</li>
                <li><code>POST {{ route('api.v1.auth.logout', absolute: false) }}</code> revokes your current token.</li>
            </ul>
            <p>For authenticated requests, send <code>Authorization: Bearer &lt;access_token&gt;</code>.</p>
        </section>
    </main>
    <script>
        function setState(id, state, label) {
            const element = document.getElementById(id);
            element.dataset.state = state;
            element.textContent = label;
        }

        async function updateStatus() {
            const checkedAt = document.getElementById('checked-at');

            try {
                const response = await fetch(@json(route('api.v1.status', absolute: false)), {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: AbortSignal.timeout(10000),
                });
                const result = await response.json();

                if (result.errors?.code === 'DATABASE_UNAVAILABLE') {
                    setState('overall', 'degraded', 'Degraded');
                    setState('api', 'operational', 'Operational');
                    setState('database', 'unavailable', 'Unavailable');
                    checkedAt.textContent = 'Last checked ' + new Date().toLocaleTimeString();
                    return;
                }

                const status = result.data;

                setState('overall', status.status, 'Operational');
                setState('api', status.checks.api, 'Operational');
                setState('database', status.checks.database, status.checks.database === 'operational' ? 'Operational' : 'Unavailable');
                checkedAt.textContent = 'Last checked ' + new Date(status.checked_at).toLocaleTimeString();
            } catch {
                setState('overall', 'unavailable', 'Status unavailable');
                setState('api', 'unavailable', 'Unreachable');
                setState('database', '', 'Unknown');
                checkedAt.textContent = 'Last attempted ' + new Date().toLocaleTimeString();
            }
        }

        updateStatus();
        setInterval(updateStatus, 30000);
    </script>
</body>
</html>
