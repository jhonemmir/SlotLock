import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
export const RESOURCE_ID = __ENV.RESOURCE_ID || '00000000-0000-4000-8000-000000000001';
export const DEMO_KEY = __ENV.DEMO_KEY || 'slotlock-demo';

/**
 * Devuelve un slot de 1 hora, alineado a 15 minutos y en el futuro.
 * Se envía en UTC (Z); la API lo interpreta como un instante.
 */
export function futureSlot(daysAhead, hourUtc) {
    const start = new Date(Date.now() + daysAhead * 24 * 60 * 60 * 1000);
    start.setUTCHours(hourUtc, 0, 0, 0);

    return {
        start: start.toISOString(),
        end: new Date(start.getTime() + 60 * 60 * 1000).toISOString(),
    };
}

export function windowIsFree(slots, startIso, endIso) {
    const from = Date.parse(startIso);
    const to = Date.parse(endIso);
    const expected = Math.round((to - from) / (15 * 60 * 1000));

    const window = slots.filter((slot) => {
        const at = Date.parse(slot.start);
        return at >= from && at < to;
    });

    return window.length === expected && window.every((slot) => slot.status === 'free');
}

/**
 * Busca un slot libre consultando la API de disponibilidad, para que las
 * corridas sean repetibles aunque queden holds de corridas anteriores.
 */
export function pickFreeSlot() {
    for (let attempt = 0; attempt < 12; attempt++) {
        const days = 90 + Math.floor(Math.random() * 240);
        const hour = 9 + Math.floor(Math.random() * 8);
        const slot = futureSlot(days, hour);
        const date = slot.start.slice(0, 10);

        const response = http.get(
            `${BASE_URL}/api/resources/${RESOURCE_ID}/availability?date=${date}`,
            { tags: { name: 'GET availability' } },
        );

        if (response.status === 200) {
            const body = response.json();
            if (windowIsFree(body.slots || [], slot.start, slot.end)) {
                return slot;
            }
        }
    }

    throw new Error('No se encontró un slot libre después de 12 intentos.');
}

export function holdPayload(slot) {
    return JSON.stringify({
        resource_id: RESOURCE_ID,
        start_at: slot.start,
        end_at: slot.end,
    });
}

export function holdParams(key) {
    return {
        headers: {
            'Content-Type': 'application/json',
            'Idempotency-Key': key,
            'X-Demo-Key': DEMO_KEY,
        },
        tags: { name: 'POST /api/reservations' },
    };
}

export function header(response, name) {
    const key = Object.keys(response.headers).find(
        (candidate) => candidate.toLowerCase() === name.toLowerCase(),
    );

    return key ? response.headers[key] : null;
}
