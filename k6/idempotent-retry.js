import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { BASE_URL, futureSlot, header, holdPayload, holdParams } from './lib.js';

// Dos requests concurrentes con la MISMA Idempotency-Key y el mismo body:
// ambos deben devolver 201 con el mismo id; uno es replay del otro.
const N = Number(__ENV.N || 10);

const matched = new Counter('idempotency_matched');
const mismatched = new Counter('idempotency_mismatched');
const replayed = new Counter('idempotency_replayed');
const serverErrors = new Counter('reservation_server_error');

export const options = {
    scenarios: {
        retry: {
            executor: 'per-vu-iterations',
            vus: 1,
            iterations: N,
            maxDuration: '2m',
        },
    },
    thresholds: {
        idempotency_matched: [`count==${N}`],
        idempotency_mismatched: ['count==0'],
        reservation_server_error: ['count==0'],
        checks: ['rate==1'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return { baseDay: 150 + Math.floor(Math.random() * 100) };
}

export default function (data) {
    const slot = futureSlot(data.baseDay + __ITER, 11);
    const key = crypto.randomUUID();
    const request = {
        method: 'POST',
        url: `${BASE_URL}/api/reservations`,
        body: holdPayload(slot),
        params: holdParams(key),
    };

    const [first, second] = http.batch([request, request]);

    const sameStatus = first.status === 201 && second.status === 201;
    const sameId = first.json('id') === second.json('id');
    const isReplay = header(first, 'Idempotency-Replayed') === 'true'
        || header(second, 'Idempotency-Replayed') === 'true';

    if (sameStatus && sameId) {
        matched.add(1);
    } else {
        mismatched.add(1);
    }

    if (isReplay) {
        replayed.add(1);
    }

    if (first.status >= 500 || second.status >= 500) {
        serverErrors.add(1);
    }

    check(first, {
        'both 201 with the same id': () => sameStatus && sameId,
        'one response is a replay': () => isReplay,
    });
}

export function handleSummary(data) {
    return {
        stdout: JSON.stringify({
            scenario: 'idempotent-retry',
            iterations: N,
            matched: data.metrics.idempotency_matched?.values.count ?? 0,
            mismatched: data.metrics.idempotency_mismatched?.values.count ?? 0,
            replayed: data.metrics.idempotency_replayed?.values.count ?? 0,
            server_errors: data.metrics.reservation_server_error?.values.count ?? 0,
            p95_ms: Math.round(data.metrics.http_req_duration?.values['p(95)'] ?? 0),
            p99_ms: Math.round(data.metrics.http_req_duration?.values['p(99)'] ?? 0),
            rps: Number((data.metrics.http_reqs?.values.rate ?? 0).toFixed(2)),
            checks_ok: data.metrics.checks?.values.rate === 1,
        }, null, 2) + '\n',
    };
}
