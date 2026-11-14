// k6 load test (FIABILITE B1): 200 simultaneous check-ins, zero error, zero duplicate.
//
// 1. On a NON-production environment:  php artisan attendance:load-fixtures --count=200
// 2. Make the clock/day suitable (a working day) and the office coordinates known (settings).
// 3. k6 run -e BASE_URL=https://api.test.example/api -e TOKENS=storage/app/private/load-tokens.json \
//          -e LAT=5.595223 -e LNG=-0.216703 tests/load/checkin.js
// 4. Check duplicates: SELECT user_id, work_date, count(*) FROM attendances GROUP BY 1,2 HAVING count(*) > 1;  (expected: none)
//
// Each virtual user sends its intent + confirmation at the same moment, then a double submit of the
// confirmation (must answer 200 "created: false", never a 5xx nor a second row).
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter } from 'k6/metrics';

const tokens = new SharedArray('tokens', () => JSON.parse(open(__ENV.TOKENS || 'load-tokens.json')));
const serverErrors = new Counter('server_errors');
const created = new Counter('attendances_created');

export const options = {
  scenarios: {
    burst: { executor: 'per-vu-iterations', vus: tokens.length, iterations: 1, maxDuration: '2m' },
  },
  thresholds: {
    server_errors: ['count==0'],
    checks: ['rate==1.0'],
    http_req_duration: ['p(95)<3000'],
  },
};

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000/api';
const LAT = parseFloat(__ENV.LAT || '5.595223');
const LNG = parseFloat(__ENV.LNG || '-0.216703');

export default function () {
  const token = tokens[(__VU - 1) % tokens.length];
  const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' };

  const intent = http.post(`${BASE}/attendance/check-in/intent`, JSON.stringify({ type: 'gps', latitude: LAT, longitude: LNG, accuracy: 10 }), { headers });
  if (intent.status >= 500) serverErrors.add(1);
  check(intent, { 'intent 201': (r) => r.status === 201 });
  if (intent.status !== 201) return;
  const body = JSON.stringify({ intent_id: intent.json('intent_id') });

  // Confirmation and a parallel double submit.
  const [a, b] = http.batch([
    ['POST', `${BASE}/attendance/check-in/confirm`, body, { headers }],
    ['POST', `${BASE}/attendance/check-in/confirm`, body, { headers }],
  ]);
  [a, b].forEach((r) => { if (r.status >= 500) serverErrors.add(1); });
  const statuses = [a.status, b.status].sort().join(',');
  check(null, {
    'one created, one idempotent': () => statuses === '200,201',
    'same attendance id': () => a.json('data.id') === b.json('data.id'),
  });
  if (a.status === 201 || b.status === 201) created.add(1);
}
