<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>WORM Storage · MinIO</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f4f1ea;
                --ink: #1b1b18;
                --muted: #5c5a52;
                --card: #fffdf8;
                --line: #d9d4c8;
                --accent: #b45309;
                --ok: #166534;
                --ok-bg: #dcfce7;
                --err: #991b1b;
                --err-bg: #fee2e2;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: var(--bg);
                color: var(--ink);
                line-height: 1.5;
            }
            main {
                max-width: 880px;
                margin: 0 auto;
                padding: 2.5rem 1.25rem 4rem;
            }
            h1 { font-size: 1.75rem; margin: 0 0 .35rem; }
            h2 { font-size: 1.1rem; margin: 0 0 .75rem; }
            p.lead { color: var(--muted); margin: 0 0 1.5rem; }
            .meta, .card {
                background: var(--card);
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 1rem 1.15rem;
            }
            .meta { display: grid; gap: .35rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1rem; }
            .meta span { display: block; color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
            .meta strong { font-weight: 600; }
            .status { padding: .85rem 1rem; border-radius: 10px; margin: 1rem 0; }
            .status.ok { background: var(--ok-bg); color: var(--ok); }
            .status.err { background: var(--err-bg); color: var(--err); }
            label { display: block; font-weight: 600; margin: .85rem 0 .35rem; }
            input[type="file"] {
                width: 100%;
                border: 1px dashed var(--line);
                border-radius: 8px;
                padding: .9rem .8rem;
                font: inherit;
                background: #fff;
            }
            .hint { color: var(--muted); font-size: .875rem; margin: .4rem 0 0; }
            .actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
            button, .button {
                border: 0;
                border-radius: 8px;
                padding: .65rem 1rem;
                font: inherit;
                font-weight: 600;
                cursor: pointer;
                background: var(--ink);
                color: #fff;
                text-decoration: none;
                display: inline-block;
            }
            .button.secondary, button.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
            button.danger { background: #991b1b; }
            .offline { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: .85rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
            a { color: var(--accent); }
            code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9em; }
            table { width: 100%; border-collapse: collapse; }
            th, td { text-align: left; padding: .65rem 0; border-bottom: 1px solid var(--line); vertical-align: middle; }
            th { color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
            td.actions-cell { white-space: nowrap; }
            td.actions-cell form { display: inline; }
            .empty { color: var(--muted); margin: 0; }
            .stack { display: grid; gap: 1rem; }
        </style>
    </head>
    <body>
        <main>
            <h1>Write Once, Read Many</h1>
            <p class="lead">
                Upload a file into MinIO with Object Lock. The same filename cannot be uploaded again, and deletes are
                blocked while the retention window is active.
            </p>

            @unless ($reachable)
                <div class="offline">
                    MinIO is not reachable at <code>{{ $endpoint }}</code>.
                    Start it with <code>docker compose up -d</code>, then open the
                    <a href="http://127.0.0.1:9001">console</a> (minioadmin / minioadmin).
                </div>
            @endunless

            <div class="meta">
                <div><span>S3 API</span><strong>{{ $endpoint }}</strong></div>
                <div><span>Bucket</span><strong>{{ $bucket }}</strong></div>
                <div><span>Lock</span><strong>{{ $lockMode }} · {{ $retentionDays }} day</strong></div>
                <div><span>Console</span><strong><a href="http://127.0.0.1:9001">127.0.0.1:9001</a></strong></div>
            </div>

            @if (session('status'))
                <div class="status ok">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="status err">{{ $errors->first() }}</div>
            @endif

            <div class="stack">
                <div class="card">
                    <h2>Upload a file</h2>
                    <form method="post" action="{{ route('demo.write') }}" enctype="multipart/form-data">
                        @csrf
                        <label for="file">File</label>
                        <input id="file" name="file" type="file" required accept=".{{ implode(',.', $allowedMimes) }}">
                        <p class="hint">
                            Max {{ Number::fileSize($maxUploadKilobytes * 1024) }}.
                            Allowed: {{ implode(', ', $allowedMimes) }}.
                            Uploading the same name twice is rejected.
                        </p>
                        <div class="actions">
                            <button type="submit">Upload once</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <h2>Stored files</h2>
                    @if (count($files) === 0)
                        <p class="empty">No files yet. Upload one to lock it in the archive.</p>
                    @else
                        <table>
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Size</th>
                                    <th>Stored</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($files as $file)
                                    <tr>
                                        <td><code>{{ $file['key'] }}</code></td>
                                        <td>{{ Number::fileSize($file['size']) }}</td>
                                        <td>{{ $file['lastModified'] }}</td>
                                        <td class="actions-cell">
                                            <a class="button secondary" href="{{ route('demo.download', ['key' => $file['key']]) }}">Download</a>
                                            <form method="post" action="{{ route('demo.delete') }}">
                                                @csrf
                                                <input type="hidden" name="key" value="{{ $file['key'] }}">
                                                <button class="danger" type="submit">Try delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </main>
    </body>
</html>
