import http from 'k6/http';
import { check, sleep, group } from 'k6';

// Konfigurasi via environment variable atau default lokal
const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000/api';
const NPSN = __ENV.NPSN || '12345678';
const USERNAME = __ENV.USERNAME || 'siswa1';
const PASSWORD = __ENV.PASSWORD || 'password';
const EXAM_ID = __ENV.EXAM_ID || '1';
const EXAM_TOKEN = __ENV.EXAM_TOKEN || 'UJIAN1';

// Skenario Load Testing: bertahap naik ke 50 Virtual Users (VU)
export const options = {
  stages: [
    { duration: '15s', target: 10 }, // Ramp-up ke 10 siswa bersamaan
    { duration: '30s', target: 50 }, // Naik ke 50 siswa bersamaan
    { duration: '15s', target: 0 },  // Ramp-down
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],   // Error rate maksimal 5%
    http_req_duration: ['p(95)<2000'], // 95% request harus di bawah 2 detik
  },
};

export default function () {
  let authToken = null;

  // 1. Login Siswa
  group('01. Login Siswa', () => {
    const res = http.post(
      `${BASE_URL}/login`,
      JSON.stringify({
        npsn: NPSN,
        username: USERNAME,
        password: PASSWORD,
      }),
      {
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
      }
    );

    const ok = check(res, {
      'login status 200': (r) => r.status === 200,
      'login dapat token': (r) => r.json('token') !== undefined,
    });

    if (ok) {
      authToken = res.json('token');
    }
  });

  if (!authToken) {
    return;
  }

  const authHeaders = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${authToken}`,
  };

  // 2. Ambil Daftar Ujian
  group('02. List Ujian Siswa', () => {
    const res = http.get(`${BASE_URL}/siswa/exams`, { headers: authHeaders });
    check(res, {
      'list exams status 200': (r) => r.status === 200,
    });
  });

  // 3. Mulai Ujian & Download Soal (Offline-First)
  let questions = [];
  group('03. Start Ujian & Download Soal', () => {
    const res = http.post(
      `${BASE_URL}/siswa/exams/${EXAM_ID}/start`,
      JSON.stringify({ token: EXAM_TOKEN }),
      { headers: authHeaders }
    );

    check(res, {
      'start exam status 200': (r) => r.status === 200,
    });

    try {
      const body = res.json();
      questions = body?.questions || body?.data?.questions || [];
    } catch (_) {
      questions = [];
    }
  });

  // Simulasi siswa mengerjakan ujian secara offline
  sleep(2);

  // 4. Submit Jawaban Ujian
  group('04. Submit Jawaban Ujian', () => {
    const answers = questions.map((q) => ({
      question_id: q.id,
      option_id: q.options && q.options.length > 0 ? q.options[0].id : null,
      answer_text: null,
    }));

    const res = http.post(
      `${BASE_URL}/siswa/exams/${EXAM_ID}/submit`,
      JSON.stringify({
        duration_seconds: 120,
        answers: answers,
      }),
      { headers: authHeaders }
    );

    check(res, {
      'submit exam status 200/201': (r) => r.status === 200 || r.status === 201,
    });
  });

  sleep(1);
}
