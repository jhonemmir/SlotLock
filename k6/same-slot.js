import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { BASE_URL, holdPayload, holdParams, pickFreeSlot } from './lib.js';

// N peticiones concurrentes al MISMO slot, cada una con su propia
// Idempotency-Key. Resultado esperado: exactamente 1×201 y (N-1)×409.
const N = Number(__ENV.N || 100);

const created = new Counter('reservation_created');
const rejected = new Counter('reservation_rejected');
const serverErrors = new Counter('reservation_server_error');

export const options = {
    scenarios: {
        same_slot: {
            executor: 'shared-iterations',
            vus: N,
            iterations: N,
            maxDuration: '3m',
        },
    },
    thresholds: {
        reservation_created: ['count==1'],
        reservation_rejected: [`count==${N - 1}`],
        reservation_server_error: ['count==0'],
        checks: ['rate==1'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return pickFreeSlot();
}

export default function (slot) {
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
        'status is 201 or 409': (res) => res.status === 201 || res.status === 409,
    });
}

export function handleSummary(data) {
    return {
        stdout: JSON.stringify({
            scenario: 'same-slot',
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
