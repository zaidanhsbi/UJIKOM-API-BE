<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamSubmission;
use App\Models\License;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Rombel;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SmkDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        // 1. Pastikan Sekolah SMK Negeri 1 Nusantara & Lisensi Pro Aktif
        $school = School::updateOrCreate(
            ['npsn' => '20201234'],
            [
                'name' => 'SMK Negeri 1 Nusantara',
                'is_active' => true,
            ]
        );

        License::updateOrCreate(
            ['school_id' => $school->id],
            [
                'plan' => 'Pro',
                'max_students' => 1000,
                'starts_at' => now()->subMonths(2),
                'expires_at' => now()->addYear(),
                'status' => 'active',
            ]
        );

        // 2. Pastikan Akun Admin ada
        User::updateOrCreate(
            ['school_id' => $school->id, 'username' => 'admin'],
            [
                'rombel_id' => null,
                'role' => 'admin',
                'name' => 'Administrator SMK Negeri 1 Nusantara',
                'email' => 'admin@smkn1.sch.id',
                'nisn' => null,
                'password' => $password,
                'is_active' => true,
            ]
        );

        // 2.5. Tahun Ajaran (2025/2026 nonaktif, 2026/2027 Ganjil aktif)
        AcademicYear::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2025/2026', 'semester' => 'Genap'],
            ['is_active' => false]
        );
        $activeAcademicYear = AcademicYear::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2026/2027', 'semester' => 'Ganjil'],
            ['is_active' => true]
        );
        AcademicYear::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2026/2027', 'semester' => 'Genap'],
            ['is_active' => false]
        );

        // 3. Rombel SMK (RPL & TKJ)
        $rombelNames = [
            'X RPL 1',
            'XI RPL 1',
            'XII RPL 1',
            'XII RPL 2',
            'XI TKJ 1',
            'XII TKJ 1',
        ];
        $rombels = [];
        foreach ($rombelNames as $name) {
            $rombels[$name] = Rombel::updateOrCreate(
                ['school_id' => $school->id, 'name' => $name],
                [
                    'academic_year' => $activeAcademicYear->name,
                    'academic_year_id' => $activeAcademicYear->id,
                ]
            );
        }

        // 4. Mata Pelajaran Kejuruan & Umum SMK
        $subjectList = [
            'PWEB' => 'Pemrograman Web & Perangkat Bergerak',
            'BDAT' => 'Basis Data',
            'JARKOM' => 'Administrasi Infrastruktur Jaringan',
            'MTK' => 'Matematika Kejuruan',
            'BING' => 'Bahasa Inggris Teknik',
            'PKK' => 'Produk Kreatif & Kewirausahaan',
        ];
        $subjects = [];
        foreach ($subjectList as $code => $name) {
            $subjects[$code] = Subject::firstOrCreate(
                ['school_id' => $school->id, 'code' => $code],
                ['name' => $name]
            );
        }

        // 5. Akun Guru (GR-001 s/d GR-005)
        $guruData = [
            ['username' => 'GR-001', 'name' => 'Budi Santoso, S.Pd.', 'email' => 'budi.santoso@smkn1.sch.id'],
            ['username' => 'GR-002', 'name' => 'Siti Aminah, S.Kom.', 'email' => 'siti.aminah@smkn1.sch.id'],
            ['username' => 'GR-003', 'name' => 'Hendra Wijaya, S.T.', 'email' => 'hendra.wijaya@smkn1.sch.id'],
            ['username' => 'GR-004', 'name' => 'Dewi Lestari, S.Pd.', 'email' => 'dewi.lestari@smkn1.sch.id'],
            ['username' => 'GR-005', 'name' => 'Agus Pratama, S.Kom.', 'email' => 'agus.pratama@smkn1.sch.id'],
        ];
        $gurus = [];
        foreach ($guruData as $g) {
            $gurus[$g['username']] = User::updateOrCreate(
                ['school_id' => $school->id, 'username' => $g['username']],
                [
                    'rombel_id' => null,
                    'role' => 'guru',
                    'name' => $g['name'],
                    'email' => $g['email'],
                    'nisn' => null,
                    'password' => $password,
                    'password_plain' => 'password',
                    'is_active' => true,
                ]
            );
        }

        // 6. Penugasan Guru (TeacherAssignments)
        $assignments = [
            ['guru' => 'GR-001', 'subject' => 'PWEB', 'rombel' => 'XII RPL 1'],
            ['guru' => 'GR-001', 'subject' => 'PWEB', 'rombel' => 'XII RPL 2'],
            ['guru' => 'GR-001', 'subject' => 'BDAT', 'rombel' => 'XI RPL 1'],
            ['guru' => 'GR-002', 'subject' => 'BDAT', 'rombel' => 'XII RPL 1'],
            ['guru' => 'GR-002', 'subject' => 'PWEB', 'rombel' => 'XI RPL 1'],
            ['guru' => 'GR-003', 'subject' => 'JARKOM', 'rombel' => 'XI TKJ 1'],
            ['guru' => 'GR-003', 'subject' => 'JARKOM', 'rombel' => 'XII TKJ 1'],
            ['guru' => 'GR-004', 'subject' => 'MTK', 'rombel' => 'X RPL 1'],
            ['guru' => 'GR-004', 'subject' => 'BING', 'rombel' => 'XII RPL 1'],
            ['guru' => 'GR-005', 'subject' => 'PKK', 'rombel' => 'XII RPL 2'],
            ['guru' => 'GR-005', 'subject' => 'PKK', 'rombel' => 'XII TKJ 1'],
        ];

        foreach ($assignments as $a) {
            TeacherAssignment::firstOrCreate([
                'school_id' => $school->id,
                'teacher_id' => $gurus[$a['guru']]->id,
                'subject_id' => $subjects[$a['subject']]->id,
                'rombel_id' => $rombels[$a['rombel']]->id,
            ]);
        }

        // 7. 30 Akun Siswa Tersebar di 6 Rombel (5 siswa per rombel)
        $namaSiswa = [
            'Ahmad Fauzi', 'Rizky Ramadhan', 'Nabila Putri', 'Dimas Prasetyo', 'Salsa Azzahra',
            'Fajar Nugroho', 'Aulia Rahma', 'Bagas Saputra', 'Citra Kirana', 'Daffa Pratama',
            'Eka Sari', 'Farhan Hakim', 'Gita Permata', 'Hafiz Maulana', 'Indah Cahyani',
            'Joko Susilo', 'Kartika Sari', 'Lukman Hakim', 'Maya Anggraini', 'Naufal Aziz',
            'Olivia Putri', 'Pandu Wijaya', 'Qoriatul Aini', 'Rendi Kurniawan', 'Shinta Dewi',
            'Taufik Hidayat', 'Umar Faruq', 'Vina Melati', 'Wahyu Setiawan', 'Zahra Amalia',
        ];

        $studentsByRombel = [];
        $idx = 0;
        foreach ($rombelNames as $rName) {
            $studentsByRombel[$rName] = [];
            for ($i = 0; $i < 5; $i++) {
                $nisn = '00820000' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);
                $student = User::updateOrCreate(
                    ['school_id' => $school->id, 'nisn' => $nisn],
                    [
                        'rombel_id' => $rombels[$rName]->id,
                        'academic_year_id' => $activeAcademicYear->id,
                        'role' => 'siswa',
                        'name' => $namaSiswa[$idx],
                        'email' => 'siswa' . ($idx + 1) . '@smkn1.sch.id',
                        'username' => $nisn,
                        'password' => $password,
                        'password_plain' => 'password',
                        'is_active' => true,
                    ]
                );
                $studentsByRombel[$rName][] = $student;
                $idx++;
            }
        }

        // 8. Bank Soal & Soal Bertema SMK
        $bankConfigs = [
            [
                'guru' => 'GR-001',
                'subject' => 'PWEB',
                'grade' => 'XII RPL 1',
                'name' => 'Bank Soal PAS Pemrograman Web (Laravel & React)',
                'questions' => [
                    ['Apa fungsi utama dari `php artisan migrate` pada framework Laravel?', ['Menjalankan server lokal', 'Membuat atau memperbarui tabel database', 'Menghapus cache aplikasi', 'Menginstall package Composer', 'Membuat file controller baru'], 'B'],
                    ['Hook React yang digunakan untuk menangani side-effect seperti fetch API adalah...', ['useState', 'useEffect', 'useRef', 'useMemo', 'useContext'], 'B'],
                    ['HTTP Status Code yang menandakan request berhasil dan resource baru berhasil dibuat adalah...', ['200', '201', '400', '401', '404'], 'B'],
                    ['Middleware di Laravel berfungsi untuk...', ['Menyimpan gambar ke storage', 'Menyaring HTTP request sebelum masuk ke controller', 'Mengubah format JSON menjadi XML', 'Membuat tampilan Blade', 'Menghubungkan frontend ke CSS'], 'B'],
                    ['Perintah untuk mem-build aplikasi React Vite ke mode production adalah...', ['npm run dev', 'npm run test', 'npm run build', 'npm start', 'npx vite serve'], 'C'],
                ],
            ],
            [
                'guru' => 'GR-002',
                'subject' => 'BDAT',
                'grade' => 'XII RPL 1',
                'name' => 'Bank Soal Basis Data Relasional & SQL',
                'questions' => [
                    ['Perintah SQL untuk mengambil data unik tanpa duplikat adalah...', ['SELECT ALL', 'SELECT UNIQUE', 'SELECT DISTINCT', 'SELECT GROUP', 'SELECT ONLY'], 'C'],
                    ['Relasi dimana satu baris di tabel A dapat berhubungan dengan banyak baris di tabel B disebut...', ['One-to-One', 'One-to-Many', 'Many-to-Many', 'Self-Join', 'Cross-Join'], 'B'],
                    ['Constraint yang menjamin bahwa nilai pada kolom tidak boleh kosong (NULL) adalah...', ['UNIQUE', 'PRIMARY KEY', 'NOT NULL', 'CHECK', 'DEFAULT'], 'C'],
                    ['Klausa SQL yang digunakan untuk memfilter hasil agregasi (seperti COUNT, SUM) adalah...', ['WHERE', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT'], 'D'],
                    ['Normalisasi database bertujuan utama untuk...', ['Memperbesar ukuran file database', 'Mengurangi redundansi dan anomali data', 'Menghapus seluruh foreign key', 'Membuat query menjadi lebih lambat', 'Mengubah tabel menjadi JSON'], 'B'],
                ],
            ],
            [
                'guru' => 'GR-003',
                'subject' => 'JARKOM',
                'grade' => 'XI TKJ 1',
                'name' => 'Bank Soal Jaringan & Subnetting TKJ',
                'questions' => [
                    ['Subnet mask default untuk IP Address kelas C adalah...', ['255.0.0.0', '255.255.0.0', '255.255.255.0', '255.255.255.128', '255.255.255.252'], 'C'],
                    ['Protokol yang digunakan untuk menerjemahkan nama domain menjadi IP Address adalah...', ['DHCP', 'DNS', 'FTP', 'SSH', 'HTTP'], 'B'],
                    ['Port default untuk protokol HTTPS adalah...', ['21', '22', '80', '443', '8080'], 'D'],
                    ['Perangkat jaringan yang bekerja pada Layer 3 OSI (Network Layer) adalah...', ['Hub', 'Switch Unmanaged', 'Repeater', 'Router', 'Kabel UTP'], 'D'],
                    ['Perintah CMD/Terminal untuk mengecek konektivitas ke suatu host adalah...', ['ipconfig', 'ping', 'netstat', 'nslookup', 'route'], 'B'],
                ],
            ],
            [
                'guru' => 'GR-005',
                'subject' => 'PKK',
                'grade' => 'XII RPL 2',
                'name' => 'Bank Soal Produk Kreatif & Kewirausahaan',
                'questions' => [
                    ['Analisis SWOT terdiri dari komponen berikut, kecuali...', ['Strengths', 'Weaknesses', 'Opportunities', 'Threats', 'Solutions'], 'E'],
                    ['Biaya yang jumlahnya tetap meskipun volume produksi berubah disebut...', ['Biaya Variabel', 'Biaya Tetap (Fixed Cost)', 'Biaya Marginal', 'Biaya Operasional', 'Harga Pokok Penjualan'], 'B'],
                    ['Dokumen yang berisi rencana bisnis secara tertulis mulai dari visi hingga proyeksi keuangan disebut...', ['Proposal Usaha / Business Plan', 'Faktur Penjualan', 'Surat Jalan', 'Nota Kontan', 'Kwitansi'], 'A'],
                    ['Titik dimana total pendapatan sama dengan total biaya (tidak untung dan tidak rugi) disebut...', ['ROI', 'BEP (Break Even Point)', 'Profit Margin', 'Cash Flow', 'Omzet'], 'B'],
                    ['Hak eksklusif yang diberikan negara kepada pencipta atas karya di bidang ilmu pengetahuan, seni, dan sastra adalah...', ['Hak Paten', 'Hak Merek', 'Hak Cipta', 'Rahasia Dagang', 'Desain Industri'], 'C'],
                ],
            ],
        ];

        $banks = [];
        foreach ($bankConfigs as $cfg) {
            $guru = $gurus[$cfg['guru']];
            $subject = $subjects[$cfg['subject']];

            $bank = QuestionBank::firstOrCreate(
                [
                    'school_id' => $school->id,
                    'owner_id' => $guru->id,
                    'name' => $cfg['name'],
                ],
                [
                    'subject_id' => $subject->id,
                    'grade' => $cfg['grade'],
                    'visibility' => 'school',
                ]
            );

            if ($bank->questions()->count() === 0) {
                $letters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($cfg['questions'] as $qItem) {
                    $options = [];
                    foreach ($qItem[1] as $optIdx => $optText) {
                        $options[$letters[$optIdx]] = ['text' => $optText, 'image' => null];
                    }
                    Question::create([
                        'school_id' => $school->id,
                        'bank_id' => $bank->id,
                        'type' => 'pg',
                        'difficulty' => 'sedang',
                        'weight' => 20,
                        'content' => [
                            'text' => $qItem[0],
                            'options' => $options,
                            'answer' => $qItem[2],
                        ],
                        'version' => 1,
                    ]);
                }
            }

            $banks[$cfg['subject']] = $bank;
        }

        // 9. Buat Ujian Demo (Aktif, Selesai, & Terjadwal) + Submissions untuk Rekap Nilai
        $examPwebAktif = Exam::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'PAS Pemrograman Web - XII RPL 1 (Aktif)'],
            [
                'teacher_id' => $gurus['GR-001']->id,
                'question_bank_id' => $banks['PWEB']->id,
                'subject_id' => $subjects['PWEB']->id,
                'token' => 'PWEB01',
                'duration_minutes' => 90,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(4),
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'status' => 'ongoing',
            ]
        );

        $examBdatSelesai = Exam::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'UH Basis Data - XII RPL 1 (Selesai)'],
            [
                'teacher_id' => $gurus['GR-002']->id,
                'question_bank_id' => $banks['BDAT']->id,
                'subject_id' => $subjects['BDAT']->id,
                'token' => 'BDAT01',
                'duration_minutes' => 60,
                'starts_at' => now()->subDays(3),
                'ends_at' => now()->subDays(2),
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'status' => 'finished',
            ]
        );

        Exam::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'PTS Jaringan Komputer - XI TKJ 1 (Terjadwal)'],
            [
                'teacher_id' => $gurus['GR-003']->id,
                'question_bank_id' => $banks['JARKOM']->id,
                'subject_id' => $subjects['JARKOM']->id,
                'token' => 'TKJ001',
                'duration_minutes' => 75,
                'starts_at' => now()->addDays(2),
                'ends_at' => now()->addDays(2)->addHours(3),
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'status' => 'scheduled',
            ]
        );

        // 10. Seed Submissions & Answers untuk Ujian Selesai (agar Rekap Nilai & Dashboard langsung terisi)
        $bdatQuestions = $banks['BDAT']->questions()->get();
        foreach ($studentsByRombel['XII RPL 1'] as $sIdx => $student) {
            $submission = ExamSubmission::firstOrCreate(
                ['exam_id' => $examBdatSelesai->id, 'student_id' => $student->id],
                [
                    'school_id' => $school->id,
                    'started_at' => now()->subDays(3),
                    'finished_at' => now()->subDays(3)->addMinutes(35 + ($sIdx * 4)),
                    'score' => 60 + ($sIdx * 10 > 40 ? 40 : $sIdx * 10),
                ]
            );

            if ($submission->answers()->count() === 0) {
                foreach ($bdatQuestions as $qIdx => $q) {
                    $correctAns = $q->content['answer'] ?? 'A';
                    $studentAns = ($qIdx === 0 && $sIdx === 0) ? 'A' : $correctAns;
                    $isCorrect = $studentAns === $correctAns;

                    ExamAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id' => $q->id,
                        'answer' => $studentAns,
                        'is_correct' => $isCorrect,
                        'score_earned' => $isCorrect ? $q->weight : 0,
                        'ver' => 1,
                    ]);
                }
            }
        }

        // 11. Seed beberapa submission pada Ujian Aktif (untuk demo monitoring ujian)
        $pwebQuestions = $banks['PWEB']->questions()->get();
        foreach (array_slice($studentsByRombel['XII RPL 1'], 0, 2) as $sIdx => $student) {
            $submission = ExamSubmission::firstOrCreate(
                ['exam_id' => $examPwebAktif->id, 'student_id' => $student->id],
                [
                    'school_id' => $school->id,
                    'started_at' => now()->subMinutes(40),
                    'finished_at' => now()->subMinutes(10),
                    'score' => $sIdx === 0 ? 100 : 80,
                ]
            );

            if ($submission->answers()->count() === 0) {
                foreach ($pwebQuestions as $q) {
                    $correctAns = $q->content['answer'] ?? 'B';
                    ExamAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id' => $q->id,
                        'answer' => $correctAns,
                        'is_correct' => true,
                        'score_earned' => $q->weight,
                        'ver' => 1,
                    ]);
                }
            }
        }

        $this->command?->info('SmkDemoSeeder berhasil dijalankan! Data demo SMK (6 rombel, 6 mapel, 5 guru, 30 siswa, 4 bank soal, 3 ujian & rekap nilai) siap digunakan.');
    }
}
