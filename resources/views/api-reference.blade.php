<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $specification['info']['title'] }} reference</title>
    <style>
        :root { color-scheme: light; font: 16px/1.55 system-ui, sans-serif; color: #172033; background: #f6f8fb; }
        body { margin: 0; }
        main { max-width: 58rem; margin: 2rem auto; padding: 0 1rem; }
        header { margin-bottom: 1.5rem; }
        a { color: #1748a1; }
        label { display: block; font-weight: 650; margin-top: 1rem; }
        input, select { font: inherit; padding: .45rem .6rem; border: 1px solid #9ca9be; border-radius: .4rem; background: white; }
        input { width: min(28rem, 90%); }
        details { border: 1px solid #ccd6e5; background: white; border-radius: .45rem; margin: .5rem 0; padding: .75rem; }
        summary { cursor: pointer; overflow-wrap: anywhere; }
        summary strong { display: inline-block; min-width: 4.2rem; color: #184381; }
        pre { overflow: auto; max-height: 32rem; padding: .75rem; background: #eef2f7; }
        code { font-family: ui-monospace, monospace; }
    </style>
    <script src="{{ asset('api-reference.js') }}" defer></script>
</head>
<body>
<main>
    <header>
        <h1>{{ $specification['info']['title'] }} reference</h1>
        <p>OpenAPI {{ $specification['openapi'] }} · <a href="{{ route('api-reference.specification', absolute: false) }}">Download OpenAPI YAML</a></p>
        <label for="search">Find an endpoint</label>
        <input type="search" id="search" autocomplete="off">
        <label for="method">HTTP method</label>
        <select id="method">
            <option value="">All methods</option>
            @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                <option>{{ $method }}</option>
            @endforeach
        </select>
        <p id="result-count" role="status"></p>
    </header>
    @foreach ($specification['paths'] as $path => $operations)
        @foreach ($operations as $method => $operation)
            @continue(! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true))
            <details data-method="{{ strtoupper($method) }}">
                <summary><strong>{{ strtoupper($method) }}</strong> <code>{{ $path }}</code> {{ $operation['summary'] ?? '' }}</summary>
                @if (isset($operation['description']))
                    <p>{{ $operation['description'] }}</p>
                @endif
                @if (isset($operation['parameters']) || isset($operation['requestBody']))
                    <h2>Request</h2>
                    <pre>{{ json_encode(array_intersect_key($operation, ['parameters' => true, 'requestBody' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                @endif
                <h2>Responses</h2>
                <pre>{{ json_encode($operation['responses'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endforeach
    @endforeach
</main>
</body>
</html>
