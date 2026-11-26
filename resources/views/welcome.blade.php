<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SlotLock — reservas con concurrencia real</title>
    <style>
        :root {
            --bg: #0b1120;
            --panel: #111a2e;
            --line: #22304d;
            --text: #e6edf7;
            --muted: #94a6c4;
            --accent: #5eead4;
            --accent-2: #a3e635;
            --code: #07101f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(1100px 600px at 15% -10%, #16304a 0%, var(--bg) 55%);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
        }
        .wrap { max-width: 980px; margin: 0 auto; padding: 32px 22px 64px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .brand { font-weight: 700; letter-spacing: -0.02em; font-size: 1.15rem; }
        .brand span { color: var(--accent); }
        nav { display: flex; gap: 18px; font-size: .9rem; }
        a { color: var(--muted); text-decoration: none; }
        a:hover { color: var(--accent); }
        .hero { margin-top: 64px; }
        .pill {
            display: inline-block; font-size: .75rem; letter-spacing: .08em; text-transform: uppercase;
            color: var(--accent); border: 1px solid var(--line); border-radius: 999px; padding: 5px 12px;
            background: rgba(94, 234, 212, .06);
        }
        h1 { font-size: clamp(2rem, 5vw, 3.2rem); line-height: 1.1; letter-spacing: -0.03em; margin: 18px 0 14px; }
        h1 em { font-style: normal; color: var(--accent); }
        .lead { color: var(--muted); font-size: 1.08rem; max-width: 62ch; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 40px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .card h2 { font-size: .95rem; margin: 0 0 8px; color: var(--accent-2); }
        .card p { margin: 0; color: var(--muted); font-size: .92rem; }
        pre {
            background: var(--code); border: 1px solid var(--line); border-radius: 14px;
            padding: 18px; overflow-x: auto; font-size: .84rem; line-height: 1.6;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .sql-k { color: #c084fc; } .sql-c { color: var(--accent); } .sql-s { color: #fbbf24; } .sql-n { color: #93c5fd; }
        .section { margin-top: 56px; }
        .section h3 { font-size: 1.25rem; letter-spacing: -0.01em; margin: 0 0 14px; }
        ul.checks { list-style: none; padding: 0; margin: 0; color: var(--muted); }
        ul.checks li { padding-left: 26px; position: relative; margin-bottom: 8px; }
        ul.checks li::before { content: "✓"; position: absolute; left: 0; color: var(--accent); font-weight: 700; }
        footer { margin-top: 64px; padding-top: 20px; border-top: 1px solid var(--line); color: var(--muted); font-size: .82rem; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        code { background: rgba(148, 166, 196, .12); padding: 2px 6px; border-radius: 5px; font-family: ui-monospace, Menlo, monospace; font-size: .85em; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <div class="brand">Slot<span>Lock</span></div>
            <nav>
                <a href="/api/resources">API</a>
                <a href="/health">Health</a>
                <a href="https://github.com/jhonemmir/SlotLock" rel="noreferrer">GitHub</a>
            </nav>
        </header>

        <section class="hero">
            <span class="pill">PostgreSQL · Laravel · EXCLUDE USING gist</span>
            <h1>Reservas concurrentes sin <em>dobles asignaciones</em>.</h1>
            <p class="lead">
                Dos usuarios, el mismo recurso, el mismo horario, el mismo milisegundo.
                Uno gana; el otro recibe un 409 determinista. La garantía no está en PHP:
                está en una restricción de exclusión de PostgreSQL.
            </p>
        </section>

        <div class="grid">
            <div class="card">
                <h2>La invariante vive en la DB</h2>
                <p>Sin <code>SELECT</code> previos ni locks de aplicación. El motor evalúa el solapamiento de forma atómica.</p>
            </div>
            <div class="card">
                <h2>Holds con TTL</h2>
                <p>Reservas temporales, idempotencia en reintentos y expiración que no deja slots muertos.</p>
            </div>
            <div class="card">
                <h2>Evidencia reproducible</h2>
                <p>Migraciones versionadas, tests contra PostgreSQL real y planes <code>EXPLAIN</code> de las consultas críticas.</p>
            </div>
        </div>

        <section class="section">
            <h3>La pieza central</h3>
<pre><code><span class="sql-k">ALTER TABLE</span> reservations
  <span class="sql-k">ADD CONSTRAINT</span> reservations_no_overlap
  <span class="sql-k">EXCLUDE USING</span> gist (
    resource_id <span class="sql-k">WITH</span> =,
    time_range  <span class="sql-k">WITH</span> &&
  ) <span class="sql-k">WHERE</span> (status <span class="sql-k">IN</span> (<span class="sql-s">'held'</span>, <span class="sql-s">'confirmed'</span>));</code></pre>
            <p class="lead" style="margin-top:14px">
                Dos transacciones que insertan el mismo rango para el mismo recurso se serializan
                por el índice GiST: la segunda recibe <code>23P01</code> y la API responde
                <code>409 slot_already_taken</code>.
            </p>
        </section>

        <section class="section">
            <h3>Qué podés verificar hoy</h3>
            <ul class="checks">
                <li>La restricción existe en la base: <code>\d reservations</code>.</li>
                <li>El test de integración inserta dos reservas solapadas y comprueba el SQLSTATE <code>23P01</code>.</li>
                <li>Un hold vencido se expira en la misma transacción del nuevo insert (sin slots muertos).</li>
                <li>Un reintento con la misma <code>Idempotency-Key</code> devuelve la misma reserva.</li>
            </ul>
        </section>

        <section class="section">
            <h3>Probar la API</h3>
<pre><code><span class="sql-c"># recursos (seed)</span>
curl -s localhost:8000/api/resources | jq

<span class="sql-c"># disponibilidad de un día</span>
curl -s "localhost:8000/api/resources/00000000-0000-4000-8000-000000000001/availability?date=2027-03-01" | jq

<span class="sql-c"># crear un hold (Idempotency-Key obligatoria)</span>
curl -s -X POST localhost:8000/api/reservations \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"resource_id":"00000000-0000-4000-8000-000000000001","start_at":"2027-03-01T10:00:00-03:00","end_at":"2027-03-01T11:00:00-03:00"}' | jq</code></pre>
        </section>

        <footer>
            <span>SlotLock · proyecto de portafolio</span>
            <span>PHP 8.3 · Laravel · PostgreSQL 16</span>
        </footer>
    </div>
</body>
</html>
