<?php

namespace App\Services\Guru;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QuestionService
{
    /**
     * Membersihkan teks soal dari tag atau karakter berbahaya.
     */
    public function sanitizeText(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * Memeriksa apakah bank soal sedang digunakan oleh ujian yang terkunci.
     */
    public function isBankLockedByExam(int $bankId): bool
    {
        if (! Schema::hasTable('exams') || ! Schema::hasColumn('exams', 'question_bank_id')) {
            return false;
        }

        return DB::table('exams')
            ->where('question_bank_id', $bankId)
            ->whereIn('status', ['active', 'aktif', 'finished', 'selesai'])
            ->exists();
    }

    /**
     * Memvalidasi dan membersihkan struktur konten soal berdasarkan tipenya.
     */
    public function validateAndSanitizeContent(string $type, array $content): array
    {
        $text = $this->sanitizeText((string) ($content['text'] ?? ''));
        if ($text === '') {
            throw ValidationException::withMessages([
                'content.text' => ['Teks soal wajib diisi.'],
            ]);
        }

        $base = [
            'text' => $text,
            'image' => ! empty($content['image']) ? (string) $content['image'] : null,
            'alt_text' => ! empty($content['alt_text']) ? $this->sanitizeText((string) $content['alt_text']) : null,
        ];

        if ($type === 'pg' || $type === 'pgk') {
            $rawOptions = $content['options'] ?? [];
            $options = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
                $opt = $rawOptions[$key] ?? [];
                $optText = is_array($opt) ? (string) ($opt['text'] ?? '') : (string) $opt;
                $optImage = is_array($opt) && ! empty($opt['image']) ? (string) $opt['image'] : null;
                $options[$key] = [
                    'text' => $this->sanitizeText($optText),
                    'image' => $optImage,
                ];
            }

            if ($type === 'pg') {
                $answer = strtoupper((string) ($content['answer'] ?? ''));
                if (! in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                    throw ValidationException::withMessages([
                        'content.answer' => ['Pilih satu kunci jawaban benar (A-E).'],
                    ]);
                }

                return array_merge($base, ['options' => $options, 'answer' => $answer]);
            }

            $answers = array_values(array_unique(array_filter(
                array_map('strtoupper', (array) ($content['answers'] ?? [])),
                fn ($a) => in_array($a, ['A', 'B', 'C', 'D', 'E'], true)
            )));

            if (count($answers) === 0) {
                throw ValidationException::withMessages([
                    'content.answers' => ['Pilih minimal satu kunci jawaban benar.'],
                ]);
            }

            return array_merge($base, ['options' => $options, 'answers' => $answers]);
        }

        if ($type === 'bs') {
            $answer = (string) ($content['answer'] ?? '');
            if (! in_array($answer, ['true', 'false'], true)) {
                throw ValidationException::withMessages([
                    'content.answer' => ['Pilih kunci jawaban Benar atau Salah.'],
                ]);
            }

            return array_merge($base, ['answer' => $answer]);
        }

        if ($type === 'jd') {
            $pairs = collect($content['pairs'] ?? [])
                ->map(fn ($p) => [
                    'left' => $this->sanitizeText((string) ($p['left'] ?? '')),
                    'right' => $this->sanitizeText((string) ($p['right'] ?? '')),
                ])
                ->filter(fn ($p) => $p['left'] !== '' && $p['right'] !== '')
                ->values()
                ->all();

            if (count($pairs) < 2) {
                throw ValidationException::withMessages([
                    'content.pairs' => ['Minimal harus ada 2 pasangan kiri-kanan yang terisi.'],
                ]);
            }

            return array_merge($base, ['pairs' => $pairs]);
        }

        // ur (Urutan)
        $items = collect($content['items'] ?? [])
            ->map(fn ($item) => $this->sanitizeText((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->all();

        if (count($items) < 2) {
            throw ValidationException::withMessages([
                'content.items' => ['Minimal harus ada 2 item urutan yang terisi.'],
            ]);
        }

        return array_merge($base, ['items' => $items]);
    }

    /**
     * Mengunggah file media gambar untuk soal.
     */
    public function uploadMedia(UploadedFile $file, int $schoolId, ?string $altText): QuestionMedia
    {
        $raw = file_get_contents($file->getRealPath());
        $path = $raw !== false
            ? $this->compressAndStoreImage($raw, $schoolId, 'img_')
            : null;

        if ($path === null) {
            $path = $file->store("question-media/{$schoolId}", 'public');
        }

        return QuestionMedia::create([
            'school_id' => $schoolId,
            'question_id' => null,
            'file' => $path,
            'alt_text' => $altText !== null && $altText !== '' ? $this->sanitizeText($altText) : null,
        ]);
    }

    /**
     * Mengubah gambar mentah menjadi WebP terkompresi (maks 800px) di disk public.
     */
    private function compressAndStoreImage(string $rawBinary, int $schoolId, string $prefix = 'img_'): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }

        // Cek dimensi dari header dulu agar gambar beresolusi raksasa tidak menghabiskan RAM GD
        $info = @getimagesizefromstring($rawBinary);
        if (is_array($info) && ($info[0] * $info[1]) > 16000000) {
            return null;
        }

        $src = @imagecreatefromstring($rawBinary);
        unset($rawBinary);
        if ($src === false) {
            return null;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        $maxDim = 800;

        if ($origW > $maxDim || $origH > $maxDim) {
            $ratio = min($maxDim / $origW, $maxDim / $origH);
            $newW = (int) round($origW * $ratio);
            $newH = (int) round($origH * $ratio);
            $dst = imagecreatetruecolor($newW, $newH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagewebp($src, null, 72);
        $webpData = ob_get_clean();
        imagedestroy($src);

        if ($webpData === false) {
            return null;
        }

        $path = "question-media/{$schoolId}/" . uniqid($prefix, true) . '.webp';
        Storage::disk('public')->put($path, $webpData);

        return $path;
    }

    /**
     * Mengimpor banyak soal sekaligus dari baris data Excel/array.
     */
    public function bulkImportFromRows(QuestionBank $questionBank, int $schoolId, array $rows): array
    {
        $importedCount = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Baris Excel (baris 1 = header)
            $rawType = strtolower(trim((string) ($row['type'] ?? '')));
            $text = $this->sanitizeText((string) ($row['text'] ?? ''));
            $answer = strtoupper(trim((string) ($row['answer'] ?? '')));
            $weight = max(1, (int) ($row['weight'] ?? 1));

            if ($text === '') {
                $errors[] = "Baris {$rowNumber} gagal karena teks soal kosong.";
                continue;
            }

            $options = [
                'A' => ['text' => $this->sanitizeText((string) ($row['option_a'] ?? '')), 'image' => null],
                'B' => ['text' => $this->sanitizeText((string) ($row['option_b'] ?? '')), 'image' => null],
                'C' => ['text' => $this->sanitizeText((string) ($row['option_c'] ?? '')), 'image' => null],
                'D' => ['text' => $this->sanitizeText((string) ($row['option_d'] ?? '')), 'image' => null],
                'E' => ['text' => $this->sanitizeText((string) ($row['option_e'] ?? '')), 'image' => null],
            ];

            $err = $this->storeImportedRow(
                $questionBank->id,
                $schoolId,
                $rowNumber,
                $rawType,
                $text,
                null,
                $options,
                $answer,
                $weight
            );

            if ($err !== null) {
                $errors[] = $err;
            } else {
                $importedCount++;
            }
        }

        return [
            'imported_count' => $importedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Mengimpor soal dari file dokumen DOCX ke dalam bank soal.
     */
    public function importFromDocx(QuestionBank $questionBank, int $schoolId, string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw ValidationException::withMessages([
                'file' => ['File Word (.docx) tidak dapat dibuka.'],
            ]);
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            throw ValidationException::withMessages([
                'file' => ['Format dokumen Word tidak valid.'],
            ]);
        }

        $relMap = $this->extractDocxRelationships($zip);

        $dom = new \DOMDocument();
        @$dom->loadXML($documentXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $tableRows = $xpath->query('//w:tbl[1]/w:tr');
        $importedCount = 0;
        $errors = [];

        foreach ($tableRows as $index => $tr) {
            if ($index === 0) {
                continue; // Skip header row
            }

            $rowNumber = $index + 1;
            $cells = $xpath->query('./w:tc', $tr);
            if ($cells->length < 8) {
                continue;
            }

            $rawType = strtolower($this->extractDocxCell($cells->item(0), $xpath, $zip, $relMap, $schoolId)['text']);
            $questionCell = $this->extractDocxCell($cells->item(1), $xpath, $zip, $relMap, $schoolId);
            $options = [
                'A' => $this->extractDocxCell($cells->item(2), $xpath, $zip, $relMap, $schoolId),
                'B' => $this->extractDocxCell($cells->item(3), $xpath, $zip, $relMap, $schoolId),
                'C' => $this->extractDocxCell($cells->item(4), $xpath, $zip, $relMap, $schoolId),
                'D' => $this->extractDocxCell($cells->item(5), $xpath, $zip, $relMap, $schoolId),
                'E' => $this->extractDocxCell($cells->item(6), $xpath, $zip, $relMap, $schoolId),
            ];
            $answer = strtoupper($this->extractDocxCell($cells->item(7), $xpath, $zip, $relMap, $schoolId)['text']);
            $weight = $cells->length >= 9
                ? max(1, (int) $this->extractDocxCell($cells->item(8), $xpath, $zip, $relMap, $schoolId)['text'])
                : 1;

            if ($questionCell['text'] === '' && $questionCell['image'] === null) {
                continue;
            }

            $err = $this->storeImportedRow(
                $questionBank->id,
                $schoolId,
                $rowNumber,
                $rawType,
                $questionCell['text'] ?: 'Perhatikan gambar berikut.',
                $questionCell['image'],
                $options,
                $answer,
                $weight
            );

            if ($err !== null) {
                $errors[] = $err;
            } else {
                $importedCount++;
            }
        }

        $zip->close();

        return [
            'imported_count' => $importedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Membuat file template DOCX untuk impor soal dan mengembalikan path-nya.
     */
    public function buildDocxTemplatePath(): string
    {
        $headers = [
            'Jenis Soal (PG/PGK/BS/JD/UR)',
            'Teks Soal',
            'Opsi A',
            'Opsi B',
            'Opsi C',
            'Opsi D',
            'Opsi E',
            'Kunci Jawaban',
            'Bobot',
        ];
        $rows = [
            ['PG', 'Ibukota negara Indonesia adalah...', 'Bandung', 'Jakarta', 'Surabaya', 'Medan', 'Makassar', 'B', '2'],
            ['PGK', 'Pilih bilangan prima di bawah ini!', '2', '4', '5', '6', '7', 'A,C,E', '3'],
            ['BS', 'Matahari terbit dari arah timur.', '', '', '', '', '', 'B', '1'],
            ['JD', 'Pasangkan negara dengan ibu kotanya.', 'Indonesia = Jakarta', 'Jepang = Tokyo', 'Prancis = Paris', '', '', 'SESUAI', '4'],
            ['UR', 'Urutkan peristiwa sejarah dari yang paling awal.', 'Proklamasi 1945', 'KMB 1949', 'Pemilu Pertama 1955', '', '', 'URUT', '3'],
        ];

        $buildRowXml = function (array $cells, bool $isHeader = false): string {
            $xml = '<w:tr>';
            foreach ($cells as $cell) {
                $escaped = htmlspecialchars((string) $cell, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $bold = $isHeader ? '<w:rPr><w:b/></w:rPr>' : '';
                $xml .= '<w:tc><w:p><w:r>' . $bold . '<w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';

            return $xml;
        };

        $rowsXml = $buildRowXml($headers, true);
        foreach ($rows as $r) {
            $rowsXml .= $buildRowXml($r);
        }

        $tableXml = '<w:tbl>'
            . '<w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tblBorders></w:tblPr>'
            . $rowsXml
            . '</w:tbl>';

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Template Import Soal BinoExam (Isi tabel di bawah, gambar dapat ditempel langsung di sel Soal atau Opsi)</w:t></w:r></w:p>'
            . $tableXml
            . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/></w:sectPr>'
            . '</w:body></w:document>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $tmpPath = tempnam(sys_get_temp_dir(), 'binoexam_tpl_') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        return $tmpPath;
    }

    /**
     * Menyimpan satu baris soal hasil impor ke database.
     */
    private function storeImportedRow(
        int $bankId,
        int $schoolId,
        int $rowNumber,
        string $rawType,
        string $text,
        ?string $image,
        array $options,
        string $answer,
        int $weight
    ): ?string {
        $createQuestion = function (string $type, array $content) use ($schoolId, $bankId, $weight): void {
            Question::create([
                'school_id' => $schoolId,
                'bank_id' => $bankId,
                'type' => $type,
                'difficulty' => 'sedang',
                'content' => array_merge([
                    'text' => '',
                    'image' => null,
                    'alt_text' => null,
                ], $content),
                'weight' => $weight,
                'version' => 1,
            ]);
        };

        if ($rawType === 'pg') {
            if ($answer === '' || ! in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                return "Baris {$rowNumber} gagal karena kunci jawaban PG harus A, B, C, D, atau E.";
            }

            $createQuestion('pg', [
                'text' => $text,
                'image' => $image,
                'options' => $options,
                'answer' => $answer,
            ]);

            return null;
        }

        if ($rawType === 'pgk') {
            $answers = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $answer)),
                fn ($a) => in_array($a, ['A', 'B', 'C', 'D', 'E'], true)
            )));

            if (count($answers) === 0) {
                return "Baris {$rowNumber} gagal karena kunci jawaban PGK harus berisi A-E (pisahkan dengan koma, mis. A,C).";
            }

            $createQuestion('pgk', [
                'text' => $text,
                'image' => $image,
                'options' => $options,
                'answers' => $answers,
            ]);

            return null;
        }

        if ($rawType === 'bs') {
            $isTrue = in_array($answer, ['B', 'BENAR', 'TRUE', '1'], true);
            $isFalse = in_array($answer, ['S', 'SALAH', 'FALSE', '0'], true);

            if (! $isTrue && ! $isFalse) {
                return "Baris {$rowNumber} gagal karena kunci jawaban BS harus Benar/Salah (B/S).";
            }

            $createQuestion('bs', [
                'text' => $text,
                'image' => $image,
                'answer' => $isTrue ? 'true' : 'false',
            ]);

            return null;
        }

        if ($rawType === 'jd') {
            $pairs = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
                $rawPair = trim((string) ($options[$key]['text'] ?? ''));
                if ($rawPair === '') {
                    continue;
                }
                $parts = preg_split('/\s*(?:=|->|\|)\s*/', $rawPair, 2);
                if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                    $pairs[] = [
                        'left' => trim($parts[0]),
                        'right' => trim($parts[1]),
                    ];
                }
            }

            if (count($pairs) < 2) {
                return "Baris {$rowNumber} gagal karena Menjodohkan (JD) butuh minimal 2 pasangan di Opsi A-E dengan format 'Kiri = Kanan'.";
            }

            $createQuestion('jd', [
                'text' => $text,
                'image' => $image,
                'pairs' => $pairs,
            ]);

            return null;
        }

        if ($rawType === 'ur') {
            $items = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
                $item = trim((string) ($options[$key]['text'] ?? ''));
                if ($item !== '') {
                    $items[] = $item;
                }
            }

            if (count($items) < 2) {
                return "Baris {$rowNumber} gagal karena Urutan (UR) butuh minimal 2 item berurutan di Opsi A-E.";
            }

            $createQuestion('ur', [
                'text' => $text,
                'image' => $image,
                'items' => $items,
            ]);

            return null;
        }

        return "Baris {$rowNumber} gagal karena jenis soal '{$rawType}' tidak didukung (gunakan PG, PGK, BS, JD, atau UR).";
    }

    /**
     * Mengekstrak daftar relasi media dari arsip DOCX.
     */
    private function extractDocxRelationships(\ZipArchive $zip): array
    {
        $relMap = [];
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml === false) {
            return $relMap;
        }

        $relsDom = new \DOMDocument();
        @$relsDom->loadXML($relsXml);
        foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ($id && $target) {
                $relMap[$id] = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');
            }
        }

