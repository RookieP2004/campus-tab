// Campus TAB load test (Phase 9). Runs in Docker:
//   docker compose --profile loadtest run --rm k6
// against the api-load service (database campus_tab_load, seeded with
// `php artisan loadtest:seed`). Five timed actions, each its own metric:
// sign-in, dashboard, attendance save, markbook save, counter payment.
// Numbers from a laptop are only indicative (docs/report/performance.md).
import http from 'k6/http';
import { check, group } from 'k6';
import { Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://api-load:8000/api/v1';
const fixtures = JSON.parse(open('./fixtures.json'));
const password = fixtures.password;
const schools = fixtures.schools;

const signIn = new Trend('t_sign_in', true);
const dashboard = new Trend('t_dashboard', true);
const attendance = new Trend('t_attendance_save', true);
const markbook = new Trend('t_markbook_save', true);
const payment = new Trend('t_counter_payment', true);

// SMOKE=1 runs a 20-second check of the script with two teachers and one collector.
const smoke = !!__ENV.SMOKE;

export const options = {
  scenarios: smoke
    ? {
        teachers: { executor: 'constant-vus', exec: 'teacher', vus: 2, duration: '20s' },
        counter: { executor: 'constant-vus', exec: 'collector', vus: 1, duration: '20s' },
      }
    : {
        teachers: { executor: 'ramping-vus', exec: 'teacher', startVUs: 0, stages: [{ duration: '30s', target: 20 }, { duration: '2m', target: 20 }, { duration: '15s', target: 0 }] },
        counter: { executor: 'constant-vus', exec: 'collector', vus: 3, duration: '2m30s' },
      },
  thresholds: {
    t_sign_in: ['p(95)<1500'],
    t_dashboard: ['p(95)<800'],
    t_attendance_save: ['p(95)<1000'],
    t_markbook_save: ['p(95)<1000'],
    t_counter_payment: ['p(95)<1500'],
    http_req_failed: ['rate<0.01'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max', 'count'],
};

const json = { 'Content-Type': 'application/json', Accept: 'application/json' };

function login(school, email) {
  const res = http.post(`${BASE}/auth/login`, JSON.stringify({ school_code: school, login: email, password, device_name: 'k6' }), { headers: json, tags: { name: 'sign_in' } });
  signIn.add(res.timings.duration);
  check(res, { 'signed in': (r) => r.status === 200 && !!r.json('data.token') });
  return res.json('data.token');
}

const auth = (token, extra = {}) => ({ headers: { ...json, Authorization: `Bearer ${token}`, ...extra } });

function today() {
  // Today's register in India time (the load-test schools allow edits all week).
  return __ENV.DATE || new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
}

export function teacher() {
  const school = schools[exec.vu.idInTest % schools.length];
  // Spread the virtual teachers over the seats so two never save the same register at once.
  const seat = school.teachers[(exec.vu.idInTest * 13 + exec.vu.iterationInScenario) % school.teachers.length];
  const token = login(school.code, seat.email);
  if (!token) return;

  group('dashboard', () => {
    const res = http.get(`${BASE}/me/dashboard`, auth(token));
    dashboard.add(res.timings.duration);
    check(res, { 'dashboard 200': (r) => r.status === 200 });
  });

  group('attendance', () => {
    const day = http.get(`${BASE}/attendance/sections/${seat.section_id}/days/${today()}`, auth(token));
    if (day.status !== 200) return;
    const statuses = {};
    (day.json('data.students') || []).forEach((s, i) => {
      statuses[s.id] = i % 9 === 0 ? 'A' : 'P';
    });
    const res = http.put(`${BASE}/attendance/sections/${seat.section_id}/days/${today()}`, JSON.stringify({ statuses }), auth(token));
    attendance.add(res.timings.duration);
    check(res, { 'attendance saved': (r) => r.status === 200 });
  });

  if (seat.paper_id) {
    group('markbook', () => {
      const paper = http.get(`${BASE}/papers/${seat.paper_id}`, auth(token));
      if (paper.status !== 200) return;
      const marks = {};
      (paper.json('data.rows') || paper.json('data.students') || []).forEach((r) => {
        marks[r.student_id || r.id] = { value: String(40 + Math.floor(Math.random() * 60)) };
      });
      const res = http.put(`${BASE}/papers/${seat.paper_id}/marks`, JSON.stringify({ marks }), auth(token, { 'If-Match': paper.headers.Etag || paper.headers.ETag }));
      markbook.add(res.timings.duration);
      // Two teachers on one paper get a 409 by design; that is not a failure here.
      check(res, { 'marks saved or conflict': (r) => r.status === 200 || r.status === 409 });
    });
  }

  http.post(`${BASE}/auth/logout`, null, auth(token));
}

export function collector() {
  const withFees = schools.filter((s) => s.collector);
  const school = withFees[exec.vu.idInTest % withFees.length];
  const token = login(school.code, school.collector.email);
  if (!token) return;
  const student = school.collector.students[Math.floor(Math.random() * school.collector.students.length)];
  const res = http.post(`${BASE}/fees/payments`, JSON.stringify({ student_id: student, mode: 'cash', amount: '10' }), auth(token, { 'Idempotency-Key': `${exec.vu.idInTest}-${exec.scenario.iterationInTest}-${Date.now()}` }));
  payment.add(res.timings.duration);
  check(res, { 'payment taken': (r) => r.status === 201 });
  http.post(`${BASE}/auth/logout`, null, auth(token));
}

export function handleSummary(data) {
  return { '/scripts/results/summary.json': JSON.stringify(data, null, 2), stdout: textSummary(data) };
}

function textSummary(data) {
  const pick = (name) => {
    const m = data.metrics[name];
    return m ? `${name.padEnd(20)} p95 ${m.values['p(95)'].toFixed(0)} ms  median ${m.values.med.toFixed(0)} ms  n=${m.values.count}` : `${name}: no data`;
  };
  return ['t_sign_in', 't_dashboard', 't_attendance_save', 't_markbook_save', 't_counter_payment'].map(pick).join('\n') + '\n';
}
