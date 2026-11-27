<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SlotLock — reservas con concurrencia real</title>
    <meta name="theme-color" content="#0f172a">
    <meta name="color-scheme" content="dark">
    <link rel="stylesheet" href="/css/tokens.css?v=2">
    <link rel="stylesheet" href="/css/base.css?v=2">
    <style>
        body {
            background: radial-gradient(var(--bg-glow-size) at var(--bg-glow-position-landing), var(--bg-glow) var(--bg-glow-start), var(--bg) var(--bg-glow-stop));
        }

        .wrap {
            max-width: var(--container-narrow);
            margin: 0 auto;
            padding: var(--space-xl) var(--gutter) var(--space-3xl);
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            flex-wrap: wrap;
        }

        .brand {
            font-family: var(--font-heading);
            font-weight: var(--weight-bold);
            letter-spacing: var(--tracking-tight);
            font-size: var(--text-lg);
            color: var(--text);
        }

        .brand span {
            color: var(--brand);
        }

        nav {
            display: flex;
            gap: var(--space-xs);
            font-size: var(--text-sm);
            flex-wrap: wrap;
        }

        nav a {
            display: inline-flex;
            align-items: center;
            min-height: var(--control-height-sm);
            padding: var(--space-3xs) var(--space-sm);
            border-radius: var(--radius-sm);
            transition: color var(--duration-fast) var(--ease-out), background-color var(--duration-fast) var(--ease-out);
        }

        nav a:hover {
            color: var(--brand);
            background: var(--hover-overlay);
        }

        .hero {
            margin-top: var(--space-3xl);
        }

        .pill {
            display: inline-block;
            font-size: var(--text-xs);
            letter-spacing: var(--tracking-wider);
            text-transform: uppercase;
            color: var(--brand);
            border: var(--border-width) solid var(--line-subtle);
            border-radius: var(--radius-full);
            padding: var(--space-xs) var(--space-sm);
            background: var(--brand-soft);
        }

        h1 {
            font-size: var(--text-display);
            line-height: var(--leading-tight);
            letter-spacing: var(--tracking-tight);
            margin: var(--space-md) 0 var(--space-sm);
        }

        h1 em {
            font-style: normal;
            color: var(--brand);
            text-shadow: var(--text-glow-brand);
        }

        .lead {
            color: var(--muted);
            font-size: var(--text-lg);
            max-width: var(--measure);
        }

        .lead--spaced {
            margin-top: var(--space-sm);
        }

        .grid {
            display: grid;
            gap: var(--space-md);
            grid-template-columns: repeat(auto-fit, minmax(var(--card-min), 1fr));
            margin-top: var(--space-2xl);
        }

        .card {
            background: var(--panel);
            border: var(--border-width) solid var(--line-subtle);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            box-shadow: var(--shadow-sm);
        }

        .card h2 {
            font-size: var(--text-sm);
            letter-spacing: var(--tracking-snug);
            margin: 0 0 var(--space-sm);
            color: var(--brand);
        }

        .card p {
            margin: 0;
            color: var(--muted);
            font-size: var(--text-sm);
        }

        pre {
            background: var(--code);
            border: var(--border-width) solid var(--line-subtle);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            overflow-x: auto;
            font-size: var(--text-sm);
            line-height: var(--leading-relaxed);
            color: var(--code-text);
        }

        .sql-k { color: var(--syntax-keyword); }
        .sql-c { color: var(--muted); }
        .sql-s { color: var(--syntax-number); }
        .sql-n { color: var(--syntax-variable); }

        .section {
            margin-top: var(--space-3xl);
        }

        .section h3 {
            font-size: var(--text-xl);
            letter-spacing: var(--tracking-snug);
            margin: 0 0 var(--space-sm);
            color: var(--text);
        }

        ul.checks {
            list-style: none;
            padding: 0;
            margin: 0;
            color: var(--muted);
        }

        ul.checks li {
            padding-left: var(--space-lg);
            position: relative;
            margin-bottom: var(--space-sm);
        }

        ul.checks li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--accent);
            font-weight: var(--weight-bold);
        }

        footer {
            margin-top: var(--space-3xl);
            padding-top: var(--space-lg);
            border-top: var(--border-width) solid var(--line-subtle);
            color: var(--muted);
            font-size: var(--text-xs);
            display: flex;
            justify-content: space-between;
            gap: var(--space-sm);
            flex-wrap: wrap;
        }

        .cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: var(--space-lg);
            min-height: var(--control-height);
            background: var(--accent);
            color: var(--accent-contrast);
            font-family: var(--font-sans);
            font-weight: var(--weight-semibold);
            font-size: var(--text-sm);
            padding: var(--space-sm) var(--space-lg);
            border: var(--border-width) solid transparent;
            border-radius: var(--radius-md);
            transition: background-color var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out), color var(--duration-fast) var(--ease-out);
        }

        .cta:hover {
            background: var(--accent-hover);
            color: var(--accent-contrast);
            box-shadow: var(--shadow-accent);
        }

        .cta:active {
            background: var(--accent-active);
            box-shadow: var(--shadow-inset);
        }

        @media (max-width: 640px) {
            .wrap { padding: var(--space-lg) var(--gutter) var(--space-2xl); }
            .hero { margin-top: var(--space-2xl); }
            .lead { font-size: var(--text-base); }
            .grid { margin-top: var(--space-xl); }
            .card { padding: var(--space-md); }
            pre { padding: var(--space-md); white-space: pre-wrap; overflow-wrap: anywhere; }
            .section { margin-top: var(--space-2xl); }
            footer { margin-top: var(--space-2xl); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <div class="brand">Slot<span>Lock</span></div>
            <nav>
                <a href="/demo">Demo</a>
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
            <a class="cta" href="/demo">Probar la demo →</a>
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
            <p class="lead lead--spaced">
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
            <span>PHP 8.4 · Laravel 13 · PostgreSQL 16</span>
        </footer>
    </div>
</body>
</html>