        return $relMap;
    }

    /**
     * Mengekstrak teks dan gambar dari satu sel tabel DOCX.
     */
    private function extractDocxCell(
        \DOMElement $tc,
        \DOMXPath $xpath,
        \ZipArchive $zip,
        array $relMap,
        int $schoolId
    ): array {
        $paragraphs = [];
        foreach ($xpath->query('.//w:p', $tc) as $p) {
            $pText = '';
            foreach ($xpath->query('.//w:t', $p) as $t) {
                $pText .= $t->textContent;
            }
            $paragraphs[] = $pText;
        }
        $text = $this->sanitizeText(implode("\n", $paragraphs));

        $imagePath = null;
        $blip = $xpath->query('.//a:blip/@r:embed', $tc)->item(0);
        if ($blip && isset($relMap[$blip->nodeValue])) {
            $mediaEntry = $relMap[$blip->nodeValue];
            $binary = $zip->getFromName($mediaEntry);
            if ($binary !== false) {
                $filename = $this->compressAndStoreImage($binary, $schoolId, 'docx_');
                if ($filename === null) {
                    $ext = pathinfo($mediaEntry, PATHINFO_EXTENSION) ?: 'png';
                    $filename = 'question-media/' . $schoolId . '/' . uniqid('docx_', true) . '.' . $ext;
                    Storage::disk('public')->put($filename, $binary);
                }
                QuestionMedia::create([
                    'school_id' => $schoolId,
                    'question_id' => null,
                    'file' => $filename,
                    'alt_text' => null,
                ]);
                $imagePath = $filename;
            }
        }

        return ['text' => $text, 'image' => $imagePath];
    }
}
