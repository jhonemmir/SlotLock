import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { BASE_URL, futureSlot, holdPayload, holdParams } from './lib.js';

// N VUs reservan N slots distintos (uno por VU). Resultado esperado: N×201.
const N = Number(__ENV.N || 100);

const created = new Counter('reservation_created');
const rejected = new Counter('reservation_rejected');
const serverErrors = new Counter('reservation_server_error');

export const options = {
    scenarios: {
        distinct_slots: {
            executor: 'per-vu-iterations',
            vus: N,
            iterations: 1,
            maxDuration: '3m',
        },
    },
    thresholds: {
        reservation_created: [`count==${N}`],
        reservation_rejected: ['count==0'],
        reservation_server_error: ['count==0'],
        checks: ['rate==1'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    // Día base aleatorio: cada VU usa un día distinto para no chocar con
    // corridas anteriores ni entre sí.
    return { baseDay: 120 + Math.floor(Math.random() * 100) };
}

export default function (data) {
    const slot = futureSlot(data.baseDay + __VU, 10);

    const response = http.post(
        `${BASE_URL}/api/reservations`,
        holdPayload(slot),
        holdParams(crypto.randomUUID()),
    );

    if (response.status === 201) {
        created.add(1);
    } else if (response.status === 409) {
        rejected.add(1);
    } else {
        serverErrors.add(1);
    }

    check(response, {
        'status is 201': (res) => res.status === 201,
    });
}

export function handleSummary(data) {
    return {
        stdout: JSON.stringify({
            scenario: 'distinct-slots',
            n: N,
            created: data.metrics.reservation_created?.values.count ?? 0,
            rejected: data.metrics.reservation_rejected?.values.count ?? 0,
            server_errors: data.metrics.reservation_server_error?.values.count ?? 0,
            p95_ms: Math.round(data.metrics.http_req_duration?.values['p(95)'] ?? 0),
            p99_ms: Math.round(data.metrics.http_req_duration?.values['p(99)'] ?? 0),
            rps: Number((data.metrics.http_reqs?.values.rate ?? 0).toFixed(2)),
            checks_ok: data.metrics.checks?.values.rate === 1,
        }, null, 2) + '\n',
    };
}
